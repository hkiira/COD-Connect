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
            $pva = ProductVariationAttribute::where('product_id', $product->id)->first();
            if (!$pva) {
                return response()->json([
                    'statut' => 0,
                    'data' => ["products" => ["Product ID {$item['id']} does not have any variation attributes defined."]]
                ], 422);
            }

            $attributes = [];
            if ($pva->variationAttribute) {
                $attributes = $pva->variationAttribute->childVariationAttributes->pluck('attribute_id')->toArray();
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
            'order_status_id' => 4,
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

        if ($brandSourceId) {
            $query->whereHas('brandSources', function ($q) use ($brandSourceId) {
                $q->where('brand_source.id', $brandSourceId);
            });
        }

        if ($searchName !== null && trim(strval($searchName)) !== '') {
            $query->where('title', 'like', "%{$searchName}%");
        }

        $products = $query->with(['price', 'principalImage', 'images', 'offers'])->get();

        $formattedProducts = $products->map(function ($product) {
            $principalImg = $product->principalImage->first() ?? $product->images->first();
            $principal_image_url = $principalImg ? asset($principalImg->photo_dir . $principalImg->photo) : null;

            $images_urls = $product->images->map(function ($img) {
                return asset($img->photo_dir . $img->photo);
            })->toArray();

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

            return [
                'id' => $product->id,
                'name' => $product->title,
                'code' => $product->code,
                'principal_image' => $principal_image_url,
                'images' => $images_urls,
                'price' => $price,
                'discount_price' => $discount_price,
            ];
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

        $product = Product::where('title', 'like', "%{$name}%")
            ->where('statut', 1)
            ->with(['price', 'principalImage', 'images', 'offers'])
            ->first();

        if (!$product) {
            return response()->json(['statut' => 0, 'message' => 'Product not found.'], 404);
        }

        $principalImg = $product->principalImage->first() ?? $product->images->first();
        $principal_image_url = $principalImg ? asset($principalImg->photo_dir . $principalImg->photo) : null;

        $images_urls = $product->images->map(function ($img) {
            return asset($img->photo_dir . $img->photo);
        })->toArray();

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

        return response()->json([
            'id' => $product->id,
            'name' => $product->title,
            'code' => $product->code,
            'principal_image' => $principal_image_url,
            'images' => $images_urls,
            'price' => $price,
            'discount_price' => $discount_price,
            'offers' => $offers,
        ]);
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

        if ($brandSourceId) {
            $query->whereHas('brandSources', function ($q) use ($brandSourceId) {
                $q->where('brand_source.id', $brandSourceId);
            });
        }

        if ($search !== null && trim(strval($search)) !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('id', $search)
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $products = $query->with(['price', 'principalImage', 'images', 'offers'])->get();

        $formattedProducts = $products->map(function ($product) {
            $principalImg = $product->principalImage->first() ?? $product->images->first();
            $principal_image_url = $principalImg ? asset($principalImg->photo_dir . $principalImg->photo) : null;

            $images_urls = $product->images->map(function ($img) {
                return asset($img->photo_dir . $img->photo);
            })->toArray();

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

            return [
                'id' => $product->id,
                'name' => $product->title,
                'code' => $product->code,
                'principal_image' => $principal_image_url,
                'images' => $images_urls,
                'price' => $price,
                'discount_price' => $discount_price,
                'offers' => $offers,
            ];
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
}
