<?php

namespace App\Services\WooCommerce;

use App\Http\Controllers\OrderController;
use App\Models\City;
use App\Models\Order;
use App\Models\Warehouse;
use App\Models\WooCommerce\OrderLink;
use App\Models\WooCommerce\Store;
use App\Models\WooCommerce\SyncLog;
use App\Support\AccountContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The WooCommerce orders of one store: list them with their match state, show one next to its CodConnect
 * order, and import some. Every lookup goes through the store's own link tables.
 */
class OrderService
{
    /** City used when the billing "city" of a WooCommerce order is not a CodConnect city id. */
    private const FALLBACK_CITY_ID = 4;

    private WooCommerceClient $client;

    private VariationLinkResolver $resolver;

    public function __construct(private readonly Store $store)
    {
        $this->client = WooCommerceClient::fromStore($store);
        $this->resolver = new VariationLinkResolver($store);
    }

    /** @throws WooCommerceException */
    public function list(string $status, int $perPage, int $page, ?string $search = null): array
    {
        $result = $this->client->orders(array_filter(['status' => $status, 'per_page' => $perPage, 'page' => $page, 'search' => $search]));

        $imported = OrderLink::where('store_id', $this->store->id)
            ->whereIn('wc_order_id', collect($result['data'])->pluck('id'))
            ->pluck('order_id', 'wc_order_id');
        $codes = Order::whereIn('id', $imported->values())->pluck('code', 'id');

        $orders = collect($result['data'])->map(function ($wcOrder) use ($imported, $codes) {
            $lineItems = collect($wcOrder['line_items'])->map(function ($item) {
                $pva = $this->resolver->resolve((int) $item['product_id'], (int) $item['variation_id']);
                $systemProduct = $pva?->product ? [
                    'id' => $pva->product->id,
                    'title' => $pva->product->title,
                    'reference' => $pva->product->reference,
                    'pva_id' => $pva->id,
                    'attributes' => $pva->variationAttribute?->childVariationAttributes
                        ->map(fn ($childVa) => ['id' => $childVa->attribute_id, 'title' => $childVa->attribute->title ?? null]) ?? [],
                ] : null;

                $imageUrl = $this->resolveItemImage($item, $pva);

                return [
                    'wc_product_id' => $item['product_id'],
                    'wc_variation_id' => $item['variation_id'],
                    'name' => $item['name'],
                    'sku' => $item['sku'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'image_url' => $imageUrl,
                    'wc_product_url' => $this->wcProductUrl((int) ($item['product_id'] ?? 0)),
                    'system_product' => $systemProduct,
                    'system_product_id' => $systemProduct['id'] ?? null,
                    'is_matched' => $systemProduct !== null,
                ];
            });

            $orderId = $imported[$wcOrder['id']] ?? null;

            return [
                'wc_order_id' => $wcOrder['id'],
                'wc_status' => $wcOrder['status'],
                'date_created' => $wcOrder['date_created'],
                'customer' => [
                    'name' => trim(($wcOrder['billing']['first_name'] ?? '') . ' ' . ($wcOrder['billing']['last_name'] ?? '')),
                    'phone' => $wcOrder['billing']['phone'] ?? null,
                    'email' => $wcOrder['billing']['email'] ?? null,
                    'address' => $wcOrder['billing']['address_1'] ?? null,
                    'city' => $wcOrder['billing']['city'] ?? null,
                ],
                'total' => $wcOrder['total'],
                'currency' => $wcOrder['currency'],
                'already_imported' => $orderId !== null,
                'system_order_id' => $orderId,
                'system_order_code' => $orderId ? ($codes[$orderId] ?? null) : null,
                'unmatched_items' => $lineItems->where('is_matched', false)->count(),
                'line_items' => $lineItems,
            ];
        });

        return ['data' => $orders, 'total' => $result['total'] ?: count($result['data'])];
    }

    /**
     * Imports WooCommerce orders as CodConnect orders. Everything local is one transaction; the WooCommerce
     * orders are marked only once it has committed, and a failure there is logged, never fatal.
     *
     * @param array<int, array<string, mixed>> $inputs one entry per order: wc_order_id + optional warehouse/brand source/payment ids
     * @return array<int, array<string, mixed>> one result per order
     */
    public function import(array $inputs, ?string $wcUpdateStatus = null): array
    {
        $wcUpdateStatus ??= $this->store->wc_status_after_import ?: 'completed';
        $accountId = (int) $this->store->account_id;

        return AccountContext::run($accountId, function () use ($inputs, $wcUpdateStatus, $accountId) {
            $results = [];
            $toUpdate = [];

            DB::beginTransaction();
            try {
                foreach ($inputs as $input) {
                    $wcOrderId = (int) $input['wc_order_id'];
                    $result = $this->importOne($input, $wcOrderId, $accountId);
                    $results[] = $result;

                    if ($result['success']) {
                        $toUpdate[$wcOrderId] = $result['system_order_id'] ?? null;
                    }
                }

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error('WooCommerce import failed: ' . $e->getMessage(), ['store_id' => $this->store->id, 'trace' => $e->getTraceAsString()]);
                SyncLog::record($this->store, 'import', 'order', 'failed', 'Import aborted: ' . $e->getMessage());

                throw $e;
            }

            foreach ($results as $result) {
                SyncLog::record(
                    $this->store,
                    'import',
                    'order',
                    $result['success'] ? 'success' : (($result['skipped'] ?? false) ? 'skipped' : 'failed'),
                    $result['message'],
                    ['wc_id' => $result['wc_order_id'], 'entity_id' => $result['system_order_id'] ?? null]
                );
            }

            foreach ($toUpdate as $wcOrderId => $orderId) {
                $this->markImported($wcOrderId, $wcUpdateStatus);
            }

            return $results;
        });
    }

    /**
     * One WooCommerce order next to its CodConnect order (when imported). An order that is not imported yet
     * is shown as a read-only preview built from the WooCommerce data.
     *
     * @throws WooCommerceException when WooCommerce cannot give the order and it was never imported
     */
    public function show(int $wcOrderId): array
    {
        $link = OrderLink::where('store_id', $this->store->id)->where('wc_order_id', $wcOrderId)->first();

        $wc = null;
        try {
            $raw = $this->client->order($wcOrderId);
            $wc = [
                'wc_order_id' => $raw['id'],
                'wc_status' => $raw['status'],
                'wc_total' => $raw['total'],
                'wc_currency' => $raw['currency'],
                'date_created' => $raw['date_created'],
                'date_modified' => $raw['date_modified'] ?? null,
                'billing' => $raw['billing'] ?? null,
                'shipping' => $raw['shipping'] ?? null,
                'line_items' => $raw['line_items'] ?? [],
                'payment_method' => $raw['payment_method_title'] ?? null,
                'customer_note' => $raw['customer_note'] ?? null,
                'shipping_total' => $raw['shipping_total'] ?? 0,
                'meta_data' => $raw['meta_data'] ?? [],
            ];
        } catch (WooCommerceException $e) {
            if (! $link) {
                throw $e;
            }
        }

        $order = $link ? Order::with([
            'customer.phones.phoneTypes', 'customer.addresses.city', 'orderStatus', 'brandSource.brand', 'brandSource.source',
            'warehouse', 'paymentType', 'paymentMethod', 'city', 'pickup', 'shipment',
            'activeOrderPvas.productVariationAttribute.product',
            'activeOrderPvas.productVariationAttribute.variationAttribute.childVariationAttributes.attribute',
            'activeOrderPvas.orderStatus',
        ])->find($link->order_id) : null;

        return $order ? $this->importedView($order, $wc, $link) : $this->previewView($wc);
    }

    private function previewView(array $wc): array
    {
        $billing = $wc['billing'] ?? [];
        $name = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
        $city = $this->resolveCity($wc);

        $products = collect($wc['line_items'])->map(function ($item) {
            $pva = $this->resolver->resolve((int) $item['product_id'], (int) $item['variation_id']);

            return [
                'order_pva_id' => $item['id'],
                'product_id' => $item['product_id'],
                'wc_product_id' => $item['product_id'],
                'wc_variation_id' => (int) ($item['variation_id'] ?: $item['product_id']),
                'wc_product_url' => $this->wcProductUrl((int) ($item['product_id'] ?? 0)),
                'is_matched' => $pva !== null,
                'system_pva_id' => $pva?->id,
                'system_product_id' => $pva?->product?->id,
                'product_title' => $item['name'],
                'product_reference' => $item['sku'],
                'image_url' => $this->resolveItemImage($item, $pva),
                'price' => $item['price'],
                'quantity' => $item['quantity'],
                'discount' => 0,
                'order_status' => ['id' => null, 'title' => 'N/A'],
                'attributes' => collect($item['meta_data'] ?? [])->map(fn ($meta) => [
                    'id' => $meta['id'] ?? uniqid(),
                    'title' => ($meta['display_key'] ?? '') . ': ' . strip_tags($meta['display_value'] ?? ''),
                ])->toArray(),
            ];
        });

        return [
            'id' => null,
            'code' => 'WC-' . $wc['wc_order_id'],
            'shipping_code' => '',
            'note' => $wc['customer_note'] ?? '',
            'discount' => 0,
            'carrier_price' => $wc['shipping_total'] ?? 0,
            'created_at' => $wc['date_created'],
            'updated_at' => $wc['date_modified'] ?? $wc['date_created'],
            'order_status' => ['id' => null, 'title' => $wc['wc_status']],
            'warehouse' => ['id' => null, 'title' => 'Not Imported'],
            'payment_type' => ['id' => null, 'title' => 'N/A'],
            'payment_method' => ['id' => null, 'title' => $wc['payment_method'] ?: 'N/A'],
            'brand' => null,
            'source' => null,
            'city' => ['id' => $city?->id, 'title' => $city?->title ?? ($billing['city'] ?? '')],
            'pickup' => null,
            'shipment' => null,
            'customer' => [
                'id' => null,
                'name' => $name ?: 'Unknown',
                'phones' => [['id' => null, 'title' => $billing['phone'] ?? '', 'types' => []]],
                'addresses' => [['id' => null, 'title' => $billing['address_1'] ?? '', 'city' => ['id' => $city?->id, 'title' => $city?->title ?? ($billing['city'] ?? '')]]],
            ],
            'products' => $products,
            'woocommerce' => $wc,
            'link' => null,
        ];
    }

    private function importedView(Order $order, ?array $wc, OrderLink $link): array
    {
        $products = $order->activeOrderPvas->map(function ($orderPva) {
            $pva = $orderPva->productVariationAttribute;
            $product = $pva->product;

            $img = $pva->principalImage?->first() ?? $product->principalImage?->first();
            $imageUrl = null;
            if ($img) {
                $imageUrl = ! empty($img->photo_dir) && ! empty($img->photo)
                    ? asset(rtrim($img->photo_dir, '/') . '/' . ltrim($img->photo, '/'))
                    : asset('storage/' . ltrim($img->photo, '/'));
            }

            $link = VariationLink::where('store_id', $this->store->id)->where('product_variation_attribute_id', $pva->id)->first();
            $wcProductId = $link?->wc_product_id;

            return [
                'order_pva_id' => $orderPva->id,
                'product_id' => $product->id,
                'system_product_id' => $product->id,
                'wc_product_id' => $wcProductId,
                'wc_product_url' => $this->wcProductUrl($wcProductId),
                'product_title' => $product->title,
                'product_reference' => $product->reference,
                'image_url' => $imageUrl,
                'price' => $orderPva->price,
                'quantity' => $orderPva->quantity,
                'discount' => $orderPva->discount,
                'order_status' => $orderPva->orderStatus->only('id', 'title'),
                'attributes' => $pva->variationAttribute?->childVariationAttributes
                    ->map(fn ($childVa) => ['id' => $childVa->attribute_id, 'title' => $childVa->attribute->title ?? null]) ?? [],
            ];
        });

        return [
            'id' => $order->id,
            'code' => $order->code,
            'shipping_code' => $order->shipping_code,
            'note' => $order->note,
            'discount' => $order->discount,
            'carrier_price' => $order->carrier_price,
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,
            'order_status' => $order->orderStatus?->only('id', 'title'),
            'warehouse' => $order->warehouse?->only('id', 'title'),
            'payment_type' => $order->paymentType?->only('id', 'title'),
            'payment_method' => $order->paymentMethod?->only('id', 'title'),
            'brand' => $order->brandSource?->brand?->only('id', 'title'),
            'source' => $order->brandSource?->source?->only('id', 'title'),
            'city' => $order->city?->only('id', 'title'),
            'pickup' => $order->pickup?->only('id', 'title'),
            'shipment' => $order->shipment?->only('id', 'title'),
            'customer' => $order->customer ? [
                'id' => $order->customer->id,
                'name' => $order->customer->name,
                'phones' => $order->customer->phones->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'types' => $p->phoneTypes->pluck('title')]),
                'addresses' => $order->customer->addresses->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'city' => $a->city?->only('id', 'title')]),
            ] : null,
            'products' => $products,
            'woocommerce' => $wc,
            'link' => [
                'last_wc_status' => $link->last_wc_status,
                'last_pushed_status' => $link->last_pushed_status,
                'last_pushed_at' => $link->last_pushed_at?->toIso8601String(),
            ],
        ];
    }

    private function importOne(array $input, int $wcOrderId, int $accountId): array
    {
        try {
            $wcOrder = $this->client->order($wcOrderId);
        } catch (WooCommerceException $e) {
            return ['wc_order_id' => $wcOrderId, 'success' => false, 'message' => 'Failed to fetch the WooCommerce order: ' . $e->getMessage()];
        }

        $existing = OrderLink::where('store_id', $this->store->id)->where('wc_order_id', $wcOrderId)->first();
        if ($existing) {
            return ['wc_order_id' => $wcOrderId, 'success' => false, 'skipped' => true, 'message' => 'Order already imported.', 'system_order_id' => $existing->order_id];
        }

        $billing = $wcOrder['billing'] ?? [];
        $city = $this->resolveCity($wcOrder, $input);
        $warnings = $city ? [] : ['The billing city is not a CodConnect city: city ' . self::FALLBACK_CITY_ID . ' was used.'];

        $products = [];
        foreach ($wcOrder['line_items'] as $item) {
            $pva = $this->resolver->resolve((int) $item['product_id'], (int) $item['variation_id']);
            if ($pva?->product) {
                $products[] = [
                    'id' => $pva->product->id,
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'attributes' => $pva->variationAttribute?->childVariationAttributes->pluck('attribute_id')->toArray() ?? [],
                ];
            }
        }

        if (! $products) {
            return ['wc_order_id' => $wcOrderId, 'success' => false, 'message' => 'No matched products found for this WooCommerce order.'];
        }
        if (count($products) < count($wcOrder['line_items'])) {
            $warnings[] = (count($wcOrder['line_items']) - count($products)) . ' item(s) are not matched and were left out.';
        }

        $warehouseId = ! empty($input['warehouse_id']) ? Warehouse::where('id', $input['warehouse_id'])->where('account_id', $accountId)->value('id') : null;
        $warehouseId ??= $this->store->default_warehouse_id ?: Warehouse::where('account_id', $accountId)->value('id');

        $payload = [
            'account_id' => $accountId,
            'warehouse_id' => $warehouseId,
            'brand_source_id' => $input['brand_source_id'] ?? $this->store->default_brand_source_id,
            'payment_type_id' => $input['payment_type_id'] ?? 1,
            'payment_method_id' => $input['payment_method_id'] ?? 1,
            'order_status_id' => 1,
            // the legacy screens still look the WooCommerce id up here
            'meta' => $wcOrderId,
            'carrier_price' => $wcOrder['shipping_total'] ?? 0,
            'note' => $wcOrder['customer_note'] ?? null,
            'customer' => [
                'account_id' => $accountId,
                'name' => trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')) ?: 'Client WebSite',
                'customer_type_id' => 1,
                'phones' => [['title' => $billing['phone'] ?? '', 'principal' => true, 'phoneTypes' => [1]]],
                'addresses' => [['title' => $billing['address_1'] ?? '', 'principal' => true, 'city_id' => $city?->id ?? self::FALLBACK_CITY_ID]],
            ],
            'products' => $products,
        ];

        $response = OrderController::store(new Request([$payload]));
        $body = method_exists($response, 'getData') ? $response->getData(true) : [];

        if (($body['statut'] ?? 0) != 1) {
            return ['wc_order_id' => $wcOrderId, 'success' => false, 'message' => 'Failed to create the order: ' . $this->firstError($body['data'] ?? null), 'errors' => $body['data'] ?? null];
        }

        $orderId = (int) (collect($body['data'] ?? [])->first() ?? 0);
        OrderLink::create([
            'store_id' => $this->store->id,
            'account_id' => $accountId,
            'wc_order_id' => $wcOrderId,
            'order_id' => $orderId,
            'imported_at' => now(),
            'last_wc_status' => $wcOrder['status'] ?? null,
        ]);

        return [
            'wc_order_id' => $wcOrderId,
            'success' => true,
            'message' => 'Order imported.',
            'system_order_id' => $orderId,
            'warnings' => $warnings,
        ];
    }

    private function markImported(int $wcOrderId, string $status): void
    {
        try {
            $this->client->updateOrderStatus($wcOrderId, $status);
            OrderLink::where('store_id', $this->store->id)->where('wc_order_id', $wcOrderId)
                ->update(['last_wc_status' => $status, 'last_pushed_status' => $status, 'last_pushed_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning("WooCommerce order {$wcOrderId} was imported but its status could not be updated: " . $e->getMessage());
            SyncLog::record($this->store, 'push', 'order', 'failed', "Imported, but WooCommerce was not updated to {$status}: " . $e->getMessage(), ['wc_id' => $wcOrderId]);
        }
    }

    private function firstError(mixed $errors): string
    {
        if (is_string($errors)) {
            return $errors;
        }
        $first = collect((array) $errors)->flatten()->first();

        return is_string($first) ? $first : 'validation error';
    }

    private function resolveItemImage(array $item, $pva = null): ?string
    {
        if (! empty($item['image']['src'])) {
            return (string) $item['image']['src'];
        }

        if ($pva) {
            $img = $pva->principalImage?->first() ?? $pva->product?->principalImage?->first();
            if ($img) {
                if (! empty($img->photo_dir) && ! empty($img->photo)) {
                    return asset(rtrim($img->photo_dir, '/') . '/' . ltrim($img->photo, '/'));
                }
                if (! empty($img->photo)) {
                    return asset('storage/' . ltrim($img->photo, '/'));
                }
            }
        }

        return null;
    }

    private function wcProductUrl(?int $wcProductId): ?string
    {
        if (! $wcProductId) {
            return null;
        }

        $siteUrl = preg_replace('#/wp-json(/wc/v\d+)?/?$#i', '', (string) $this->store->base_url);

        return $siteUrl ? rtrim($siteUrl, '/') . '/?p=' . $wcProductId : null;
    }

    public function resolveCity(array $wcOrder, array $input = []): ?City
    {
        // 1. Explicitly provided city_id in import payload
        if (! empty($input['city_id']) && is_numeric($input['city_id'])) {
            $city = City::find((int) $input['city_id']);
            if ($city) {
                return $city;
            }
        }

        // 2. City ID stored in WooCommerce order meta_data (used by Moroccan checkout plugins)
        $meta = collect($wcOrder['meta_data'] ?? [])->pluck('value', 'key');
        $metaCityId = $meta['city_id'] ?? $meta['_billing_city_id'] ?? $meta['_shipping_city_id'] ?? null;
        if ($metaCityId && is_numeric($metaCityId)) {
            $city = City::find((int) $metaCityId);
            if ($city) {
                return $city;
            }
        }

        // 3. Numeric ID directly in billing or shipping city
        $billing = $wcOrder['billing'] ?? [];
        if (! empty($billing['city']) && is_numeric($billing['city'])) {
            $city = City::find((int) $billing['city']);
            if ($city) {
                return $city;
            }
        }

        $shipping = $wcOrder['shipping'] ?? [];
        if (! empty($shipping['city']) && is_numeric($shipping['city'])) {
            $city = City::find((int) $shipping['city']);
            if ($city) {
                return $city;
            }
        }

        // 4. Match by city name/title
        $candidates = array_filter([
            $meta['city_title'] ?? null,
            $meta['_billing_city_title'] ?? null,
            $meta['_shipping_city_title'] ?? null,
            $billing['city'] ?? null,
            $shipping['city'] ?? null,
            $billing['state'] ?? null,
            $shipping['state'] ?? null,
        ]);

        foreach ($candidates as $candidate) {
            $matched = $this->matchCityByText((string) $candidate);
            if ($matched) {
                return $matched;
            }
        }

        return null;
    }

    private function matchCityByText(string $raw): ?City
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Direct exact match
        $city = City::where('title', $raw)->first();
        if ($city) {
            return $city;
        }

        // Split composite strings e.g. "Taroudant - تارودانت" or "Assays ( agadir )"
        $parts = preg_split('/[\-\/\(\)]+/', $raw);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || mb_strlen($part) < 2) {
                continue;
            }

            $city = City::where('title', $part)->first();
            if ($city) {
                return $city;
            }

            $city = City::where('title', 'like', $part)->first();
            if ($city) {
                return $city;
            }
        }

        // Accent-insensitive / regex match for legacy corrupted titles (e.g. K??nitra, F??s)
        $cleanInput = preg_replace('/[^a-z0-9]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $raw)));
        if (strlen($cleanInput) >= 3) {
            $cities = City::all(['id', 'title']);
            foreach ($cities as $c) {
                $cleanTitle = preg_replace('/[^a-z0-9\?]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $c->title)));
                $regex = '/^' . preg_replace('/\?+/', '[a-z]*', $cleanTitle) . '$/i';
                if (@preg_match($regex, $cleanInput)) {
                    return $c;
                }
            }
        }

        return null;
    }
}
