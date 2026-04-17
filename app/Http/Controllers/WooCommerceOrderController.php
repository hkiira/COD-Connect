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
        $this->baseUrl        = config('services.woocommerce.base_url', 'https://stylemen.net/wp-json/wc/v3/');
        $this->consumerKey    = config('services.woocommerce.consumer_key', 'ck_60f4fbf0c53746e9fbb6f64866979bf9f5a36428');
        $this->consumerSecret = config('services.woocommerce.consumer_secret', 'cs_dc5958ff74d9fa6ca2f550fd722418d58104ba9d');
    }

    // -------------------------------------------------------------------------
    // Function 1 — GET WooCommerce orders by status with matched system products
    // GET /api/wc-orders?status=processing&per_page=10&page=1
    // -------------------------------------------------------------------------

    public function getOrdersByStatus(Request $request)
    {
        $request->validate([
            'status'   => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page'     => 'nullable|integer|min:1',
        ]);

        $status  = $request->get('status', 'processing');
        $perPage = (int) $request->get('per_page', 10);
        $page    = (int) $request->get('page', 1);

        $wcResponse = Http::get($this->baseUrl . 'orders', [
            'consumer_key'    => $this->consumerKey,
            'consumer_secret' => $this->consumerSecret,
            'status'          => $status,
            'per_page'        => $perPage,
            'page'            => $page,
        ]);

        if ($wcResponse->failed()) {
            return response()->json([
                'statut'  => 0,
                'message' => 'Failed to fetch orders from WooCommerce.',
                'error'   => $wcResponse->json(),
            ], 502);
        }

        $accountId    = getAccountUser()->account_id;
        $wcOrders     = $wcResponse->json();

        $orders = collect($wcOrders)->map(function ($wcOrder) use ($accountId) {
            // Check if this WooCommerce order has already been imported
            $alreadyImported = Order::where('account_id', $accountId)
                ->where(function ($q) use ($wcOrder) {
                    $q->whereRaw("JSON_CONTAINS(meta, ?)", [json_encode(['id' => $wcOrder['id']])])
                      ->orWhere('meta', (string) $wcOrder['id']);
                })
                ->exists();

            $lineItems = collect($wcOrder['line_items'])->map(function ($item) {
                $pva = ProductVariationAttribute::whereRaw(
                    "JSON_CONTAINS(meta, ?)",
                    [json_encode(['id' => (int) $item['variation_id']])]
                )->first();

                $systemProduct = null;
                if ($pva && $pva->product) {
                    $product       = $pva->product;
                    $systemProduct = [
                        'id'         => $product->id,
                        'title'      => $product->title,
                        'reference'  => $product->reference,
                        'pva_id'     => $pva->id,
                        'attributes' => $pva->variationAttribute
                            ?->childVariationAttributes
                            ->map(fn($childVa) => [
                                'id'    => $childVa->attribute_id,
                                'title' => $childVa->attribute->title ?? null,
                            ]) ?? [],
                    ];
                }

                return [
                    'wc_product_id'   => $item['product_id'],
                    'wc_variation_id' => $item['variation_id'],
                    'name'            => $item['name'],
                    'sku'             => $item['sku'],
                    'quantity'        => $item['quantity'],
                    'price'           => $item['price'],
                    'system_product'  => $systemProduct,
                    'is_matched'      => $systemProduct !== null,
                ];
            });

            return [
                'wc_order_id'      => $wcOrder['id'],
                'wc_status'        => $wcOrder['status'],
                'date_created'     => $wcOrder['date_created'],
                'customer'         => [
                    'name'    => trim(($wcOrder['billing']['first_name'] ?? '') . ' ' . ($wcOrder['billing']['last_name'] ?? '')),
                    'phone'   => $wcOrder['billing']['phone'] ?? null,
                    'email'   => $wcOrder['billing']['email'] ?? null,
                    'address' => $wcOrder['billing']['address_1'] ?? null,
                    'city'    => $wcOrder['billing']['city'] ?? null,
                ],
                'total'            => $wcOrder['total'],
                'currency'         => $wcOrder['currency'],
                'already_imported' => $alreadyImported,
                'line_items'       => $lineItems,
            ];
        });

        return response()->json([
            'statut' => 1,
            'data'   => $orders,
            'meta'   => [
                'page'     => $page,
                'per_page' => $perPage,
                'status'   => $status,
                'count'    => count($wcOrders),
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
            'orders'                     => 'required|array|min:1',
            'orders.*.wc_order_id'       => 'required|integer',
            'orders.*.warehouse_id'      => 'nullable|integer',
            'orders.*.brand_source_id'   => 'nullable|integer',
            'orders.*.payment_type_id'   => 'nullable|integer',
            'orders.*.payment_method_id' => 'nullable|integer',
            'orders.*.order_status_id'   => 'nullable|integer',
            'wc_update_status'           => 'nullable|string',
        ]);

        $wcUpdateStatus = $request->get('wc_update_status', 'completed');
        $accountId      = getAccountUser()->account_id;
        $results        = [];

        DB::beginTransaction();
        try {
            foreach ($request->get('orders') as $orderInput) {
                $wcOrderId = (int) $orderInput['wc_order_id'];

                // Fetch full WooCommerce order details
                $wcResponse = Http::get($this->baseUrl . 'orders/' . $wcOrderId, [
                    'consumer_key'    => $this->consumerKey,
                    'consumer_secret' => $this->consumerSecret,
                ]);

                if ($wcResponse->failed()) {
                    $results[] = [
                        'wc_order_id' => $wcOrderId,
                        'success'     => false,
                        'message'     => 'Failed to fetch WooCommerce order.',
                    ];
                    continue;
                }

                $wcOrder = $wcResponse->json();

                // Skip orders already imported
                $existingOrder = Order::where('account_id', $accountId)
                    ->where(function ($q) use ($wcOrderId) {
                        $q->whereRaw("JSON_CONTAINS(meta, ?)", [json_encode(['id' => $wcOrderId])])
                          ->orWhere('meta', (string) $wcOrderId);
                    })
                    ->first();

                if ($existingOrder) {
                    $results[] = [
                        'wc_order_id'     => $wcOrderId,
                        'success'         => false,
                        'message'         => 'Order already imported.',
                        'system_order_id' => $existingOrder->id,
                    ];
                    continue;
                }

                // Resolve city from WooCommerce billing city field (stored as city ID in our system)
                $city   = City::where('id', $wcOrder['billing']['city'] ?? null)->first();
                $cityId = $city ? $city->id : 4;

                // Match line items to system products via PVA meta
                $products = [];
                foreach ($wcOrder['line_items'] as $item) {
                    $pva = ProductVariationAttribute::whereRaw(
                        "JSON_CONTAINS(meta, ?)",
                        [json_encode(['id' => (int) $item['variation_id']])]
                    )->first();

                    if ($pva && $pva->product) {
                        $products[] = [
                            'id'         => $pva->product->id,
                            'quantity'   => $item['quantity'],
                            'price'      => $item['price'],
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
                        'success'     => false,
                        'message'     => 'No matched products found for this WooCommerce order.',
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
                    'warehouse_id'      => $warehouseId,
                    'brand_source_id'   => $orderInput['brand_source_id'] ?? null,
                    'payment_type_id'   => $orderInput['payment_type_id'] ?? 1,
                    'payment_method_id' => $orderInput['payment_method_id'] ?? 1,
                    'order_status_id'   => $orderInput['order_status_id'] ?? 1,
                    'meta'              => $wcOrderId,
                    'carrier_price'     => $wcOrder['shipping_total'] ?? 0,
                    'note'              => $wcOrder['customer_note'] ?? null,
                    'customer'          => [
                        'name'             => $customerName ?: 'Client WebSite',
                        'customer_type_id' => 1,
                        'phones'           => [
                            [
                                'title'      => $wcOrder['billing']['phone'] ?? '',
                                'principal'  => true,
                                'phoneTypes' => [1],
                            ],
                        ],
                        'addresses' => [
                            [
                                'title'     => $wcOrder['billing']['address_1'] ?? '',
                                'principal' => true,
                                'city_id'   => $cityId,
                            ],
                        ],
                    ],
                    'products' => $products,
                ];

                $storeResponse = OrderController::store(new Request([$orderPayload]));
                $storePayload  = method_exists($storeResponse, 'getData')
                    ? $storeResponse->getData(true)
                    : [];

                if (($storePayload['statut'] ?? 0) == 1) {
                    // Update WooCommerce order status after successful creation
                    Http::withQueryParameters([
                        'consumer_key'    => $this->consumerKey,
                        'consumer_secret' => $this->consumerSecret,
                    ])->put($this->baseUrl . 'orders/' . $wcOrderId, ['status' => $wcUpdateStatus]);

                    $results[] = [
                        'wc_order_id'  => $wcOrderId,
                        'success'      => true,
                        'message'      => 'Order imported and WooCommerce status updated to ' . $wcUpdateStatus . '.',
                        'system_order' => $storePayload['data'] ?? null,
                    ];
                } else {
                    $results[] = [
                        'wc_order_id' => $wcOrderId,
                        'success'     => false,
                        'message'     => 'Failed to create order in system.',
                        'errors'      => $storePayload['data'] ?? null,
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
                'statut'  => 0,
                'message' => 'An error occurred during import.',
            ], 500);
        }

        $successCount = collect($results)->where('success', true)->count();

        return response()->json([
            'statut'  => $successCount > 0 ? 1 : 0,
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
            ->where('account_id', getAccountUser()->account_id)
            ->find($id);

        if (!$order) {
            return response()->json(['statut' => 0, 'message' => 'Order not found.'], 404);
        }

        // Attempt to load linked WooCommerce order
        $wcOrderData = null;
        $wcOrderId   = is_array($order->meta) ? ($order->meta['id'] ?? null) : $order->meta;

        if ($wcOrderId) {
            $wcResponse = Http::get($this->baseUrl . 'orders/' . $wcOrderId, [
                'consumer_key'    => $this->consumerKey,
                'consumer_secret' => $this->consumerSecret,
            ]);

            if ($wcResponse->successful()) {
                $raw         = $wcResponse->json();
                $wcOrderData = [
                    'wc_order_id'    => $raw['id'],
                    'wc_status'      => $raw['status'],
                    'wc_total'       => $raw['total'],
                    'wc_currency'    => $raw['currency'],
                    'date_created'   => $raw['date_created'],
                    'billing'        => $raw['billing'] ?? null,
                    'shipping'       => $raw['shipping'] ?? null,
                    'line_items'     => $raw['line_items'] ?? [],
                    'payment_method' => $raw['payment_method_title'] ?? null,
                    'customer_note'  => $raw['customer_note'] ?? null,
                ];
            }
        }

        $products = $order->activeOrderPvas->map(function ($orderPva) {
            $pva     = $orderPva->productVariationAttribute;
            $product = $pva->product;

            return [
                'order_pva_id'       => $orderPva->id,
                'product_id'         => $product->id,
                'product_title'      => $product->title,
                'product_reference'  => $product->reference,
                'price'              => $orderPva->price,
                'quantity'           => $orderPva->quantity,
                'discount'           => $orderPva->discount,
                'order_status'       => $orderPva->orderStatus->only('id', 'title'),
                'attributes'         => $pva->variationAttribute
                    ?->childVariationAttributes
                    ->map(fn($childVa) => [
                        'id'    => $childVa->attribute_id,
                        'title' => $childVa->attribute->title ?? null,
                    ]) ?? [],
            ];
        });

        return response()->json([
            'statut' => 1,
            'data'   => [
                'id'             => $order->id,
                'code'           => $order->code,
                'shipping_code'  => $order->shipping_code,
                'note'           => $order->note,
                'discount'       => $order->discount,
                'carrier_price'  => $order->carrier_price,
                'created_at'     => $order->created_at,
                'updated_at'     => $order->updated_at,
                'order_status'   => $order->orderStatus?->only('id', 'title'),
                'warehouse'      => $order->warehouse?->only('id', 'title'),
                'payment_type'   => $order->paymentType?->only('id', 'title'),
                'payment_method' => $order->paymentMethod?->only('id', 'title'),
                'brand'          => $order->brandSource?->brand?->only('id', 'title'),
                'source'         => $order->brandSource?->source?->only('id', 'title'),
                'city'           => $order->city?->only('id', 'title'),
                'pickup'         => $order->pickup?->only('id', 'title'),
                'shipment'       => $order->shipment?->only('id', 'title'),
                'customer'       => $order->customer ? [
                    'id'        => $order->customer->id,
                    'name'      => $order->customer->name,
                    'phones'    => $order->customer->phones->map(fn($p) => [
                        'id'    => $p->id,
                        'title' => $p->title,
                        'types' => $p->phoneTypes->pluck('title'),
                    ]),
                    'addresses' => $order->customer->addresses->map(fn($a) => [
                        'id'    => $a->id,
                        'title' => $a->title,
                        'city'  => $a->city?->only('id', 'title'),
                    ]),
                ] : null,
                'products'       => $products,
                'woocommerce'    => $wcOrderData,
            ],
        ]);
    }
}
