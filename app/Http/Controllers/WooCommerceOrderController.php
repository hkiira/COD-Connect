<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\City;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariationAttribute;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WooCommerceOrderController extends Controller
{
    private string $baseUrl;
    private string $consumerKey;
    private string $consumerSecret;

    public function __construct()
    {
        $this->baseUrl = config('services.woocommerce.base_url', 'https://stylemen.net/wp-json/wc/v3/');
        $this->consumerKey = config('services.woocommerce.consumer_key', 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428');
        $this->consumerSecret = config('services.woocommerce.consumer_secret', 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d');
    }

    // -------------------------------------------------------------------------
    // Function 1 — GET WooCommerce orders by status with matched system products
    // GET /api/wc-orders?status=processing&per_page=10&page=1
    // -------------------------------------------------------------------------

    public function getOrdersByStatus(Request $request)
    {
        $request->validate([
            'status' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $status = $request->get('status', 'processing');
        $perPage = (int) $request->get('per_page', 10);
        $page = (int) $request->get('page', 1);

        $wcResponse = Http::withoutVerifying()->get($this->baseUrl . 'orders', [
            'consumer_key' => $this->consumerKey,
            'consumer_secret' => $this->consumerSecret,
            'status' => $status,
            'per_page' => $perPage,
            'page' => $page,
        ]);

        if ($wcResponse->failed()) {
            return response()->json([
                'statut' => 0,
                'message' => 'Failed to fetch orders from WooCommerce.',
                'error' => $wcResponse->json(),
            ], 502);
        }

        $accountId = getAccountUser()->account_id;
        $wcOrders = $wcResponse->json();

        $orders = collect($wcOrders)->map(function ($wcOrder) use ($accountId) {
            // Check if this WooCommerce order has already been imported
            $alreadyImported = Order::where('account_id', $accountId)
                ->where(function ($q) use ($wcOrder) {
                    $q->where('meta', (string) $wcOrder['id'])
                        ->orWhere('meta', 'LIKE', '%"id":' . $wcOrder['id'] . '%')
                        ->orWhere('meta', 'LIKE', '%"id": ' . $wcOrder['id'] . '%');
                })
                ->exists();

            $lineItems = collect($wcOrder['line_items'])->map(function ($item) {
                $pva = ProductVariationAttribute::where(function ($q) use ($item) {
                    $q->where('meta', (string) $item['variation_id'])
                        ->orWhere('meta', 'LIKE', '%"id":' . (int) $item['variation_id'] . '%')
                        ->orWhere('meta', 'LIKE', '%"id": ' . (int) $item['variation_id'] . '%');
                })->first();

                $systemProduct = null;
                if ($pva && $pva->product) {
                    $product = $pva->product;
                    $systemProduct = [
                        'id' => $product->id,
                        'title' => $product->title,
                        'reference' => $product->reference,
                        'pva_id' => $pva->id,
                        'attributes' => $pva->variationAttribute
                            ?->childVariationAttributes
                            ->map(fn($childVa) => [
                                'id' => $childVa->attribute_id,
                                'title' => $childVa->attribute->title ?? null,
                            ]) ?? [],
                    ];
                }

                return [
                    'wc_product_id' => $item['product_id'],
                    'wc_variation_id' => $item['variation_id'],
                    'name' => $item['name'],
                    'sku' => $item['sku'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'system_product' => $systemProduct,
                    'is_matched' => $systemProduct !== null,
                ];
            });

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
                'already_imported' => $alreadyImported,
                'line_items' => $lineItems,
            ];
        });

        return response()->json([
            'statut' => 1,
            'data' => $orders,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'status' => $status,
                'count' => (int) $wcResponse->header('X-WP-Total') ?: count($wcOrders),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Function 2 — Import a list of WooCommerce orders into the system
    //              and update their status in WooCommerce
    // POST /api/wc-orders/import
    // Body: {
    //   "orders": [
    //     { "wc_order_id": 123, "warehouse_id": 30, "brand_source_id": 108,
    //       "payment_type_id": 1, "payment_method_id": 1, "order_status_id": 1 }
    //   ],
    //   "wc_update_status": "completed"
    // }
    // -------------------------------------------------------------------------

    public function importOrders(Request $request)
    {
        $request->validate([
            'orders' => 'required|array|min:1',
            'orders.*.wc_order_id' => 'required|integer',
            'orders.*.warehouse_id' => 'nullable|integer',
            'orders.*.brand_source_id' => 'nullable|integer',
            'orders.*.payment_type_id' => 'nullable|integer',
            'orders.*.payment_method_id' => 'nullable|integer',
            'orders.*.order_status_id' => 'nullable|integer',
            'wc_update_status' => 'nullable|string',
        ]);

        $wcUpdateStatus = $request->get('wc_update_status', 'completed');
        $accountId = getAccountUser()->account_id;
        $results = [];

        DB::beginTransaction();
        try {
            foreach ($request->get('orders') as $orderInput) {
                $wcOrderId = (int) $orderInput['wc_order_id'];

                // Fetch full WooCommerce order details
                $wcResponse = Http::withoutVerifying()->get($this->baseUrl . 'orders/' . $wcOrderId, [
                    'consumer_key' => $this->consumerKey,
                    'consumer_secret' => $this->consumerSecret,
                ]);

                if ($wcResponse->failed()) {
                    $results[] = [
                        'wc_order_id' => $wcOrderId,
                        'success' => false,
                        'message' => 'Failed to fetch WooCommerce order.',
                    ];
                    continue;
                }

                $wcOrder = $wcResponse->json();

                // Skip orders already imported
                $existingOrder = Order::where('account_id', $accountId)
                    ->where(function ($q) use ($wcOrderId) {
                        $q->where('meta', (string) $wcOrderId)
                            ->orWhere('meta', 'LIKE', '%"id":' . $wcOrderId . '%')
                            ->orWhere('meta', 'LIKE', '%"id": ' . $wcOrderId . '%');
                    })
                    ->first();

                if ($existingOrder) {
                    $results[] = [
                        'wc_order_id' => $wcOrderId,
                        'success' => false,
                        'message' => 'Order already imported.',
                        'system_order_id' => $existingOrder->id,
                    ];
                    continue;
                }

                // Resolve city from WooCommerce billing city field (stored as city ID in our system)
                $city = City::where('id', $wcOrder['billing']['city'] ?? null)->first();
                $cityId = $city ? $city->id : 4;

                // Match line items to system products via PVA meta
                $products = [];
                foreach ($wcOrder['line_items'] as $item) {
                    $pva = ProductVariationAttribute::where(function ($q) use ($item) {
                        $q->where('meta', (string) $item['variation_id'])
                            ->orWhere('meta', 'LIKE', '%"id":' . (int) $item['variation_id'] . '%')
                            ->orWhere('meta', 'LIKE', '%"id": ' . (int) $item['variation_id'] . '%');
                    })->first();

                    if ($pva && $pva->product) {
                        $products[] = [
                            'id' => $pva->product->id,
                            'quantity' => $item['quantity'],
                            'price' => $item['price'],
                            'attributes' => $pva->variationAttribute
                                ?->childVariationAttributes
                                ->pluck('attribute_id')
                                ->toArray() ?? [],
                        ];
                    }
                }

                if (empty($products)) {
                    $results[] = [
                        'wc_order_id' => $wcOrderId,
                        'success' => false,
                        'message' => 'No matched products found for this WooCommerce order.',
                    ];
                    continue;
                }

                $customerName = trim(
                    ($wcOrder['billing']['first_name'] ?? '') . ' ' . ($wcOrder['billing']['last_name'] ?? '')
                );

                // Ensure warehouse belongs to current account; fallback to first account warehouse.
                $requestedWarehouseId = $orderInput['warehouse_id'] ?? null;
                $warehouseId = null;

                if (!empty($requestedWarehouseId)) {
                    $warehouseId = Warehouse::where('id', $requestedWarehouseId)
                        ->where('account_id', $accountId)
                        ->value('id');
                }

                if (!$warehouseId) {
                    $warehouseId = Warehouse::where('account_id', $accountId)->value('id');
                }

                $orderPayload = [
                    'warehouse_id' => $warehouseId,
                    'brand_source_id' => $orderInput['brand_source_id'] ?? null,
                    'payment_type_id' => $orderInput['payment_type_id'] ?? 1,
                    'payment_method_id' => $orderInput['payment_method_id'] ?? 1,
                    'order_status_id' => 1,
                    'meta' => $wcOrderId,
                    'carrier_price' => $wcOrder['shipping_total'] ?? 0,
                    'note' => $wcOrder['customer_note'] ?? null,
                    'customer' => [
                        'name' => $customerName ?: 'Client WebSite',
                        'customer_type_id' => 1,
                        'phones' => [
                            [
                                'title' => $wcOrder['billing']['phone'] ?? '',
                                'principal' => true,
                                'phoneTypes' => [1],
                            ],
                        ],
                        'addresses' => [
                            [
                                'title' => $wcOrder['billing']['address_1'] ?? '',
                                'principal' => true,
                                'city_id' => $cityId,
                            ],
                        ],
                    ],
                    'products' => $products,
                ];

                $storeResponse = OrderController::store(new Request([$orderPayload]));
                $storePayload = method_exists($storeResponse, 'getData')
                    ? $storeResponse->getData(true)
                    : [];

                if (($storePayload['statut'] ?? 0) == 1) {
                    // Update WooCommerce order status after successful creation
                    Http::withoutVerifying()->withQueryParameters([
                        'consumer_key' => $this->consumerKey,
                        'consumer_secret' => $this->consumerSecret,
                    ])->put($this->baseUrl . 'orders/' . $wcOrderId, ['status' => $wcUpdateStatus]);

                    $results[] = [
                        'wc_order_id' => $wcOrderId,
                        'success' => true,
                        'message' => 'Order imported and WooCommerce status updated to ' . $wcUpdateStatus . '.',
                        'system_order' => $storePayload['data'] ?? null,
                    ];
                } else {
                    $results[] = [
                        'wc_order_id' => $wcOrderId,
                        'success' => false,
                        'message' => 'Failed to create order in system.',
                        'errors' => $storePayload['data'] ?? null,
                    ];
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('WooCommerceOrderController@importOrders: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'statut' => 0,
                'message' => 'An error occurred during import.',
            ], 500);
        }

        $successCount = collect($results)->where('success', true)->count();

        return response()->json([
            'statut' => $successCount > 0 ? 1 : 0,
            'message' => "{$successCount} of " . count($results) . ' orders imported successfully.',
            'results' => $results,
        ]);
    }

    // -------------------------------------------------------------------------
    // Function 3 — Show full order details (system + WooCommerce cross-reference)
    // GET /api/wc-orders/{id}
    // -------------------------------------------------------------------------

    public function showOrder($id)
    {
        $accountId = getAccountUser()->account_id;

        // 1. Fetch WooCommerce order
        $wcResponse = Http::withoutVerifying()->get($this->baseUrl . 'orders/' . $id, [
            'consumer_key' => $this->consumerKey,
            'consumer_secret' => $this->consumerSecret,
        ]);

        $wcOrderData = null;
        if ($wcResponse->successful()) {
            $raw = $wcResponse->json();
            $wcOrderData = [
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
            ];
        }

        // 2. Find if this WooCommerce order has been imported as a System Order
        $order = Order::with([
            'customer.phones.phoneTypes',
            'customer.addresses.city',
            'orderStatus',
            'brandSource.brand',
            'brandSource.source',
            'warehouse',
            'paymentType',
            'paymentMethod',
            'city',
            'pickup',
            'shipment',
            'activeOrderPvas.productVariationAttribute.product',
            'activeOrderPvas.productVariationAttribute.variationAttribute.childVariationAttributes.attribute',
            'activeOrderPvas.orderStatus',
        ])
            ->where('account_id', $accountId)
            ->where(function ($q) use ($id) {
                $q->where('meta', (string) $id)
                    ->orWhere('meta', 'LIKE', '%"id":' . $id . '%')
                    ->orWhere('meta', 'LIKE', '%"id": ' . $id . '%');
            })
            ->first();

        if (!$order && !$wcOrderData) {
            return response()->json(['statut' => 0, 'message' => 'Order not found.'], 404);
        }

        if (!$order) {
            // Synthetic response for unimported WooCommerce order
            $customerName = trim(($wcOrderData['billing']['first_name'] ?? '') . ' ' . ($wcOrderData['billing']['last_name'] ?? ''));
            $products = collect($wcOrderData['line_items'])->map(function ($item) {
                // Check if already synced
                $variationId = (int) ($item['variation_id'] ?: $item['product_id']);
                $pva = ProductVariationAttribute::where(function ($q) use ($variationId) {
                    $q->where('meta', (string) $variationId)
                        ->orWhere('meta', 'LIKE', '%"id":' . $variationId . '%')
                        ->orWhere('meta', 'LIKE', '%"id": ' . $variationId . '%');
                })->first();

                return [
                    'order_pva_id' => $item['id'],
                    'product_id' => $item['product_id'],
                    'wc_product_id' => $item['product_id'],
                    'wc_variation_id' => $variationId,
                    'is_matched' => $pva !== null,
                    'system_pva_id' => $pva ? $pva->id : null,
                    'product_title' => $item['name'],
                    'product_reference' => $item['sku'],
                    'price' => $item['price'],
                    'quantity' => $item['quantity'],
                    'discount' => 0,
                    'order_status' => ['id' => null, 'title' => 'N/A'],
                    'attributes' => collect($item['meta_data'] ?? [])->map(fn($meta) => [
                        'id' => $meta['id'] ?? uniqid(),
                        'title' => ($meta['display_key'] ?? '') . ': ' . strip_tags($meta['display_value'] ?? ''),
                    ])->toArray(),
                ];
            });

            return response()->json([
                'statut' => 1,
                'data' => [
                    'id' => null,
                    'code' => 'WC-' . $wcOrderData['wc_order_id'],
                    'shipping_code' => '',
                    'note' => $wcOrderData['customer_note'] ?? '',
                    'discount' => 0,
                    'carrier_price' => $wcOrderData['shipping_total'] ?? 0,
                    'created_at' => $wcOrderData['date_created'],
                    'updated_at' => $wcOrderData['date_modified'] ?? $wcOrderData['date_created'],
                    'order_status' => ['id' => null, 'title' => $wcOrderData['wc_status']],
                    'warehouse' => ['id' => null, 'title' => 'Not Imported'],
                    'payment_type' => ['id' => null, 'title' => 'N/A'],
                    'payment_method' => ['id' => null, 'title' => $wcOrderData['payment_method'] ?: 'N/A'],
                    'brand' => null,
                    'source' => null,
                    'city' => ['id' => null, 'title' => $wcOrderData['billing']['city'] ?? ''],
                    'pickup' => null,
                    'shipment' => null,
                    'customer' => [
                        'id' => null,
                        'name' => $customerName ?: 'Unknown',
                        'phones' => [[
                            'id' => null,
                            'title' => $wcOrderData['billing']['phone'] ?? '',
                            'types' => []
                        ]],
                        'addresses' => [[
                            'id' => null,
                            'title' => $wcOrderData['billing']['address_1'] ?? '',
                            'city' => ['id' => null, 'title' => $wcOrderData['billing']['city'] ?? '']
                        ]],
                    ],
                    'products' => $products,
                    'woocommerce' => $wcOrderData,
                ],
            ]);
        }

        // If system order exists, return it with WooCommerce data attached
        $products = $order->activeOrderPvas->map(function ($orderPva) {
            $pva = $orderPva->productVariationAttribute;
            $product = $pva->product;

            return [
                'order_pva_id' => $orderPva->id,
                'product_id' => $product->id,
                'product_title' => $product->title,
                'product_reference' => $product->reference,
                'price' => $orderPva->price,
                'quantity' => $orderPva->quantity,
                'discount' => $orderPva->discount,
                'order_status' => $orderPva->orderStatus->only('id', 'title'),
                'attributes' => $pva->variationAttribute
                    ?->childVariationAttributes
                    ->map(fn($childVa) => [
                        'id' => $childVa->attribute_id,
                        'title' => $childVa->attribute->title ?? null,
                    ]) ?? [],
            ];
        });

        return response()->json([
            'statut' => 1,
            'data' => [
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
                    'phones' => $order->customer->phones->map(fn($p) => [
                        'id' => $p->id,
                        'title' => $p->title,
                        'types' => $p->phoneTypes->pluck('title'),
                    ]),
                    'addresses' => $order->customer->addresses->map(fn($a) => [
                        'id' => $a->id,
                        'title' => $a->title,
                        'city' => $a->city?->only('id', 'title'),
                    ]),
                ] : null,
                'products' => $products,
                'woocommerce' => $wcOrderData,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Function 4 — Sync a WooCommerce variation to a system product variation
    // POST /api/wc-orders/sync-product
    // -------------------------------------------------------------------------

    public function syncProduct(Request $request)
    {
        $request->validate([
            'wc_variation_id' => 'required|integer',
            'system_pva_id' => 'required|integer',
        ]);

        $pva = ProductVariationAttribute::find($request->system_pva_id);
        
        if (!$pva) {
            return response()->json(['statut' => 0, 'message' => 'System product variation not found.'], 404);
        }

        // Store the WooCommerce variation ID in the meta field (it's cast to array in the model)
        $metaArray = is_array($pva->meta) ? $pva->meta : [];
        
        $exists = false;
        foreach ($metaArray as $item) {
            if (isset($item['id']) && $item['id'] == $request->wc_variation_id) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $metaArray[] = ['id' => (int) $request->wc_variation_id];
            $pva->meta = $metaArray;
            $pva->save();
        }

        return response()->json([
            'statut' => 1, 
            'message' => 'Product synced successfully.',
            'pva' => $pva
        ]);
    }
}

