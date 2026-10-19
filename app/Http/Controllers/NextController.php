<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Phone;
use App\Models\Address;
use App\Models\City;
use App\Models\ProductVariationAttribute;
use App\Models\BrandSource;
use App\Models\Offer;
use App\Models\Warehouse;
use App\Models\Account;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\CustomerController;
use App\Models\CustomerCall;
use App\Models\OrderCall;
use App\Services\CustomerIdentityService;
use App\Support\Orders\ReturnRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class NextController extends Controller
{
    /**
     * POST /api/create_order
     * 
     * Body: name, phoneNumber, city_id, address, products[{id,quantity}], final_price, brand_source_id
     * Response: {id}
     */
    public function create_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phoneNumber' => 'required|string|max:255',
            'city_id' => 'required|exists:cities,id',
            'address' => 'required|string|max:255',
            'products' => 'required|array|min:1',
            'products.*.id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:1',
            'products.*.attributes' => 'nullable|array',
            'final_price' => 'nullable|numeric',
            'brand_source_id' => 'required|exists:brand_source,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ], 422);
        }

        $accountUser = getAccountUser();
        $accountId = $accountUser ? $accountUser->account_id : null;

        // Fetch fallback warehouse for this account context
        $warehouse = Warehouse::where('account_id', $accountId)->first();
        if (!$warehouse) {
            return response()->json([
                'statut' => 0,
                'data' => 'No active warehouse found for the account context.'
            ], 422);
        }
        $warehouseId = $warehouse->id;

        $productsInput = $request->input('products', []);
        $totalDefaultPrice = 0;
        $totalQuantity = 0;
        $productsPayload = [];

        // Fetch account products to confirm permissions
        $accountUsers = Account::find($accountId)?->accountUsers->pluck('id')->toArray() ?? [];

        foreach ($productsInput as $item) {
            $product = Product::where('id', $item['id'])
                ->whereIn('account_user_id', $accountUsers)
                ->first();

            if (!$product) {
                return response()->json([
                    'statut' => 0,
                    'data' => ["products" => ["Product ID {$item['id']} is invalid or does not belong to your account."]]
                ], 422);
            }

            $qty = intval($item['quantity'] ?? 1);
            $defaultPrice = floatval($product->price->first()?->price ?? $product->sellingprice ?? 0);
            $totalDefaultPrice += $defaultPrice * $qty;
            $totalQuantity += $qty;

            // Resolve variation attribute
            $attributes = [];
            if (isset($item['attributes']) && is_array($item['attributes']) && !empty($item['attributes'])) {
                $attributes = collect($item['attributes'])
                    ->filter(function ($val) {
                        return is_numeric($val);
                    })
                    ->map(function ($val) {
                        return (int) $val;
                    })
                    ->values()
                    ->toArray();
            }

            if (empty($attributes)) {
                $pva = ProductVariationAttribute::where('product_id', $product->id)->first();
                if (!$pva) {
                    return response()->json([
                        'statut' => 0,
                        'data' => ["products" => ["Product ID {$item['id']} does not have any variation attributes defined."]]
                    ], 422);
                }

                if ($pva->variationAttribute) {
                    $attributes = $pva->variationAttribute->childVariationAttributes->pluck('attribute_id')->toArray();
                }
            }

            $productsPayload[] = [
                'id' => $product->id,
                'quantity' => $qty,
                'default_price' => $defaultPrice,
                'attributes' => $attributes,
            ];
        }

        // Scale product pricing if final_price is explicitly provided
        $finalPrice = $request->input('final_price');
        if ($finalPrice !== null) {
            $finalPrice = floatval($finalPrice);
            if ($totalDefaultPrice > 0) {
                $scale = $finalPrice / $totalDefaultPrice;
                foreach ($productsPayload as &$p) {
                    $p['price'] = round($p['default_price'] * $scale, 2);
                }
            } else {
                $pricePerUnit = $totalQuantity > 0 ? round($finalPrice / $totalQuantity, 2) : 0;
                foreach ($productsPayload as &$p) {
                    $p['price'] = $pricePerUnit;
                }
            }
        } else {
            foreach ($productsPayload as &$p) {
                $p['price'] = $p['default_price'];
            }
        }

        // Remove default_price helper property before delegating to existing controller store
        foreach ($productsPayload as &$p) {
            unset($p['default_price']);
        }
        unset($p);

        // Build standard order creation parameters
        $orderPayload = [
            'warehouse_id' => $warehouseId,
            'brand_source_id' => $request->input('brand_source_id'),
            'payment_type_id' => 1,
            'payment_method_id' => 1,
            'order_status_id' => 1,
            'carrier_price' => 0,
            'note' => null,
            'customer' => [
                'name' => $request->input('name'),
                'customer_type_id' => 1,
                'phones' => [
                    [
                        'title' => $request->input('phoneNumber'),
                        'principal' => true,
                        'phoneTypes' => [1],
                    ]
                ],
                'addresses' => [
                    [
                        'title' => $request->input('address'),
                        'principal' => true,
                        'city_id' => $request->input('city_id'),
                    ]
                ],
            ],
            'products' => $productsPayload,
        ];

        // Call the static OrderController store method
        $storeResponse = OrderController::store(new Request([$orderPayload]), $isImport = 0);
        $responseData = $storeResponse->getData(true);

        if (isset($responseData['statut']) && $responseData['statut'] == 1) {
            $orderId = is_array($responseData['data']) ? ($responseData['data'][0] ?? null) : $responseData['data'];
            return response()->json(['id' => $orderId]);
        } else {
            return response()->json([
                'statut' => 0,
                'message' => 'Failed to create order',
                'errors' => $responseData['data'] ?? null
            ], 422);
        }
    }

    /**
     * GET /api/get_products
     * 
     * Params: brand_source_id, search[name] or search or name
     * Response: [{id,name,code,principal_image,images:["",""],price,discount_price}]
     */
    public function get_products(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'brand_source_id' => 'required|exists:brand_source,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ], 422);
        }

        $brandSourceId = $request->input('brand_source_id');
        $search = $request->input('search');

        // Resolve flexible search query formats
        $searchName = null;
        if (is_array($search) && isset($search['name'])) {
            $searchName = $search['name'];
        } elseif (is_string($search)) {
            $searchName = $search;
        } elseif ($request->has('name')) {
            $searchName = $request->input('name');
        }

        $query = Product::query()->where('statut', 1);

        $accountUser = getAccountUser();
        $accountId = $accountUser ? $accountUser->account_id : null;
        if (!$accountId && $brandSourceId) {
            $brandSource = \App\Models\BrandSource::find($brandSourceId);
            $accountId = $brandSource ? $brandSource->account_id : null;
        }
        if ($accountId) {
            $accountUsers = \App\Models\AccountUser::where('account_id', $accountId)->pluck('id')->toArray();
            $query->whereIn('account_user_id', $accountUsers);
        }

        if ($brandSourceId) {
            $query->whereHas('brandSources', function ($q) use ($brandSourceId) {
                $q->where('brand_source.id', $brandSourceId)
                    ->where('product_brand_source.statut', 1);
            });
        }

        if ($searchName !== null && trim(strval($searchName)) !== '') {
            $keywords = array_filter(explode(' ', trim(strval($searchName))));
            if (!empty($keywords)) {
                $query->where(function ($q) use ($keywords) {
                    foreach ($keywords as $keyword) {
                        $q->orWhere('title', 'like', "%{$keyword}%")
                            ->orWhere('code', 'like', "%{$keyword}%")
                            ->orWhere('id', $keyword)
                            ->orWhereHas('accountProducts.taxonomies', function ($qTax) use ($keyword) {
                                $qTax->where('title', 'like', "%{$keyword}%");
                            })
                            ->orWhereHas('productVariationAttributes.variationAttribute.childVariationAttributes.attribute', function ($qAttr) use ($keyword) {
                                $qAttr->where('title', 'like', "%{$keyword}%");
                            });
                    }
                });
            }
        }

        $products = $query->with([
            'price',
            'principalImage',
            'images',
            'offers',
            'activePvas.variationAttribute.childVariationAttributes.attribute.typeAttribute',
            'activePvas.images' => function ($q) {
                $q->orderBy('images.id', 'desc');
            }
        ])->take(50)->get();

        $formattedProducts = $products->map(function ($product) {
            $principalImg = $product->principalImage->first() ?? $product->images->first();
            $principal_image_url = $principalImg ? asset($principalImg->photo_dir . $principalImg->photo) : null;

            $price = floatval($product->price->first()?->price ?? $product->sellingprice ?? 0);

            // Compute discount price from active offers
            $discount_price = $price;
            $activeOffer = $product->offers->where('statut', 1)->first();
            if ($activeOffer) {
                if (floatval($activeOffer->price) > 0) {
                    $discount_price = floatval($activeOffer->price);
                } elseif (floatval($activeOffer->discount) > 0) {
                    $discount_price = $price - floatval($activeOffer->discount);
                }
            }

            $groupedAttrs = $this->getGroupedAttributes($product);

            $productData = [
                'id' => $product->id,
                'name' => $product->title,
                'code' => $product->code,
                'principal_image' => $principal_image_url,
                'price' => $price,
                'discount_price' => $discount_price,
            ];

            return array_merge($productData, $groupedAttrs);
        });

        return response()->json($formattedProducts);
    }

    /**
     * GET /api/get_product
     * 
     * Params: name
     * Response: {id,name,code,principal_image,images:["",""],price,discount_price,offers:[{}]}
     */
    public function get_product(Request $request)
    {
        $name = $request->input('name');

        if (!$name) {
            return response()->json(['statut' => 0, 'message' => 'Product name parameter is required.'], 400);
        }

        $accountUser = getAccountUser();
        $accountId = $accountUser ? $accountUser->account_id : null;

        $query = Product::where('statut', 1);
        if ($name !== null && trim(strval($name)) !== '') {
            $keywords = array_filter(explode(' ', trim(strval($name))));
            if (!empty($keywords)) {
                $query->where(function ($q) use ($keywords) {
                    foreach ($keywords as $keyword) {
                        $q->orWhere('title', 'like', "%{$keyword}%")
                            ->orWhere('code', 'like', "%{$keyword}%")
                            ->orWhere('id', $keyword)
                            ->orWhereHas('accountProducts.taxonomies', function ($qTax) use ($keyword) {
                                $qTax->where('title', 'like', "%{$keyword}%");
                            })
                            ->orWhereHas('productVariationAttributes.variationAttribute.childVariationAttributes.attribute', function ($qAttr) use ($keyword) {
                                $qAttr->where('title', 'like', "%{$keyword}%");
                            });
                    }
                });
            }
        }
        if ($accountId) {
            $accountUsers = \App\Models\AccountUser::where('account_id', $accountId)->pluck('id')->toArray();
            $query->whereIn('account_user_id', $accountUsers);
        }

        $product = $query->with([
            'price',
            'principalImage',
            'images',
            'offers',
            'activePvas.variationAttribute.childVariationAttributes.attribute.typeAttribute',
            'activePvas.images' => function ($q) {
                $q->orderBy('images.id', 'desc');
            }
        ])
            ->first();

        if (!$product) {
            return response()->json(['statut' => 0, 'message' => 'Product not found.'], 404);
        }

        $principalImg = $product->principalImage->first() ?? $product->images->first();
        $principal_image_url = $principalImg ? asset($principalImg->photo_dir . $principalImg->photo) : null;

        $price = floatval($product->price->first()?->price ?? $product->sellingprice ?? 0);

        // Compute discount price from active offers
        $discount_price = $price;
        $activeOffer = $product->offers->where('statut', 1)->first();
        if ($activeOffer) {
            if (floatval($activeOffer->price) > 0) {
                $discount_price = floatval($activeOffer->price);
            } elseif (floatval($activeOffer->discount) > 0) {
                $discount_price = $price - floatval($activeOffer->discount);
            }
        }

        $offers = $product->offers->map(function ($offer) {
            return [
                'id' => $offer->id,
                'code' => $offer->code,
                'title' => $offer->title,
                'price' => $offer->price,
                'discount' => $offer->discount,
                'started' => $offer->started,
                'expired' => $offer->expired,
                'offer_type_id' => $offer->offer_type_id,
            ];
        })->toArray();

        $groupedAttrs = $this->getGroupedAttributes($product);

        $productData = [
            'id' => $product->id,
            'name' => $product->title,
            'code' => $product->code,
            'principal_image' => $principal_image_url,
            'price' => $price,
            'discount_price' => $discount_price,
            'offers' => $offers,
        ];

        return response()->json(array_merge($productData, $groupedAttrs));
    }

    /**
     * GET /api/getProduct
     * 
     * Params: search, brand_source_id
     * Response: [{id,name,code,principal_image,images:["",""],price,discount_price,offers:[{}]}]
     */
    public function getProduct(Request $request)
    {
        $search = $request->input('search');
        $brandSourceId = $request->input('brand_source_id');

        $query = Product::query()->where('statut', 1);

        $accountUser = getAccountUser();
        $accountId = $accountUser ? $accountUser->account_id : null;
        if (!$accountId && $brandSourceId) {
            $brandSource = \App\Models\BrandSource::find($brandSourceId);
            $accountId = $brandSource ? $brandSource->account_id : null;
        }
        if ($accountId) {
            $accountUsers = \App\Models\AccountUser::where('account_id', $accountId)->pluck('id')->toArray();
            $query->whereIn('account_user_id', $accountUsers);
        }

        if ($brandSourceId) {
            $query->whereHas('brandSources', function ($q) use ($brandSourceId) {
                $q->where('brand_source.id', $brandSourceId)
                    ->where('product_brand_source.statut', 1);
            });
        }

        if ($search !== null && trim(strval($search)) !== '') {
            $keywords = array_filter(explode(' ', trim(strval($search))));
            if (!empty($keywords)) {
                $query->where(function ($q) use ($keywords) {
                    foreach ($keywords as $keyword) {
                        $q->orWhere('title', 'like', "%{$keyword}%")
                            ->orWhere('code', 'like', "%{$keyword}%")
                            ->orWhere('id', $keyword)
                            ->orWhereHas('accountProducts.taxonomies', function ($qTax) use ($keyword) {
                                $qTax->where('title', 'like', "%{$keyword}%");
                            })
                            ->orWhereHas('productVariationAttributes.variationAttribute.childVariationAttributes.attribute', function ($qAttr) use ($keyword) {
                                $qAttr->where('title', 'like', "%{$keyword}%");
                            });
                    }
                });
            }
        }

        $products = $query->with([
            'price',
            'principalImage',
            'images',
            'offers',
            'activePvas.variationAttribute.childVariationAttributes.attribute.typeAttribute',
            'activePvas.images' => function ($q) {
                $q->orderBy('images.id', 'desc');
            }
        ])->get();

        $formattedProducts = $products->map(function ($product) {
            $principalImg = $product->principalImage->first() ?? $product->images->first();
            $principal_image_url = $principalImg ? asset($principalImg->photo_dir . $principalImg->photo) : null;

            $price = floatval($product->price->first()?->price ?? $product->sellingprice ?? 0);

            // Compute discount price from active offers
            $discount_price = $price;
            $activeOffer = $product->offers->where('statut', 1)->first();
            if ($activeOffer) {
                if (floatval($activeOffer->price) > 0) {
                    $discount_price = floatval($activeOffer->price);
                } elseif (floatval($activeOffer->discount) > 0) {
                    $discount_price = $price - floatval($activeOffer->discount);
                }
            }

            $offers = $product->offers->map(function ($offer) {
                return [
                    'id' => $offer->id,
                    'code' => $offer->code,
                    'title' => $offer->title,
                    'price' => $offer->price,
                    'discount' => $offer->discount,
                    'started' => $offer->started,
                    'expired' => $offer->expired,
                    'offer_type_id' => $offer->offer_type_id,
                ];
            })->toArray();

            $groupedAttrs = $this->getGroupedAttributes($product);

            $productData = [
                'id' => $product->id,
                'name' => $product->title,
                'code' => $product->code,
                'principal_image' => $principal_image_url,
                'price' => $price,
                'discount_price' => $discount_price,
                'offers' => $offers,
            ];

            return array_merge($productData, $groupedAttrs);
        });

        return response()->json($formattedProducts);
    }

    /**
     * Helper to resolve customer model from either an ID or a phone number.
     */
    private function resolveCustomer(Request $request, $id = null): ?Customer
    {
        $customerId = $id ?? $request->input('id') ?? $request->input('customer_id');
        $accountUser = getAccountUser();
        $accountId = $accountUser ? $accountUser->account_id : null;

        if ($customerId) {
            $query = Customer::query();
            if ($accountId) {
                $query->where('customers.account_id', $accountId);
            }
            return $query->find($customerId);
        }

        $phoneNumber = $request->input('phoneNumber') ?? $request->input('phone') ?? $request->input('primary_phone');
        if ($phoneNumber) {
            $formattedPhone = CustomerIdentityService::normalizePhone((string) $phoneNumber);
            $phone = Phone::where('title', $formattedPhone)->first();
            if (!$phone && $phoneNumber !== $formattedPhone) {
                $phone = Phone::where('title', $phoneNumber)->first();
            }
            if (!$phone) {
                $cleaned = preg_replace('/\D/', '', (string) $phoneNumber);
                if (!empty($cleaned)) {
                    $phone = Phone::whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(title, ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?", ["%{$cleaned}%"])->first();
                }
            }
            if ($phone) {
                $customerQuery = $phone->customers();
                if ($accountId) {
                    $customerQuery->where('customers.account_id', $accountId);
                }
                return $customerQuery->first() ?? $phone->customers()->first();
            }
        }

        return null;
    }

    /**
     * GET /api/next/get_customer/{id?}
     * GET /api/next/get_customer_details/{id?}
     * GET /api/next/customer/{id}
     * 
     * Params: id (in path or query) OR phoneNumber / phone (in query)
     * Returns Customer 360 data with KPIs and order history, with backward compatibility.
     */
    public function get_customer(Request $request, $id = null)
    {
        $customerId = $id ?? $request->input('id') ?? $request->input('customer_id');
        $phoneNumber = $request->input('phoneNumber') ?? $request->input('phone') ?? $request->input('primary_phone');

        if (!$customerId && !$phoneNumber) {
            return response()->json(['statut' => 0, 'message' => 'Customer ID or phoneNumber parameter is required.'], 400);
        }

        $customer = $this->resolveCustomer($request, $id);

        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'Customer not found.'], 404);
        }

        // Load relations
        $customer->load(['phones.phoneTypes', 'addresses.city', 'customerType', 'images', 'tags']);

        $primaryAddress = $customer->addresses()->where('addressables.statut', 1)->first() ?? $customer->addresses->first();
        $city = $primaryAddress?->city?->title ?? null;
        $address = $primaryAddress?->title ?? null;
        $primaryPhone = $customer->phones->first()?->title ?? $phoneNumber ?? null;

        // Compute 360 metrics
        $metricsMap = CustomerIdentityService::batchCustomerMetrics([$customer->id]);
        $m = $metricsMap[$customer->id] ?? [
            'delivered_count' => 0,
            'returned_count' => 0,
            'pending_count' => 0,
            'total_orders' => 0,
            'lifetime_value' => 0.0,
            'average_order_value' => 0.0,
            'delivery_rate' => null,
            'return_rate' => 0.0,
            'tier' => 'Regular',
            'trust_level' => 'new',
        ];

        // Fetch customer orders with rich relationships
        $orders = $customer->orders()
            ->with([
                'orderStatus',
                'brandSource.brand',
                'brandSource.source',
                'pickup.carrier',
                'shipment.carrier',
                'orderComments.comment',
                'orderComments.accountUser',
                'orderComments.orderStatus',
                'productVariationAttributes.product.images',
                'productVariationAttributes.variationAttribute.childVariationAttributes.attribute.typeAttribute',
                'orderPvas.productVariationAttribute.product.images',
                'orderPvas.productVariationAttribute.variationAttribute.childVariationAttributes.attribute.typeAttribute',
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $orderPvaIds = $orders->filter(fn ($order) => ReturnRules::isReturnable($order))
            ->flatMap(fn ($order) => $order->orderPvas->pluck('id'))
            ->all();
        $returned = ReturnRules::returnedQuantities($orderPvaIds);

        $formattedOrders = $orders->map(function ($order) use ($returned) {
            $orderTotal = 0;
            $returnable = ReturnRules::isReturnable($order);
            $inactive = fn ($orderPva) => in_array((int) $orderPva->order_status_id, ReturnRules::INACTIVE_LINE_STATUSES, true);
            $lines = (int) $order->order_status_id === 2 ? $order->orderPvas->filter($inactive) : $order->orderPvas->reject($inactive);
            $unitValues = ReturnRules::unitValuesOf($order->orderPvas->reject($inactive), (float) $order->discount);

            $products = $lines->map(function ($orderPva) use (&$orderTotal, $returnable, $returned, $unitValues) {
                $orderTotal += $orderPva->price * $orderPva->quantity;
                $alreadyReturned = $returned[$orderPva->id] ?? 0;
                $pva = $orderPva->productVariationAttribute;
                if (!$pva) return null;

                $attributes = $pva->variationAttribute?->childVariationAttributes?->map(function ($child) {
                    return $child->attribute->title ?? $child->attribute->code;
                })->toArray() ?? [];

                return [
                    'order_pva_id' => $orderPva->id,
                    'id' => $pva->product_id,
                    'product_id' => $pva->product_id,
                    'name' => $pva->product->title . (!empty($attributes) ? ' ' . implode('-', $attributes) : ''),
                    'product' => $pva->product->title . (!empty($attributes) ? ' ' . implode('-', $attributes) : ''),
                    'reference' => $pva->product->reference ?? null,
                    'quantity' => (int) $orderPva->quantity,
                    'price' => floatval($orderPva->price),
                    'refund_unit_price' => $unitValues[$orderPva->id] ?? (float) $orderPva->price,
                    'returned_quantity' => $alreadyReturned,
                    'returnable_quantity' => $returnable ? max(0, (int) $orderPva->quantity - $alreadyReturned) : 0,
                    'images' => $pva->product->images ?? [],
                    'attributes' => $pva->variationAttribute?->childVariationAttributes?->map(function ($child) {
                        return [
                            'id' => $child->attribute->id,
                            'title' => $child->attribute->title,
                            'typeAttribute' => $child->attribute->typeAttribute?->title ?? null,
                        ];
                    }) ?? [],
                ];
            })->filter()->values()->toArray();

            $delivery_men = $order->pickup?->carrier?->title ?? $order->shipment?->carrier?->title ?? null;

            $history = $order->orderComments->map(function ($orderComment) {
                return [
                    'status' => $orderComment->orderStatus?->title ?? $orderComment->title,
                    'created_at' => $orderComment->created_at?->toIso8601String() ?? $orderComment->created_at?->toDateTimeString(),
                    'note' => $orderComment->title,
                    'comment' => $orderComment->comment?->title ?? null,
                    'user' => $orderComment->accountUser ? ($orderComment->accountUser->first_name . ' ' . $orderComment->accountUser->last_name) : null,
                ];
            })->toArray();

            $computedTotal = max(0, $orderTotal + (float) $order->carrier_price - (float) $order->discount);

            return [
                'id' => $order->id,
                'code' => $order->code,
                'order_id' => $order->order_id,
                'type' => $order->type,
                'status' => $order->orderStatus?->title,
                'status_id' => (int) $order->order_status_id,
                'create_at' => $order->created_at?->toDateTimeString(),
                'date' => $order->created_at?->toIso8601String(),
                'total' => $computedTotal,
                'returnable' => $returnable && collect($products)->sum('returnable_quantity') > 0,
                'products' => $products,
                'brand' => $order->brandSource?->brand?->title ?? null,
                'source' => $order->brandSource?->source?->title ?? null,
                'shippement' => [
                    'delivery_men' => $delivery_men,
                    'history' => $history,
                ],
                'shipment' => [
                    'delivery_men' => $delivery_men,
                    'history' => $history,
                ],
                'order_comments' => $history,
            ];
        })->toArray();

        $customerData = [
            'id' => $customer->id,
            'name' => $customer->name,
            'code' => $customer->code,
            'note' => $customer->note,
            'notes' => $customer->note,
            'is_blacklisted' => (bool) $customer->is_blacklisted,
            'wallet_balance' => (float) $customer->wallet_balance,
            'discount_percent' => (float) $customer->discount_percent,
            'customer_type' => $customer->customerType ? $customer->customerType->only('id', 'title') : null,
            'primary_phone' => $primaryPhone,
            'primary_address' => $primaryAddress ? ($primaryAddress->title . ($primaryAddress->city ? ', ' . $primaryAddress->city->title : '')) : null,
            'city' => $city,
            'city_id' => $primaryAddress?->city_id,
            'address' => $address,
            'phones' => $customer->phones->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'phone' => $p->title,
                'phone_types' => $p->phoneTypes->map(fn ($pt) => ['id' => $pt->id, 'title' => $pt->title])->values(),
                'phoneTypes' => $p->phoneTypes->map(fn ($pt) => ['id' => $pt->id, 'title' => $pt->title])->values(),
            ])->values(),
            'addresses' => $customer->addresses->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'address' => $a->title,
                'city_id' => $a->city_id,
                'city' => $a->city ? $a->city->only('id', 'title') : null,
                'statut' => $a->pivot?->statut ?? 0,
            ])->values(),
            'images' => $customer->images,
            'tags' => $customer->tags,
            'orders_count' => $m['total_orders'],
            'delivered_count' => $m['delivered_count'],
            'returned_count' => $m['returned_count'],
            'pending_count' => $m['pending_count'],
            'delivery_rate' => $m['delivery_rate'],
            'return_rate' => $m['return_rate'],
            'lifetime_value' => $m['lifetime_value'],
            'average_order_value' => $m['average_order_value'],
            'tier' => $m['tier'],
            'trust_level' => $m['trust_level'],
            'orders' => $formattedOrders,
        ];

        return response()->json([
            'statut' => 1,
            'data' => $customerData,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phoneNumber' => $primaryPhone,
                'city' => $city,
                'address' => $address,
            ],
            'orders' => $formattedOrders,
        ]);
    }

    /**
     * GET /api/next/get_customers
     * GET /api/next/customers
     * 
     * Params: search, segment, city_id, product_id, is_blacklisted, has_multiple_orders, is_loyal, page, per_page
     */
    public function get_customers(Request $request)
    {
        $accountUser = getAccountUser();
        $accountId = $accountUser ? $accountUser->account_id : null;

        $perPage = max(1, min(200, (int) $request->input('per_page', $request->input('pagination.per_page', 15))));
        $currentPage = max(1, (int) $request->input('page', $request->input('pagination.page', $request->input('pagination.current_page', 1))));

        $query = Customer::query();
        if ($accountId) {
            $query->where('customers.account_id', $accountId);
        }

        // Search by name, code, note, or raw/normalized phone
        if ($request->filled('search')) {
            $searchTerm = trim((string) $request->input('search'));
            $normalizedPhoneSearch = CustomerIdentityService::normalizePhone($searchTerm);

            $query->where(function ($q) use ($searchTerm, $normalizedPhoneSearch) {
                $q->where('customers.name', 'like', "%{$searchTerm}%")
                    ->orWhere('customers.code', 'like', "%{$searchTerm}%")
                    ->orWhere('customers.note', 'like', "%{$searchTerm}%")
                    ->orWhereHas('phones', function ($phoneQuery) use ($searchTerm, $normalizedPhoneSearch) {
                        $phoneQuery->where('title', 'like', "%{$searchTerm}%");
                        if ($normalizedPhoneSearch !== '' && $normalizedPhoneSearch !== $searchTerm) {
                            $phoneQuery->orWhere('title', 'like', "%{$normalizedPhoneSearch}%");
                        }
                    });
            });
        }

        // Segment filter or flags
        $segment = (string) $request->input('segment', '');
        if ($segment !== '' && $segment !== 'all') {
            CustomerController::applySegmentFilter($query, $segment);
        } else {
            if ($request->has('is_blacklisted') && $request->input('is_blacklisted') !== null && $request->input('is_blacklisted') !== '') {
                $query->where('customers.is_blacklisted', $request->input('is_blacklisted') == 1);
            }
            if ($request->input('has_multiple_orders') == 1) {
                CustomerController::applySegmentFilter($query, 'repeat');
            }
            if ($request->input('is_loyal') == 1) {
                CustomerController::applySegmentFilter($query, 'vip');
            }
        }

        // City filter
        if ($request->filled('city_id')) {
            $cityId = (int) $request->input('city_id');
            if ($cityId > 0) {
                $query->whereHas('addresses', function ($q) use ($cityId) {
                    $q->where('addresses.city_id', $cityId);
                });
            }
        }

        // Product filter
        if ($request->filled('product_id')) {
            $productId = (int) $request->input('product_id');
            if ($productId > 0) {
                $query->whereHas('orders', function ($oq) use ($productId) {
                    $oq->where('orders.type', 'sale')
                        ->whereHas('orderPvas.productVariationAttribute', function ($pq) use ($productId) {
                            $pq->where('product_id', $productId);
                        });
                });
            }
        }

        $query->with(['phones.phoneTypes', 'addresses.city', 'customerType', 'images'])
            ->with([
                'latestOrder' => function ($q) {
                    $q->where('type', 'sale');
                }
            ])
            ->orderByDesc('customers.id');

        $paginator = $query->paginate($perPage, ['*'], 'page', $currentPage);
        $customerIds = $paginator->getCollection()->pluck('id')->all();
        $metricsMap = CustomerIdentityService::batchCustomerMetrics($customerIds);

        $formattedData = $paginator->getCollection()->map(function ($customer) use ($metricsMap) {
            $primaryAddress = $customer->addresses->first();
            $m = $metricsMap[$customer->id] ?? [
                'delivered_count' => 0,
                'returned_count' => 0,
                'pending_count' => 0,
                'total_orders' => 0,
                'lifetime_value' => 0.0,
                'average_order_value' => 0.0,
                'delivery_rate' => null,
                'return_rate' => 0.0,
                'tier' => 'Regular',
                'trust_level' => 'new',
            ];

            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'code' => $customer->code,
                'note' => $customer->note,
                'notes' => $customer->note,
                'is_blacklisted' => (bool) $customer->is_blacklisted,
                'tier' => $m['tier'],
                'trust_level' => $m['trust_level'],
                'customer_type' => $customer->customerType ? $customer->customerType->only('id', 'title') : null,
                'primary_phone' => $customer->phones->first()?->title,
                'primary_address' => $primaryAddress ? ($primaryAddress->title . ($primaryAddress->city ? ', ' . $primaryAddress->city->title : '')) : null,
                'city' => $primaryAddress && $primaryAddress->city ? $primaryAddress->city->title : null,
                'city_id' => $primaryAddress ? $primaryAddress->city_id : null,
                'images' => $customer->images,
                'orders_count' => $m['total_orders'],
                'delivered_count' => $m['delivered_count'],
                'returned_count' => $m['returned_count'],
                'pending_count' => $m['pending_count'],
                'delivery_rate' => $m['delivery_rate'],
                'return_rate' => $m['return_rate'],
                'lifetime_value' => $m['lifetime_value'],
                'average_order_value' => $m['average_order_value'],
                'last_order_date' => $customer->latestOrder ? $customer->latestOrder->created_at->toIso8601String() : null,
                'created_at' => $customer->created_at ? $customer->created_at->toIso8601String() : null,
                'phones' => $customer->phones->map(fn ($p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'phone' => $p->title,
                    'phone_types' => $p->phoneTypes->map(fn ($pt) => ['id' => $pt->id, 'title' => $pt->title])->values(),
                ])->values(),
                'addresses' => $customer->addresses->map(fn ($a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'city_id' => $a->city_id,
                    'city' => $a->city ? $a->city->only('id', 'title') : null,
                ])->values(),
            ];
        });

        return response()->json([
            'statut' => 1,
            'data' => $formattedData,
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    /**
     * GET /api/next/get_customers_counts
     * GET /api/next/customers/counts
     * 
     * Returns count of customers per segment.
     */
    public function get_customers_counts(Request $request)
    {
        $accountUser = getAccountUser();
        $accountId = $accountUser ? $accountUser->account_id : null;

        $countSegment = function (string $segment) use ($accountId): int {
            $q = Customer::query();
            if ($accountId) {
                $q->where('customers.account_id', $accountId);
            }
            if ($segment !== 'all') {
                CustomerController::applySegmentFilter($q, $segment);
            }
            return $q->count();
        };

        $counts = [
            'all' => $countSegment('all'),
            'repeat' => $countSegment('repeat'),
            'multiple_orders' => $countSegment('repeat'),
            'vip' => $countSegment('vip'),
            'loyal' => $countSegment('vip'),
            'dormant' => $countSegment('dormant'),
            'risky' => $countSegment('risky'),
            'blacklisted' => $countSegment('blacklisted'),
        ];

        return response()->json(array_merge([
            'statut' => 1,
            'data' => $counts,
        ], $counts));
    }

    /**
     * POST /api/next/create_customer
     * POST /api/next/customers
     * 
     * Body supports flat or structured: name, phoneNumber/phone, city_id, address, note, email, customer_type_id, phones, addresses
     */
    public function create_customer(Request $request)
    {
        $accountUser = getAccountUser();
        $accountId = $accountUser ? $accountUser->account_id : null;

        $raw = $request->all();
        $item = (isset($raw[0]) && is_array($raw[0])) ? $raw[0] : $raw;

        // Auto-convert flat fields to structured arrays
        if (!isset($item['phones']) && (isset($item['phoneNumber']) || isset($item['phone']))) {
            $ph = $item['phoneNumber'] ?? $item['phone'];
            $pts = $item['phoneTypes'] ?? $item['phone_types'] ?? [];
            $item['phones'] = [
                ['title' => $ph, 'phoneTypes' => $pts]
            ];
        }

        if (!isset($item['addresses']) && (isset($item['address']) || isset($item['city_id']))) {
            $item['addresses'] = [
                [
                    'title' => $item['address'] ?? '',
                    'city_id' => $item['city_id'] ?? null,
                ]
            ];
        }

        $normalized = CustomerController::normalizeCustomerItem($item);

        $validator = Validator::make($normalized, [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'customer_type_id' => 'nullable|exists:customer_types,id',
            'note' => 'nullable|string',
            'phones' => 'required|array|min:1',
            'phones.*.title' => 'required|string',
            'addresses' => 'nullable|array',
            'addresses.*.city_id' => 'nullable|exists:cities,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $customer = DB::transaction(function () use ($normalized, $accountId) {
            $phones = $normalized['phones'] ?? [];
            $addresses = $normalized['addresses'] ?? [];

            $nextId = (Customer::max('id') ?? 0) + 1;
            $code = 'CL-' . str_pad($nextId, 5, '0', STR_PAD_LEFT);

            $customer = Customer::create([
                'name' => $normalized['name'],
                'code' => $code,
                'note' => $normalized['note'] ?? null,
                'customer_type_id' => $normalized['customer_type_id'] ?? null,
                'wallet_balance' => (float) ($normalized['wallet_balance'] ?? 0),
                'discount_percent' => (float) ($normalized['discount_percent'] ?? 0),
                'account_id' => $accountId,
                'statut' => 1,
            ]);

            foreach ($phones as $phoneData) {
                $normPhone = CustomerIdentityService::normalizePhone((string) ($phoneData['title'] ?? ''));
                if ($normPhone === '') {
                    continue;
                }

                $phoneTypes = isset($phoneData['phoneTypes']) ? collect($phoneData['phoneTypes'])
                    ->map(fn ($pt) => is_array($pt) ? ($pt['id'] ?? null) : (is_numeric($pt) ? (int) $pt : null))
                    ->filter()->values()->all() : null;

                $accountPhone = Phone::where('account_id', $accountId)->where('title', $normPhone)->first();
                if ($accountPhone) {
                    $customer->phones()->syncWithoutDetaching([$accountPhone->id]);
                    if ($phoneTypes !== null) {
                        $accountPhone->phoneTypes()->sync($phoneTypes);
                    }
                } else {
                    $newPhone = $customer->phones()->create([
                        'title' => $normPhone,
                        'account_id' => $accountId,
                    ]);
                    if ($phoneTypes !== null) {
                        $newPhone->phoneTypes()->sync($phoneTypes);
                    }
                }
            }

            foreach ($addresses as $addressData) {
                $title = trim((string) ($addressData['title'] ?? ''));
                $cityId = !empty($addressData['city_id']) ? (int) $addressData['city_id'] : null;
                if ($title !== '' || $cityId !== null) {
                    $customer->addresses()->create([
                        'title' => $title !== '' ? $title : 'Adresse',
                        'city_id' => $cityId,
                        'account_id' => $accountId,
                    ]);
                }
            }

            return $customer;
        });

        $customer->load(['phones.phoneTypes', 'addresses.city', 'customerType']);

        return response()->json([
            'statut' => 1,
            'message' => 'Customer created successfully.',
            'id' => $customer->id,
            'data' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'code' => $customer->code,
                'note' => $customer->note,
                'primary_phone' => $customer->phones->first()?->title,
                'customer_type' => $customer->customerType ? $customer->customerType->only('id', 'title') : null,
                'phones' => $customer->phones->map(fn ($p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'phone' => $p->title,
                    'phone_types' => $p->phoneTypes->map(fn ($pt) => ['id' => $pt->id, 'title' => $pt->title])->values(),
                ])->values(),
                'addresses' => $customer->addresses->map(fn ($a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'city_id' => $a->city_id,
                    'city' => $a->city ? $a->city->only('id', 'title') : null,
                ])->values(),
            ],
        ], 201);
    }

    /**
     * POST /api/next/update_customer/{id?}
     * PUT /api/next/update_customer/{id}
     * 
     * Body supports flat or structured updates.
     */
    public function update_customer(Request $request, $id = null)
    {
        $customer = $this->resolveCustomer($request, $id);
        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'Customer not found.'], 404);
        }

        $raw = $request->all();
        $item = (isset($raw[0]) && is_array($raw[0])) ? $raw[0] : $raw;

        if (!isset($item['phones']) && (isset($item['phoneNumber']) || isset($item['phone']))) {
            $ph = $item['phoneNumber'] ?? $item['phone'];
            $pts = $item['phoneTypes'] ?? $item['phone_types'] ?? null;
            $phoneEntry = ['title' => $ph];
            if ($pts !== null) {
                $phoneEntry['phoneTypes'] = $pts;
            }
            $item['phones'] = [$phoneEntry];
        }

        if (!isset($item['addresses']) && (isset($item['address']) || isset($item['city_id']))) {
            $item['addresses'] = [
                [
                    'title' => $item['address'] ?? '',
                    'city_id' => $item['city_id'] ?? null,
                ]
            ];
        }

        $normalized = CustomerController::normalizeCustomerItem($item);

        $validator = Validator::make($normalized, [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'customer_type_id' => 'sometimes|nullable|exists:customer_types,id',
            'note' => 'sometimes|nullable|string',
            'phones' => 'sometimes|nullable|array',
            'phones.*.id' => 'nullable|exists:phones,id',
            'phones.*.title' => 'required|string',
            'addresses' => 'sometimes|nullable|array',
            'addresses.*.id' => 'nullable|exists:addresses,id',
            'addresses.*.city_id' => 'nullable|exists:cities,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $accountId = $customer->account_id ?? (getAccountUser() ? getAccountUser()->account_id : null);

        DB::transaction(function () use ($normalized, $customer, $accountId) {
            $updateFields = \Illuminate\Support\Arr::only($normalized, [
                'name', 'email', 'customer_type_id', 'note', 'wallet_balance', 'discount_percent', 'is_blacklisted'
            ]);

            if (!empty($updateFields)) {
                $customer->update($updateFields);
            }

            // Sync Phones
            if (isset($normalized['phones']) && is_array($normalized['phones'])) {
                $phoneIds = [];
                foreach ($normalized['phones'] as $phoneData) {
                    $normPhone = CustomerIdentityService::normalizePhone((string) ($phoneData['title'] ?? ''));
                    if ($normPhone === '') {
                        continue;
                    }

                    $phoneTypes = isset($phoneData['phoneTypes']) ? collect($phoneData['phoneTypes'])
                        ->map(fn ($pt) => is_array($pt) ? ($pt['id'] ?? null) : (is_numeric($pt) ? (int) $pt : null))
                        ->filter()->values()->all() : null;

                    if (isset($phoneData['id'])) {
                        $phone = Phone::find($phoneData['id']);
                        if ($phone) {
                            $phone->update(['title' => $normPhone]);
                            if ($phoneTypes !== null) {
                                $phone->phoneTypes()->sync($phoneTypes);
                            }
                            $phoneIds[] = $phone->id;
                        }
                    } else {
                        $existingPhone = $customer->phones()->where('phones.title', $normPhone)->first();
                        if ($existingPhone) {
                            if ($phoneTypes !== null) {
                                $existingPhone->phoneTypes()->sync($phoneTypes);
                            }
                            $phoneIds[] = $existingPhone->id;
                        } else {
                            $accountPhone = Phone::where('account_id', $accountId)->where('title', $normPhone)->first();
                            if ($accountPhone) {
                                $customer->phones()->syncWithoutDetaching([$accountPhone->id]);
                                if ($phoneTypes !== null) {
                                    $accountPhone->phoneTypes()->sync($phoneTypes);
                                }
                                $phoneIds[] = $accountPhone->id;
                            } else {
                                $newPhone = $customer->phones()->create([
                                    'title' => $normPhone,
                                    'account_id' => $accountId,
                                ]);
                                if ($phoneTypes !== null) {
                                    $newPhone->phoneTypes()->sync($phoneTypes);
                                }
                                $phoneIds[] = $newPhone->id;
                            }
                        }
                    }
                }

                if (!empty($phoneIds)) {
                    $toDetachPhones = $customer->phones()->whereNotIn('phones.id', $phoneIds)->pluck('phones.id')->all();
                    if (!empty($toDetachPhones)) {
                        $customer->phones()->detach($toDetachPhones);
                    }
                }
            }

            // Sync Addresses
            if (isset($normalized['addresses']) && is_array($normalized['addresses'])) {
                $addressIds = [];
                foreach ($normalized['addresses'] as $addressData) {
                    $title = trim((string) ($addressData['title'] ?? ''));
                    $cityId = !empty($addressData['city_id']) ? (int) $addressData['city_id'] : null;

                    if (isset($addressData['id'])) {
                        $address = Address::find($addressData['id']);
                        if ($address) {
                            $address->update([
                                'title' => $title !== '' ? $title : $address->title,
                                'city_id' => $cityId ?? $address->city_id,
                            ]);
                            $addressIds[] = $address->id;
                        }
                    } else {
                        if ($title !== '' || $cityId !== null) {
                            $newAddress = $customer->addresses()->create([
                                'title' => $title !== '' ? $title : 'Adresse',
                                'city_id' => $cityId,
                                'account_id' => $accountId,
                            ]);
                            $addressIds[] = $newAddress->id;
                        }
                    }
                }

                if (!empty($addressIds)) {
                    $toDetachAddresses = $customer->addresses()->whereNotIn('addresses.id', $addressIds)->pluck('addresses.id')->all();
                    if (!empty($toDetachAddresses)) {
                        $customer->addresses()->detach($toDetachAddresses);
                    }
                }
            }
        });

        $customer->load(['phones.phoneTypes', 'addresses.city', 'customerType']);

        return response()->json([
            'statut' => 1,
            'message' => 'Customer updated successfully.',
            'data' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'code' => $customer->code,
                'note' => $customer->note,
                'is_blacklisted' => (bool) $customer->is_blacklisted,
                'primary_phone' => $customer->phones->first()?->title,
                'customer_type' => $customer->customerType ? $customer->customerType->only('id', 'title') : null,
                'phones' => $customer->phones->map(fn ($p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'phone' => $p->title,
                    'phone_types' => $p->phoneTypes->map(fn ($pt) => ['id' => $pt->id, 'title' => $pt->title])->values(),
                ])->values(),
                'addresses' => $customer->addresses->map(fn ($a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'city_id' => $a->city_id,
                    'city' => $a->city ? $a->city->only('id', 'title') : null,
                ])->values(),
            ],
        ]);
    }

    /**
     * POST /api/next/delete_customer/{id?}
     * DELETE /api/next/delete_customer/{id?}
     */
    public function delete_customer(Request $request, $id = null)
    {
        $customer = $this->resolveCustomer($request, $id);
        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'Customer not found.'], 404);
        }

        $customer->delete();

        return response()->json(['statut' => 1, 'message' => 'Customer deleted successfully.']);
    }

    /**
     * POST /api/next/log_customer_call/{id?}
     */
    public function log_customer_call(Request $request, $id = null)
    {
        $customer = $this->resolveCustomer($request, $id);
        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'Customer not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'outcome' => 'required|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $call = $customer->calls()->create([
            'outcome' => $request->input('outcome'),
            'notes' => $request->input('notes'),
        ]);

        return response()->json([
            'statut' => 1,
            'message' => 'Call logged successfully.',
            'data' => $call,
        ]);
    }

    /**
     * POST /api/next/toggle_customer_blacklist/{id?}
     */
    public function toggle_customer_blacklist(Request $request, $id = null)
    {
        $customer = $this->resolveCustomer($request, $id);
        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'Customer not found.'], 404);
        }

        $customer->is_blacklisted = !$customer->is_blacklisted;

        if ($customer->is_blacklisted && $request->filled('reason')) {
            $reason = trim((string) $request->input('reason'));
            $existingNote = trim((string) $customer->note);
            if ($reason !== '' && !str_contains($existingNote, $reason)) {
                $customer->note = $existingNote !== '' ? ($existingNote . ' | Blacklist: ' . $reason) : ('Blacklist: ' . $reason);
            }
        }

        $customer->save();

        return response()->json([
            'statut' => 1,
            'message' => 'Blacklist status toggled.',
            'is_blacklisted' => (bool) $customer->is_blacklisted,
            'note' => $customer->note,
        ]);
    }

    /**
     * GET /api/next/get_customer_timeline/{id?}
     * GET /api/next/customer_timeline/{id?}
     */
    public function get_customer_timeline(Request $request, $id = null)
    {
        $customer = $this->resolveCustomer($request, $id);
        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'Customer not found.'], 404);
        }

        $timeline = collect();

        if ($customer->created_at) {
            $timeline->push([
                'type' => 'customer_created',
                'date' => $customer->created_at,
                'description' => 'Customer profile was created.',
                'data' => null,
            ]);
        }

        $orders = $customer->orders()->with(['orderStatus', 'parentOrder'])->get();
        foreach ($orders as $order) {
            $eventType = 'order_placed';
            $description = 'Order #' . $order->code . ' was placed.';

            if ($order->type === 'return') {
                $eventType = 'return_created';
                $description = 'Return Order #' . $order->code . ' was created.';
            } elseif ($order->type === 'sale' && $order->order_id) {
                if ($order->parentOrder && $order->parentOrder->type === 'return') {
                    $eventType = 'exchange_created';
                    $description = 'Exchange Order #' . $order->code . ' was created from Return #' . $order->parentOrder->code . '.';
                } elseif ($order->parentOrder && $order->parentOrder->type === 'sale') {
                    $eventType = 'delivery_swapped';
                    $description = 'Order #' . $order->code . ' took over delivery from Order #' . $order->parentOrder->code . '.';
                }
            }

            $timeline->push([
                'type' => $eventType,
                'date' => $order->created_at,
                'description' => $description,
                'data' => [
                    'order_id' => $order->id,
                    'code' => $order->code,
                    'status' => $order->orderStatus ? $order->orderStatus->title : null,
                ],
            ]);

            if ((int) $order->order_status_id === 11) {
                $timeline->push([
                    'type' => 'return_initiated',
                    'date' => $order->updated_at,
                    'description' => 'Order #' . $order->code . ' was returned.',
                    'data' => [
                        'order_id' => $order->id,
                        'code' => $order->code,
                    ],
                ]);
            }
        }

        $calls = $customer->calls()->get();
        foreach ($calls as $call) {
            $timeline->push([
                'type' => 'call_logged',
                'date' => $call->created_at,
                'description' => 'Customer Call: ' . $call->outcome,
                'data' => [
                    'outcome' => $call->outcome,
                    'notes' => $call->notes,
                ],
            ]);
        }

        $orderCalls = OrderCall::whereHas('order', function ($query) use ($customer) {
            $query->where('customer_id', $customer->id);
        })->get();

        foreach ($orderCalls as $orderCall) {
            $timeline->push([
                'type' => 'order_call_logged',
                'date' => $orderCall->called_at ?? $orderCall->created_at,
                'description' => 'Order Call (' . ($orderCall->result ?? 'No result') . ')',
                'data' => [
                    'order_id' => $orderCall->order_id,
                    'employee_id' => $orderCall->employee_id,
                    'call_number' => $orderCall->call_number,
                    'result' => $orderCall->result,
                    'duration' => $orderCall->call_duration,
                    'notes' => $orderCall->note,
                ],
            ]);
        }

        $sortedTimeline = $timeline->filter(fn ($item) => !is_null($item['date']))
            ->sortByDesc('date')
            ->values()
            ->map(function ($item) {
                $item['date'] = $item['date'] instanceof \Carbon\Carbon
                    ? $item['date']->toIso8601String()
                    : (string) $item['date'];
                return $item;
            });

        return response()->json([
            'statut' => 1,
            'data' => $sortedTimeline,
        ]);
    }

    /**
     * POST /api/next/merge_customers
     */
    public function merge_customers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'primary_id' => 'required',
            'secondary_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $primaryId = $request->input('primary_id');
        $secondaryId = $request->input('secondary_id');

        $accountUser = getAccountUser();
        $accountId = $accountUser ? $accountUser->account_id : null;

        $primary = is_numeric($primaryId)
            ? Customer::where('account_id', $accountId)->find($primaryId)
            : $this->resolveCustomer(new Request(['phoneNumber' => $primaryId]));

        $secondary = is_numeric($secondaryId)
            ? Customer::where('account_id', $accountId)->find($secondaryId)
            : $this->resolveCustomer(new Request(['phoneNumber' => $secondaryId]));

        if (!$primary || !$secondary) {
            return response()->json(['statut' => 0, 'message' => 'One or both customers not found.'], 404);
        }

        if ($primary->id === $secondary->id) {
            return response()->json(['statut' => 0, 'message' => 'Cannot merge a customer into itself.'], 422);
        }

        DB::beginTransaction();
        try {
            CustomerIdentityService::mergeCustomerInto($primary, $secondary);
            DB::commit();

            return response()->json([
                'statut' => 1,
                'message' => 'Customers merged successfully. Secondary customer has been archived.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'statut' => 0,
                'message' => 'Merge failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/next/deduplicate_customers
     */
    public function deduplicate_customers(Request $request)
    {
        $accountUser = getAccountUser();
        $accountId = $accountUser ? $accountUser->account_id : null;

        if (!$accountId) {
            return response()->json(['statut' => 0, 'message' => 'Account context not found.'], 422);
        }

        $stats = CustomerIdentityService::deduplicateAccount($accountId);

        return response()->json([
            'statut' => 1,
            'data' => $stats,
            'message' => 'Customer deduplication completed successfully.',
        ]);
    }

    /**
     * POST /api/next/update_order
     * 
     * Body: id, name, phoneNumber, city_id, address, products[{id,quantity,attributes}], final_price, brand_source_id, discount, carrier_price, note
     * Response: {statut, message}
     */
    public function update_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:orders,id',
            'name' => 'nullable|string|max:255',
            'phoneNumber' => 'nullable|string|max:255',
            'city_id' => 'nullable|exists:cities,id',
            'address' => 'nullable|string|max:255',
            'products' => 'nullable|array',
            'products.*.id' => 'required_with:products|exists:products,id',
            'products.*.quantity' => 'required_with:products|numeric|min:1',
            'products.*.attributes' => 'nullable|array',
            'products.*.attributes.size_id' => 'nullable|exists:attributes,id',
            'final_price' => 'nullable|numeric',
            'brand_source_id' => 'nullable|exists:brand_source,id',
            'discount' => 'nullable|numeric',
            'carrier_price' => 'nullable|numeric',
            'note' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ], 422);
        }

        $accountUser = getAccountUser();
        $accountId = $accountUser ? $accountUser->account_id : null;

        // Verify order ownership
        $order = Order::where('id', $request->input('id'))
            ->where('account_id', $accountId)
            ->first();

        if (!$order) {
            return response()->json([
                'statut' => 0,
                'message' => 'Order not found or does not belong to your account.'
            ], 404);
        }

        $updatePayload = [
            'id' => $order->id,
        ];

        // Direct order fields mapping
        if ($request->has('warehouse_id')) {
            $updatePayload['warehouse_id'] = $request->input('warehouse_id');
        }
        if ($request->has('brand_source_id')) {
            $updatePayload['brand_source_id'] = $request->input('brand_source_id');
        }
        if ($request->has('discount')) {
            $updatePayload['discount'] = $request->input('discount');
        }
        if ($request->has('carrier_price')) {
            $updatePayload['carrier_price'] = $request->input('carrier_price');
        }
        if ($request->has('note')) {
            $updatePayload['note'] = $request->input('note');
        }

        // Handle customer updates (delegated to OrderController internally)
        $customerPayload = [];
        if ($request->has('name')) {
            $customerPayload['name'] = $request->input('name');
        }
        if ($request->has('phoneNumber')) {
            $customerPayload['phones'] = [
                [
                    'title' => $request->input('phoneNumber'),
                    'principal' => true,
                    'phoneTypes' => [1],
                ]
            ];
        }
        if ($request->has('address') || $request->has('city_id')) {
            $addr = [];
            if ($request->has('address')) {
                $addr['title'] = $request->input('address');
            }
            if ($request->has('city_id')) {
                $addr['city_id'] = $request->input('city_id');
            }
            $addr['principal'] = true;
            $customerPayload['addresses'] = [$addr];
        }
        if (!empty($customerPayload)) {
            $updatePayload['customer'] = $customerPayload;
        }

        // Handle products updates by computing active/inactive/update differences
        if ($request->has('products')) {
            $productsInput = $request->input('products', []);
            $totalDefaultPrice = 0;
            $totalQuantity = 0;
            $productsTemp = [];

            // Fetch account users context to check permissions on products
            $accountUsers = Account::find($accountId)?->accountUsers->pluck('id')->toArray() ?? [];

            foreach ($productsInput as $item) {
                $product = Product::with('productVariationAttributes.variationAttribute.childVariationAttributes.attribute.typeAttribute')
                    ->where('id', $item['id'])
                    ->whereIn('account_user_id', $accountUsers)
                    ->first();

                if (!$product) {
                    return response()->json([
                        'statut' => 0,
                        'data' => ["products" => ["Product ID {$item['id']} is invalid or does not belong to your account."]]
                    ], 422);
                }

                $qty = intval($item['quantity'] ?? 1);
                $defaultPrice = floatval($product->price->first()?->price ?? $product->sellingprice ?? 0);
                $totalDefaultPrice += $defaultPrice * $qty;
                $totalQuantity += $qty;

                // Resolve attributes array
                $attributes = [];
                if (isset($item['attributes']) && is_array($item['attributes']) && !empty($item['attributes'])) {
                    $attributes = collect($item['attributes'])
                        ->filter(function ($val) {
                            return is_numeric($val);
                        })
                        ->map(function ($val) {
                            return (int) $val;
                        })
                        ->values()
                        ->toArray();
                }

                if (empty($attributes)) {
                    $firstPva = ProductVariationAttribute::where('product_id', $product->id)->first();
                    if ($firstPva && $firstPva->variationAttribute) {
                        $attributes = $firstPva->variationAttribute->childVariationAttributes->pluck('attribute_id')->toArray();
                    }
                }

                // Check if matching PVA exists
                $matchedPva = null;
                foreach ($product->productVariationAttributes as $pva) {
                    $pvaAttributes = $pva->variationAttribute->childVariationAttributes
                        ->pluck('attribute_id')
                        ->map(function ($id) {
                            return (int) $id;
                        })
                        ->sort()
                        ->values()
                        ->all();

                    $sortedSelected = collect($attributes)->sort()->values()->all();

                    if ($pvaAttributes === $sortedSelected) {
                        $matchedPva = $pva;
                        break;
                    }
                }

                if (!$matchedPva) {

                    $requiresSize = false;
                    $requiresColor = false;

                    foreach ($product->productVariationAttributes as $pva) {
                        if ($pva->variationAttribute && $pva->variationAttribute->childVariationAttributes) {
                            foreach ($pva->variationAttribute->childVariationAttributes as $child) {
                                if ($child->attribute && $child->attribute->typeAttribute) {
                                    $typeTitle = strtolower($child->attribute->typeAttribute->title);
                                    if ($typeTitle === 'size' || $typeTitle === 'taille') {
                                        $requiresSize = true;
                                    } elseif ($typeTitle === 'color' || $typeTitle === 'couleur') {
                                        $requiresColor = true;
                                    }
                                }
                            }
                        }
                    }

                    $hasSize = false;
                    $hasColor = false;

                    if (isset($item['attributes']) && is_array($item['attributes'])) {
                        if (isset($item['attributes']['size_id']) && is_numeric($item['attributes']['size_id'])) {
                            $hasSize = true;
                        }
                        if (isset($item['attributes']['color_id']) && is_numeric($item['attributes']['color_id'])) {
                            $hasColor = true;
                        }
                    }

                    if (!$hasSize || !$hasColor) {
                        if (!empty($attributes)) {
                            $selectedAttributes = \App\Models\Attribute::with('typeAttribute')->whereIn('id', $attributes)->get();
                            foreach ($selectedAttributes as $attr) {
                                if ($attr->typeAttribute) {
                                    $typeTitle = strtolower($attr->typeAttribute->title);
                                    if ($typeTitle === 'size' || $typeTitle === 'taille') {
                                        $hasSize = true;
                                    } elseif ($typeTitle === 'color' || $typeTitle === 'couleur') {
                                        $hasColor = true;
                                    }
                                }
                            }
                        }
                    }

                    $missingErrors = [];
                    if ($requiresSize && !$hasSize) {
                        $missingErrors[] = "size_id";
                    }
                    if ($requiresColor && !$hasColor) {
                        $missingErrors[] = "color_id";
                    }

                    if (!empty($missingErrors)) {
                        $missingDescriptor = implode(' and ', $missingErrors);
                        return response()->json([
                            'statut' => 0,
                            'data' => ["products" => ["Product ID {$item['id']} with {$missingDescriptor} attributes does not exist."]]
                        ], 422);
                    }

                    return response()->json([
                        'statut' => 0,
                        'data' => ["products" => ["Product ID {$item['id']} with specified attributes does not exists."]]
                    ], 422);
                }

                $productsTemp[] = [
                    'product' => $product,
                    'pva' => $matchedPva,
                    'qty' => $qty,
                    'default_price' => $defaultPrice,
                    'attributes' => $attributes,
                    'offers' => $item['offers'] ?? [],
                    'item' => $item,
                ];
            }

            // Apply scaling if final_price is explicitly provided
            $finalPrice = $request->input('final_price');
            if ($finalPrice !== null) {
                $finalPrice = floatval($finalPrice);
                if ($totalDefaultPrice > 0) {
                    $scale = $finalPrice / $totalDefaultPrice;
                    foreach ($productsTemp as $idx => $p) {
                        $productsTemp[$idx]['price'] = round($p['default_price'] * $scale, 2);
                    }
                } else {
                    $pricePerUnit = $totalQuantity > 0 ? round($finalPrice / $totalQuantity, 2) : 0;
                    foreach ($productsTemp as $idx => $p) {
                        $productsTemp[$idx]['price'] = $pricePerUnit;
                    }
                }
            } else {
                foreach ($productsTemp as $idx => $p) {
                    $productsTemp[$idx]['price'] = isset($p['item']['price']) ? floatval($p['item']['price']) : $p['default_price'];
                }
            }

            // We will manually sync products to ensure complete replacement.
            $order->loadMissing('orderPvas');
            $syncData = [];
            foreach ($productsTemp as $p) {
                $pvaId = $p['pva']->id;
                
                if (isset($syncData[$pvaId])) {
                    $syncData[$pvaId]['quantity'] += $p['qty'];
                } else {
                    $existingOrderPva = $order->orderPvas?->where('product_variation_attribute_id', $pvaId)->first();

                    $syncData[$pvaId] = [
                        'quantity' => $p['qty'],
                        'price' => $p['price'],
                        'initial_price' => $p['default_price'],
                        'realprice' => $existingOrderPva?->realprice ?? 0,
                        'discount' => 0,
                        'order_status_id' => $order->order_status_id,
                        'account_user_id' => getAccountUser()->id ?? $accountId,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }
            }

            // Begin transaction to ensure atomicity
            DB::beginTransaction();
            try {
                // Remove products from update payload so OrderController::update doesn't process them
                unset($updatePayload['productsToInactive']);
                unset($updatePayload['productsToActive']);
                unset($updatePayload['productsToUpdate']);

                // Call the static OrderController update method for non-product fields
                $updateResponse = OrderController::update(new Request([$updatePayload]), $local = 0);
                $responseData = $updateResponse->getData(true);

                if (!isset($responseData['statut']) || $responseData['statut'] != 1) {
                    throw new \Exception($responseData['message'] ?? 'Failed to update order details');
                }

                // Completely replace the product list using sync()
                $order->productVariationAttributes()->sync($syncData);

                DB::commit();

                // Load fresh data to return the final state
                $order->refresh();
                $order->load(['orderStatus', 'activePvas.product', 'activePvas.variationAttribute.childVariationAttributes.attribute']);

                $finalProducts = $order->activePvas->map(function ($pva) {
                    $attributes = $pva->variationAttribute?->childVariationAttributes?->filter(fn ($child) => !empty($child->attribute))->map(function ($child) {
                        return [
                            'id' => $child->attribute->id,
                            'title' => $child->attribute->title
                        ];
                    })->values()->toArray() ?? [];

                    return [
                        'id' => $pva->product_id,
                        'name' => $pva->product?->title ?? '',
                        'quantity' => $pva->pivot->quantity,
                        'price' => floatval($pva->pivot->price),
                        'attributes' => $attributes
                    ];
                })->toArray();

                return response()->json([
                    'statut' => 1,
                    'message' => 'Order updated successfully',
                    'order' => [
                        'order_id' => $order->id,
                        'status' => $order->orderStatus?->title,
                        'final_price' => floatval($order->activePvas->sum(function($pva) { return $pva->pivot->price * $pva->pivot->quantity; })),
                        'products' => $finalProducts
                    ]
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json([
                    'statut' => 0,
                    'message' => 'Failed to update order',
                    'errors' => $e->getMessage()
                ], 422);
            }
        } else {
            // No products to update, just update the other fields
            $updateResponse = OrderController::update(new Request([$updatePayload]), $local = 0);
            $responseData = $updateResponse->getData(true);

            if (isset($responseData['statut']) && $responseData['statut'] == 1) {
                $order->refresh();
                return response()->json([
                    'statut' => 1,
                    'message' => 'Order updated successfully',
                    'order' => [
                        'order_id' => $order->id,
                        'status' => $order->orderStatus?->title
                    ]
                ]);
            } else {
                return response()->json([
                    'statut' => 0,
                    'message' => 'Failed to update order',
                    'errors' => $responseData['data'] ?? null
                ], 422);
            }
        }
    }

    /**
     * POST /api/next/cancel_order
     * 
     * Body: id
     * Response: {statut, message}
     */
    public function cancel_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:orders,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ], 422);
        }

        $accountUser = getAccountUser();
        $accountId = $accountUser ? $accountUser->account_id : null;

        // Verify order ownership
        $order = Order::where('id', $request->input('id'))
            ->where('account_id', $accountId)
            ->first();

        if (!$order) {
            return response()->json([
                'statut' => 0,
                'message' => 'Order not found or does not belong to your account.'
            ], 404);
        }

        // Format update payload with cancellation comment ID 77 (Commande Annulée)
        $updatePayload = [
            'id' => $order->id,
            'comment' => [
                'id' => 77, // Comment ID 77 represents "Commande Annulée"
                'title' => 'Commande Annulée',
            ],
        ];

        // Call the static OrderController update method
        $updateResponse = OrderController::update(new Request([$updatePayload]), $local = 0);
        $responseData = $updateResponse->getData(true);

        if (isset($responseData['statut']) && $responseData['statut'] == 1) {
            return response()->json(['statut' => 1, 'message' => 'Order deleted successfully']);
        } else {
            return response()->json([
                'statut' => 0,
                'message' => 'Failed to delete order',
                'errors' => $responseData['data'] ?? null
            ], 422);
        }
    }

    /**
     * Group product variation attributes by attribute type.
     */
    private function getGroupedAttributes($product)
    {
        $attributesGrouped = [];
        $attributeLatestImageId = [];

        foreach ($product->activePvas as $pva) {
            $latestPvaImage = ($pva->images && $pva->images->isNotEmpty())
                ? $pva->images->sortByDesc('id')->first()
                : null;
            $pvaImageUrl = $latestPvaImage ? asset($latestPvaImage->photo_dir . $latestPvaImage->photo) : null;
            $pvaImageId = $latestPvaImage ? $latestPvaImage->id : 0;

            if ($pva->variationAttribute) {
                foreach ($pva->variationAttribute->childVariationAttributes as $childVa) {
                    $attribute = $childVa->attribute;
                    if ($attribute) {
                        $typeTitle = $attribute->typeAttribute ? $attribute->typeAttribute->title : 'other';
                        $key = strtolower(trim($typeTitle));

                        if (!isset($attributesGrouped[$key][$attribute->id])) {
                            $attributesGrouped[$key][$attribute->id] = [
                                'id' => $attribute->id,
                                'title' => $attribute->title,
                            ];
                        }

                        if ($key === 'colors' || $key === 'color' || $key === 'couleur') {
                            if ($pvaImageUrl) {
                                $currentHighestId = $attributeLatestImageId[$attribute->id] ?? -1;
                                if ($pvaImageId > $currentHighestId) {
                                    $attributesGrouped[$key][$attribute->id]['image'] = $pvaImageUrl;
                                    $attributeLatestImageId[$attribute->id] = $pvaImageId;
                                }
                            } elseif (!array_key_exists('image', $attributesGrouped[$key][$attribute->id])) {
                                $attributesGrouped[$key][$attribute->id]['image'] = null;
                            }
                        }
                    }
                }
            }
        }

        foreach ($attributesGrouped as $key => $values) {
            $attributesGrouped[$key] = array_values($values);
        }

        return $attributesGrouped;
    }

    /**
     * Get product attributes (same response data format as /filterselect/product_attributes)
     * GET /api/next/product_attributes/{id?}
     * GET /api/next/get_product_attributes/{id?}
     */
    public function product_attributes(Request $request, $id = null)
    {
        $productId = $id ?? $request->input('id') ?? $request->input('product_id');

        if ($productId) {
            $product = Product::find($productId);
            if (!$product) {
                return response()->json([
                    'statut' => 0,
                    'type' => 'product_attributes',
                    'message' => 'Product not found',
                    'data' => []
                ], 404);
            }

            $pvas = $product->activePvas->flatMap(function ($pva) {
                if (!$pva->variationAttribute)
                    return collect();
                return $pva->variationAttribute->childVariationAttributes->map(function ($variationAttribute) {
                    return [
                        "id" => $variationAttribute->attribute->id ?? null,
                        "code" => $variationAttribute->attribute->code ?? '',
                        "title" => $variationAttribute->attribute->title ?? '',
                        "typeId" => $variationAttribute->attribute->typeAttribute->id ?? null,
                        "typeTitle" => $variationAttribute->attribute->typeAttribute->title ?? ''
                    ];
                })->filter(function ($item) {
                    return $item['id'] !== null; });
            });
        } else {
            $products = Product::where('statut', 1)->get();
            $pvas = $products->flatMap(function ($product) {
                return $product->productVariationAttributes->flatMap(function ($pva) {
                    if ($pva->variationAttribute) {
                        return $pva->variationAttribute->childVariationAttributes->map(function ($variationAttribute) {
                            return [
                                "id" => $variationAttribute->attribute->id ?? null,
                                "code" => $variationAttribute->attribute->code ?? '',
                                "title" => $variationAttribute->attribute->title ?? '',
                                "typeId" => $variationAttribute->attribute->typeAttribute->id ?? null,
                                "typeTitle" => $variationAttribute->attribute->typeAttribute->title ?? ''
                            ];
                        })->filter(function ($item) {
                            return $item['id'] !== null; });
                    }
                    return collect();
                });
            });
        }

        $datas = [];
        foreach ($pvas as $pva) {
            if (isset($pva['id']) && $pva['id']) {
                $datas[$pva['id']] = [
                    'id' => $pva['id'],
                    'code' => $pva['code'] ? $pva['code'] : $pva['title'],
                    'attribute_type' => $pva['typeTitle'],
                    'title' => $pva['title']
                ];
            }
        }

        $sorted = collect($datas)->sortBy('attribute_type')->values();

        return response()->json([
            "statut" => 1,
            "type" => "product_attributes",
            "data" => $sorted->toArray()
        ]);
    }
}
