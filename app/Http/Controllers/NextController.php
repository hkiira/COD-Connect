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
                $q->where('brand_source.id', $brandSourceId);
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
            'activePvas.images'
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
            'activePvas.images'
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
                $q->where('brand_source.id', $brandSourceId);
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
            'activePvas.images'
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
     * GET /api/get_customer
     * 
     * Params: phoneNumber
     * Response: {customer:{name,phoneNumber,city,address},orders:[{products[id,name,price],id,status,create_at,shippement:{delivery_men,history:[{}]}}]}
     */
    public function get_customer(Request $request)
    {
        $phoneNumber = $request->input('phoneNumber');

        if (!$phoneNumber) {
            return response()->json(['statut' => 0, 'message' => 'phoneNumber parameter is required.'], 400);
        }

        $formattedPhone = formatPhoneNumber($phoneNumber);
        $phone = Phone::where('title', $formattedPhone)->first();

        if (!$phone && $phoneNumber !== $formattedPhone) {
            $phone = Phone::where('title', $phoneNumber)->first();
        }

        if (!$phone) {
            // Clean phone backup search
            $cleaned = preg_replace('/\D/', '', $phoneNumber);
            if (!empty($cleaned)) {
                $phone = Phone::whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(title, ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?", ["%{$cleaned}%"])->first();
            }
        }

        if (!$phone) {
            return response()->json(['statut' => 0, 'message' => 'Customer phone not found.'], 404);
        }

        $customer = $phone->customers()->first();

        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'Customer not found.'], 404);
        }

        // Retrieve customer address and city
        $addressObj = $customer->addresses()->where('addressables.statut', 1)->first() ?? $customer->addresses()->first();
        $city = $addressObj?->city?->title ?? null;
        $address = $addressObj?->title ?? null;

        // Fetch customer orders with matching relationship models
        $orders = $customer->orders()->with([
            'orderStatus',
            'pickup.carrier',
            'shipment.carrier',
            'productVariationAttributes.product',
            'orderComments.orderStatus'
        ])->get();

        $formattedOrders = $orders->map(function ($order) {
            $products = $order->productVariationAttributes->map(function ($pva) {
                $attributes = $pva->variationAttribute?->childVariationAttributes?->map(function ($child) {
                    return $child->attribute->title;
                })->toArray() ?? [];

                return [
                    'id' => $pva->product_id,
                    'name' => $pva->product->title . (!empty($attributes) ? ' ' . implode('-', $attributes) : ''),
                    'price' => floatval($pva->pivot->price),
                ];
            })->toArray();

            $delivery_men = $order->pickup?->carrier?->title ?? $order->shipment?->carrier?->title ?? null;

            $history = $order->orderComments->map(function ($orderComment) {
                return [
                    'status' => $orderComment->orderStatus?->title ?? $orderComment->title,
                    'created_at' => $orderComment->created_at?->toDateTimeString(),
                    'note' => $orderComment->title,
                ];
            })->toArray();

            return [
                'id' => $order->id,
                'status' => $order->orderStatus?->title,
                'create_at' => $order->created_at?->toDateTimeString(),
                'products' => $products,
                'shippement' => [ // double p spelling requested
                    'delivery_men' => $delivery_men,
                    'history' => $history,
                ],
                'shipment' => [ // safety duplicate
                    'delivery_men' => $delivery_men,
                    'history' => $history,
                ],
            ];
        })->toArray();

        return response()->json([
            'customer' => [
                'name' => $customer->name,
                'phoneNumber' => $phoneNumber,
                'city' => $city,
                'address' => $address,
            ],
            'orders' => $formattedOrders,
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
                    foreach ($productsTemp as &$p) {
                        $p['price'] = round($p['default_price'] * $scale, 2);
                    }
                } else {
                    $pricePerUnit = $totalQuantity > 0 ? round($finalPrice / $totalQuantity, 2) : 0;
                    foreach ($productsTemp as &$p) {
                        $p['price'] = $pricePerUnit;
                    }
                }
            } else {
                foreach ($productsTemp as &$p) {
                    $p['price'] = isset($p['item']['price']) ? floatval($p['item']['price']) : $p['default_price'];
                }
            }

            // Fetch active PVAs from the database for comparison
            $activePvas = $order->activePvas;
            $matchedIncoming = [];

            $productsToUpdate = [];
            $productsToActive = [];
            $productsToInactive = [];

            foreach ($productsTemp as $p) {
                $matchedPva = $p['pva'];
                $existingPva = $activePvas->first(function ($apva) use ($matchedPva) {
                    return $apva->id === $matchedPva->id;
                });

                if ($existingPva) {
                    $orderPvaId = $existingPva->pivot->id;
                    $productsToUpdate[] = [
                        'id' => $orderPvaId,
                        'quantity' => $p['qty'],
                        'price' => $p['price'],
                        'discount' => 0,
                    ];
                    $matchedIncoming[] = $orderPvaId;
                } else {
                    $productsToActive[] = [
                        'id' => $p['product']->id,
                        'attributes' => $p['attributes'],
                        'quantity' => $p['qty'],
                        'price' => $p['price'],
                        'discount' => 0,
                        'offers' => $p['offers'],
                    ];
                }
            }

            foreach ($activePvas as $apva) {
                $orderPvaId = $apva->pivot->id;
                if (!in_array($orderPvaId, $matchedIncoming)) {
                    $productsToInactive[] = $orderPvaId;
                }
            }

            $updatePayload['productsToInactive'] = $productsToInactive;
            $updatePayload['productsToActive'] = $productsToActive;
            $updatePayload['productsToUpdate'] = $productsToUpdate;
        }

        // Call the static OrderController update method
        $updateResponse = OrderController::update(new Request([$updatePayload]), $local = 0);
        $responseData = $updateResponse->getData(true);

        if (isset($responseData['statut']) && $responseData['statut'] == 1) {
            return response()->json(['statut' => 1, 'message' => 'Order updated successfully']);
        } else {
            return response()->json([
                'statut' => 0,
                'message' => 'Failed to update order',
                'errors' => $responseData['data'] ?? null
            ], 422);
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

        foreach ($product->activePvas as $pva) {
            $pvaImageUrl = null;
            if ($pva->images && $pva->images->isNotEmpty()) {
                $lastImage = $pva->images->last();
                $pvaImageUrl = asset($lastImage->photo_dir . $lastImage->photo);
            }

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

                        if ($key === 'colors') {
                            if ($pvaImageUrl) {
                                $attributesGrouped[$key][$attribute->id]['image'] = $pvaImageUrl;
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
}
