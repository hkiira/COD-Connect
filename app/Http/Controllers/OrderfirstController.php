<?php

namespace App\Http\Controllers;

use niklasravnsborg\LaravelPdf\Facades\Pdf as PDF;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use App\Models\Customer;
use App\Models\Source;
use App\Models\CustomerType;
use App\Models\City;
use App\Models\Pickup;
use App\Models\Region;
use App\Models\Comment;
use App\Models\Country;
use App\Models\Sector;
use App\Models\ProductVariationAttribute;
use App\Models\OrderPva;
use App\Models\Offer;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use App\Models\Supplier;
use App\Models\Brand;
use App\Models\Taxonomy;
use App\Models\Phone;
use App\Models\Order;
use App\Models\Product;
use App\Models\Account;
use Illuminate\Support\Facades\Validator;

class OrderfirstController extends Controller
{
    public function index(Request $request)
    {
                $cityList = Customer::get()
                    ->map(function($customer) {
                        $address = $customer->addresses->first();
                        return $address ? $address->city ? $address->city->title : null : null;
                    })
                    ->filter(); // Remove nulls

                $cityCounts = $cityList->countBy(); // Laravel collection: city => count
                $orderedCities = $cityCounts->sortDesc();

                $result = $orderedCities->map(function($count, $city) {
                    return ['city' => $city, 'count' => $count];
                })->values();

                return $result;

        $request = collect($request->query())->toArray();
        $filter=[];
        if (isset($request['pagination']) ) {
            $filter['limit']=isset($request['pagination']['per_page'])?$request['pagination']['per_page']:10;
            $filter['page']=isset($request['pagination']['current_page'])?$request['pagination']['current_page']:0;
            $filter['sort']['by']=isset($request['sort']['column'])?$request['sort']['column']:'created_at';
            $filter['sort']['order']=isset($request['sort']['order'])?$request['sort']['order']:'desc';
        }


        $ordersQuery = Order::orderBy($filter['sort']['by'], $filter['sort']['order']);

        // Define filter mapping: key => [type, path]
        $filterMap = [
            'products' => ['relation', 'activeOrderPvas.productVariationAttribute.product', 'id'],
            'warehouses' => ['column', 'warehouse_id'],
            'categories' => ['relation', 'activeOrderPvas.productVariationAttribute.product.taxonomies', 'id'],
            'brands' => ['relation', 'brandSource.brand', 'id'],
            'sources' => ['relation', 'brandSource.source', 'id'],
            'customer_types' => ['relation', 'customer', 'customer_type_id'],
            'cities' => ['relation', 'customer.addresses', 'city_id'],
            'regions' => ['relation', 'customer.addresses.city.region', 'id'],
            'countries' => ['relation', 'customer.addresses.city.region.country', 'id'],
            'status' => ['column', 'order_status_id'],
            'sectors' => ['column', 'sector_id'],
        ];


        foreach ($filterMap as $key => $info) {
            if (!empty($request[$key]) && is_array($request[$key])) {
                if ($info[0] === 'column') {
                    $ordersQuery = $ordersQuery->whereIn($info[1], $request[$key]);
                } elseif ($info[0] === 'relation') {
                    $relation = $info[1];
                    $column = $info[2];
                    $ordersQuery = $ordersQuery->whereHas($relation, function ($q) use ($request, $key, $column) {
                        $q->whereIn($column, $request[$key]);
                    });
                }
            }
        }

        // Add search filter for code, customer name, customer phone, and address title
        if (!empty($request['search']) && is_string($request['search'])) {
            $search = $request['search'];
            $ordersQuery = $ordersQuery->where(function ($query) use ($search) {
                $query->where('code', 'like', "%$search%")
                    ->orWhereHas('customer', function ($q) use ($search) {
                        $q->where('name', 'like', "%$search%")
                            ->orWhereHas('phones', function ($q2) use ($search) {
                                $q2->where('title', 'like', "%$search%") ;
                            })
                            ->orWhereHas('addresses', function ($q3) use ($search) {
                                $q3->where('title', 'like', "%$search%") ;
                            });
                    });
            });
        }

        $total = $ordersQuery->count();
        $orders = $ordersQuery
            ->skip($filter['page'] * $filter['limit'])
            ->take($filter['limit'])
            ->get();

        $datas = $orders->map(function ($data) {
            $orderData = $data->only('id', 'code', 'shipping_code', 'comment', 'order_id', 'created_at', 'updated_at');
            // Add score to orderData
            $orderData['score'] = $data->score;
            if (!$orderData['shipping_code'])
                $orderData['shipping_code'] = "";
            $orderData['can_change'] = in_array($data->order_status_id, [1, 2, 3, 4, 5]) ? true : false;
            $orderData['user'] = $data->userCreated->map(function ($user) {
                return [
                    "id" => $user->id,
                    "firstname" => $user->user->firstname,
                    "lastname" => $user->user->lastname,
                    "images" => $user->user->images,
                ];
            });
            $orderData['comments'] = $data->lastOrderComments->map(function ($comment) {
                $data = [
                    "id" => $comment->id,
                    "comment" => $comment->comment_id."",
                    "title" => $comment->title,
                    "created_at" => $comment->created_at,
                    "user" => $comment->accountUser->user,
                    "status" => $comment->orderStatus->only('id', 'title', 'statut'),
                ];
                $data["status"]['created_at'] = $comment->created_at;
                return $data;
            });
            $orderData['customer'] = $data->customer->only('id', 'name', 'images');
            $orderData['customer']['phones'] = $data->customer->phones->map(function ($phone) {
                return $phone->only('id', 'title');
            });
            $orderData['customer']['address'] = $data->customer->addresses->map(function ($address) {
                return $address->only('id', 'title', 'city');
            });
            $totalOrder = 0;
            $orderData['products'] = $data->activeOrderPvas->map(function ($actfOrderPva) use (&$totalOrder) {
                $totalOrder += $actfOrderPva->price * $actfOrderPva->quantity;
                $attributes = $actfOrderPva->ProductVariationAttribute->variationAttribute->childVariationAttributes->map(function ($child) {
                    return $child->attribute->code;
                })->toArray();
                $productInfo = [
                    'id' => $actfOrderPva->productVariationAttribute->product->id,
                    'order_pva' => $actfOrderPva->id,
                    'price' => $actfOrderPva->price,
                    'quantity' => $actfOrderPva->quantity,
                    'images' => $actfOrderPva->productVariationAttribute->product->images->sortByDesc('created_at')->values(),
                    'productType' => $actfOrderPva->productVariationAttribute->product->productType,
                    'product' => $actfOrderPva->productVariationAttribute->product->title . " " . implode('-', $attributes),
                    'reference' => $actfOrderPva->productVariationAttribute->product->reference,
                    'productsize' => $actfOrderPva->productVariationAttribute->variationAttribute->id,
                    'attributes' => $actfOrderPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($child) {
                        return [
                            "id" => $child->attribute->id,
                            "title" => $child->attribute->title,
                            "typeAttribute" => $child->attribute->typeAttribute->title,
                        ];
                    }),
                ];
                return $productInfo;
            });
            $orderData['total'] = $totalOrder;
            $orderData['discount'] = $data->discount;
            $orderData['carrier_price'] = $data->carrier_price;
            $orderData['status'] = $data->orderStatus->only('id', 'title');
            $orderData['brand'] = $data->brandSource->brand->only('id', 'title', 'images');
            $orderData['carrier'] = ($data->pickup) ? $data->pickup->carrier->only('id', 'title', 'images') : null;
            $orderData['source'] = $data->brandSource->source->only('id', 'title', 'images');
            return $orderData;
        });

        return [
            'statut' => 1,
            'data' => $datas,
            'per_page' => (string)($filter['limit'] ?? 10),
            'current_page' => (int)($filter['page'] ?? 0) + 1,
            'total' => $total
        ];
    }
    // public function index(Request $request)
    // {
    //     $request = collect($request->query())->toArray();
    //     $searchIds = [];
    //     if (isset($request['products']) && array_filter($request['products'], function ($value) {
    //         return $value !== null;
    //     })) {
    //         foreach ($request['products'] as $productId) {
    //             if (Product::find($productId))
    //                 $searchIds = array_merge($searchIds, Product::find($productId)->orders->pluck('id')->toArray());
    //         }
    //         $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
    //     }
    //     if (isset($request['warehouses']) && array_filter($request['warehouses'], function ($value) {
    //         return $value !== null;
    //     })) {
    //         foreach ($request['warehouses'] as $warehouseId) {
    //             if (Warehouse::find($warehouseId))
    //                 $searchIds = array_merge($searchIds, Warehouse::find($warehouseId)->orders->pluck('id')->toArray());
    //         }
    //         $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
    //     }
    //     if (isset($request['categories']) && array_filter($request['categories'], function ($value) {
    //         return $value !== null;
    //     })) {
    //         foreach ($request['categories'] as $taxonomyId) {
    //             if (Taxonomy::find($taxonomyId))
    //                 $searchIds = array_merge($searchIds, Taxonomy::find($taxonomyId)->orders->pluck('id')->toArray());
    //         }
    //         $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
    //     }
    //     if (isset($request['brands']) && array_filter($request['brands'], function ($value) {
    //         return $value !== null;
    //     })) {
    //         foreach ($request['brands'] as $brandId) {
    //             if (Brand::find($brandId) && Brand::find($brandId)->orders)
    //                 $searchIds = array_merge($searchIds, Brand::find($brandId)->orders->pluck('id')->toArray());
    //         }
    //         $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
    //     }
    //     if (isset($request['sources']) && array_filter($request['sources'], function ($value) {
    //         return $value !== null;
    //     })) {
    //         foreach ($request['sources'] as $sourceId) {
    //             if (Source::find($sourceId))
    //                 $searchIds = array_merge($searchIds, Source::find($sourceId)->orders->pluck('id')->toArray());
    //         }
    //         $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
    //     }
    //     if (isset($request['customer_types']) && array_filter($request['customer_types'], function ($value) {
    //         return $value !== null;
    //     })) {
    //         foreach ($request['customer_types'] as $customerTypeId) {
    //             if (CustomerType::find($customerTypeId))
    //                 $searchIds = array_merge($searchIds, CustomerType::find($customerTypeId)->orders->pluck('id')->toArray());
    //         }
    //         $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
    //     }
    //     if (isset($request['cities']) && array_filter($request['cities'], function ($value) {
    //         return $value !== null;
    //     })) {
    //         foreach ($request['cities'] as $cityId) {
    //             if (City::find($cityId))
    //                 $searchIds = array_merge($searchIds, City::find($cityId)->activeAddresses->flatMap(function($activeAddress){
    //                 return $activeAddress->orders->pluck('id');
    //             })->filter()->toArray());
    //         }
    //         $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
    //     }
    //     if (isset($request['regions']) && array_filter($request['regions'], function ($value) {
    //         return $value !== null;
    //     })) {
    //         foreach ($request['regions'] as $regionId) {
    //             if (Region::find($regionId))
    //                 $searchIds = array_merge($searchIds, Region::find($regionId)->orders->pluck('id')->toArray());
    //         }
    //         $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
    //     }
    //     if (isset($request['countries']) && array_filter($request['countries'], function ($value) {
    //         return $value !== null;
    //     })) {
    //         foreach ($request['countries'] as $countryId) {
    //             if (Country::find($countryId))
    //                 $searchIds = array_merge($searchIds, Country::find($countryId)->orders->pluck('id')->toArray());
    //         }
    //         $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
    //     }
    //     if (isset($request['status']) && array_filter($request['status'], function ($value) {
    //         return $value !== null;
    //     })) {
    //         $request['whereArray'] = ['column' => 'order_status_id', 'values' => $request['status']];
    //     }
    //     if (isset($request['sectors']) && array_filter($request['sectors'], function ($value) {
    //         return $value !== null;
    //     })) {
    //         foreach ($request['sectors'] as $sectorId) {
    //             if (Sector::find($sectorId))
    //                 $searchIds = array_merge($searchIds, Sector::find($sectorId)->orders->pluck('id')->toArray());
    //         }
    //         $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
    //     }
    //     $associated = [];
    //     $model = 'App\\Models\\Order';
    //     $request['inAccount'] = ['account_id', getAccountUser()->account_id];
    //     $datas = FilterController::searchs(new Request($request), $model, ['id', 'code'], true, $associated);
    //     $datas['data'] = collect($datas['data'])->map(function ($data) {
    //         $orderData = $data->only('id', 'code', 'shipping_code', 'comment', 'order_id', 'created_at', 'updated_at');
    //         if (!$orderData['shipping_code'])
    //             $orderData['shipping_code'] = "";
    //         $orderData['can_change'] = in_array($data->order_status_id, [1, 2, 3, 4, 5]) ? true : false;
    //         $orderData['user'] = $data->userCreated->map(function ($user) {
    //             return [
    //                 "id" => $user->id,
    //                 "firstname" => $user->user->firstname,
    //                 "lastname" => $user->user->lastname,
    //                 "images" => $user->user->images,
    //             ];
    //         });
    //         $orderData['comments'] = $data->lastOrderComments->map(function ($comment) {
    //             $data = [
    //                 "id" => $comment->id,
    //                 "title" => $comment->title,
    //                 "created_at" => $comment->created_at,
    //                 "user" => $comment->accountUser->user,
    //                 "status" => $comment->orderStatus->only('id', 'title', 'statut'),
    //             ];
    //             $data["status"]['created_at'] = $comment->created_at;
    //             return $data;
    //         });
    //         $orderData['customer'] = $data->customer->only('id', 'name', 'images');
    //         $orderData['customer']['phones'] = $data->customer->phones->map(function ($phone) {
    //             return $phone->only('id', 'title');
    //         });
    //         $orderData['customer']['address'] = $data->customer->addresses->map(function ($address) {
    //             return $address->only('id', 'title', 'city');
    //         });
    //         $total = 0;
    //         //hna fine bqayt
    //         $orderData['products'] = $data->activePvas->map(function ($pva) use (&$total) {
    //             $total += $pva->pivot->price * $pva->pivot->quantity;
    //             $attributes = $pva->variationAttribute->childVariationAttributes->map(function ($child) {
    //                 return $child->attribute->code;
    //             })->toArray();
    //             $productInfo = [
    //                 'id' => $pva->product_id,
    //                 'order_pva' => $pva->pivot->id,
    //                 'price' => $pva->pivot->price,
    //                 'quantity' => $pva->pivot->quantity,
    //                 'images' => $pva->product->images->sortByDesc('created_at')->values(),
    //                 'productType' => $pva->product->productType,
    //                 'product' => $pva->product->title . " " . implode('-', $attributes),
    //                 'reference' => $pva->product->reference,
    //                 'productsize' => $pva->variationAttribute->id,
    //                 'attributes' => $pva->variationAttribute->childVariationAttributes->map(function ($child) {
    //                     return [
    //                         "id" => $child->attribute->id,
    //                         "title" => $child->attribute->title,
    //                         "typeAttribute" => $child->attribute->typeAttribute->title,
    //                     ];
    //                 }),

    //             ];
    //             return $productInfo;
    //         });
    //         $orderData['total'] = $total;
    //         $orderData['discount'] = $data->discount;
    //         $orderData['carrier_price'] = $data->carrier_price;
    //         $orderData['status'] = $data->orderStatus->only('id', 'title');
    //         $orderData['brand'] = $data->brandSource->brand->only('id', 'title', 'images');
    //         $orderData['carrier'] = ($data->pickup) ? $data->pickup->carrier->only('id', 'title', 'images') : null;
    //         $orderData['source'] = $data->brandSource->source->only('id', 'title', 'images');
    //         return $orderData;
    //     });
    //     return $datas;
    // }

    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        if (isset($request['products']['inactive'])) {
            $model = 'App\\Models\\Product';
            //permet de récupérer la liste des regions inactive filtrés
            $request['products']['inactive']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $associated[] = [
                'model' => 'App\\Models\\ProductVariationAttribute',
                'title' => 'productVariationAttributes.variationAttribute.childVariationAttributes.attribute.typeAttribute',
                'search' => false
            ];
            $associated[] = [
                'model' => 'App\\Models\\ProductVariationAttribute',
                'title' => 'productVariationAttributes.pvaPacks.childPvaPacks.ProductVariationAttribute.Product',
                'search' => false
            ];

            $products = FilterController::searchs(new Request($request['products']['inactive']), $model, ['id', 'title'], false, $associated)->map(function ($product) {
                $productData = $product->only('id', 'title');
                $productData['productType'] = $product->productType;
                $productData['images'] = $product->images;
                $productData['productVariations'] = $product->productVariationAttributes->map(function ($productVariationAttribute) use ($product) {
                    if ($product->product_type_id == 1) {
                        $pvaData = ["id" => $productVariationAttribute->id];
                        $pvaData['variations'] = $productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                            if ($childVariationAttribute->attribute->typeAttribute)
                                return [
                                    "id" => $childVariationAttribute->id,
                                    "type" => $childVariationAttribute->attribute->typeAttribute->title,
                                    "value" => $childVariationAttribute->attribute->title
                                ];
                        })->filter();
                        return $pvaData;
                    } else {
                        $pvaData = ["id" => $productVariationAttribute->id];
                        $pvaData['variations'] = $productVariationAttribute->pvaPacks->first()->childPvaPacks->map(function ($childPvaPack) {
                            return ["id" => $childPvaPack->id, "type" => "product", "value" => $childPvaPack->productVariationAttribute->product->title];
                        })->values();
                        return $pvaData;
                    }
                });
                return $productData;
            });
            $data['products']['inactive'] =  HelperFunctions::getPagination($products, $request['products']['inactive']['pagination']['per_page'], $request['products']['inactive']['pagination']['current_page']);
        }

        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }


    public static function store(Request $requests, $isImport = 0)
    {
        $thePva = [];

        if ($isImport == 0) {
            $phoneableType = "App\Models\Customers";
            $validator = Validator::make($requests->except('_method'), [
                '*.warehouse_id' => [
                    'int',
                    function ($attribute, $value, $fail) {
                        $account = getAccountUser()->account_id;
                        $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->first();
                        if (!$warehouse) {
                            $fail("not exist");
                        }
                    },
                ],
                '*.payment_type_id' => 'exists:payment_types,id|max:255',
                '*.payment_method_id' => 'exists:payment_methods,id|max:255',
                '*.customer.id' => [ // Validate title field
                    function ($attribute, $value, $fail) { // Custom validation rule
                        // Call the function to rename removed records
                        $account_id = getAccountUser()->account_id;
                        $titleModel = Customer::where(['id' => $value])->where('account_id', $account_id)->first();
                        if (!$titleModel) {
                            $fail("not exist");
                        }
                    },
                ],
                '*.customer.name' => 'max:255',
                '*.customer.phones.*.title' => [
                    'string',
                    function ($attribute, $value, $fail) use ($phoneableType) {
                        $account = getAccountUser()->account_id;
                        $phone = Phone::where(['title' => $value, 'account_id' => $account])->first();
                        if ($phone) {
                            $isUnique = \App\Models\Phoneable::where('phone_id', $phone->id)
                                ->where('phoneable_type', $phoneableType)
                                ->first();
                            if ($isUnique) {
                                $fail("A phone '$value' number already taken.");
                            }
                        }
                    },
                ],
                '*.customer.phones.*.phoneTypes' => 'exists:phone_types,id|max:255',
                '*.customer.customer_type_id' => 'exists:customer_types,id|max:255',
                '*.customer.addresses.*.title' => 'max:255',
                '*.customer.addresses.*.city_id' => 'exists:cities,id|max:255',
                '*.sector_id' => 'exists:sectors,id|max:255',
                '*.order_status_id' => 'required|exists:order_statuses,id|max:255',
                '*.brandsource_id' => 'exists:brand_source,id|max:255',
                '*.products.*.offers' => 'exists:offers,id|max:255',
                '*.products.*.attributes' => 'required|exists:attributes,id|max:255',
                '*.products.*.quantity' => 'required|numeric',
                '*.products.*.price' => 'numeric',
                '*.products.*.id' => [
                    'required',
                    'int',
                    function ($attribute, $value, $fail) use ($requests, &$thePva) {
                        $account = getAccountUser()->account_id;
                        // Extract index from attribute name
                        $index = str_replace(['*', '.id'], '', $attribute);
                        $index1 = str_replace(['*', '.products.'], '', $index);
                        // Get the ID and title from the request
                        $dataProduct = [
                            'attributes' => $requests->input("{$index}.attributes"),
                            'offers' => $requests->input("{$index}.offers"),
                            'quantity' => $requests->input("{$index}.quantity"),
                            'price' => $requests->input("{$index}.price"),
                            'discount' => $requests->input("{$index}.discount"),
                        ]; // Get ID from request
                        $accountUsers = Account::find($account)->accountUsers->pluck('id')->toArray();
                        $productAttributes = Product::with(['productVariationAttributes.variationAttribute.childVariationAttributes' => function ($vattributes) use ($dataProduct) {
                            $vattributes->whereIn('attribute_id', $dataProduct['attributes']);
                        }])->where(['id' => $value])->whereIn("account_user_id", $accountUsers)->first();
                        if ($productAttributes)
                            $productAttributes->productVariationAttributes->map(function ($pva) use (&$thePva, $dataProduct, $index1) {
                                $childs = $pva->variationAttribute->childVariationAttributes->map(function ($child) {
                                    return $child->attribute_id;
                                });
                                $childPvas = $childs->toArray();
                                sort($childPvas);
                                sort($dataProduct['attributes']);
                                if ($childPvas == $dataProduct['attributes']) {
                                    $offerIds = collect($dataProduct["offers"])->map(function ($offerId) {
                                        $offer = Offer::find($offerId);
                                        if (count($offer->productVariationAttributes) > 0) {
                                            $data = $offer->productVariationAttributes->first()->pivot->id;
                                        } elseif (count($offer->products) > 0) {
                                            $data = $offer->products->first()->pivot->id;
                                        } elseif (count($offer->taxonomies) > 0) {
                                            $data = $offer->taxonomies->first()->pivot->id;
                                        } elseif (count($offer->sources) > 0) {
                                            $data = $offer->sources->first()->pivot->id;
                                        } elseif (count($offer->brands) > 0) {
                                            $data = $offer->brands->first()->pivot->id;
                                        } elseif (count($offer->brandSources) > 0) {
                                            $data = $offer->brandSources->first()->pivot->id;
                                        } elseif (count($offer->customers) > 0) {
                                            $data = $offer->customers->first()->pivot->id;
                                        } elseif (count($offer->customerTypes) > 0) {
                                            $data = $offer->customerTypes->first()->pivot->id;
                                        } elseif (count($offer->cities) > 0) {
                                            $data = $offer->cities->first()->pivot->id;
                                        } elseif (count($offer->countries) > 0) {
                                            $data = $offer->countries->first()->pivot->id;
                                        } elseif (count($offer->regions) > 0) {
                                            $data = $offer->regions->first()->pivot->id;
                                        } elseif (count($offer->sectors) > 0) {
                                            $data = $offer->sectors->first()->pivot->id;
                                        }
                                        return $data;
                                    })->toArray();
                                    $thePva[$index1[0]][] = ['id' => $pva->id, 'price' => $dataProduct['price'], 'offerables' => $offerIds, 'quantity' => $dataProduct['quantity']];
                                }
                            })->toArray();

                        if (!isset($thePva[$index1[0]])) {
                            $fail("not Exists");
                        }
                    },
                ],
                '*.discount' => 'numeric',
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'statut' => 0,
                    'data' => $validator->errors(),
                ]);
            }
        }
        $orders = collect($requests->except('_method'))->map(function ($request, $index) use ($thePva, $isImport) {
            if (isset($request['customer']['id'])) {
                $customer = Customer::where('id', $request['customer']['id'])->first();
                CustomerController::update(new Request([$request['customer']]), $customer->id, $isOrder = 1);
                return $request;
            } elseif (isset($request['customer']['phones'])) {
                $phoneWithCustomers = Phone::with('customers')->where('account_id', getAccountUser()->account_id)->whereIn('title', collect($request['customer']['phones'])->pluck('title')->toArray())->whereHas('customers')->orderBy('created_at', 'DESC')->get();
                if (count($phoneWithCustomers) > 0) {
                    $customer = $phoneWithCustomers->first()->customers->first();
                    $request['customer']['id'] = $customer->id;
                    CustomerController::update(new Request([$request['customer']]), $customer->id, $isOrder = 1);
                } else {
                    $request['customer']['customer_type_id'] = (isset($request['customer']['customer_type_id'])) ? $request['customer']['customer_type_id'] : 1;
                    $request['customer']['name'] = $request['customer']['name'] == "  " ?  "client" : $request['customer']['name'];
                    $customerData = new Request([$request['customer']]);
                    $customer = CustomerController::store($customerData, 1)->first();
                }
            }
            $request["account_id"] = getAccountUser()->account_id;
            if ($isImport == 0) {
                $request['customer_id'] = $customer->id;
                $request['order_status_id'] = 1;
                $request['code'] = isset($request['code']) ? $request['code'] : DefaultCodeController::getAccountCode('Order', $request["account_id"]);
            } elseif ($isImport == 1) {
                $request['customer_id'] = $customer->id;
            }
            $request['warehouse_id'] = isset($request['warehouse_id']) ? $request['warehouse_id'] : Warehouse::where('account_id', getAccountUser()->account_id)->first()->id;
            $order = Order::create($request);
            if ($isImport == 1 || $isImport == 2) {
                $thePva[$index] = $request['order_pva'];
                if ($order->customer->addresses->first())
                    $order->addresses()->syncWithoutDetaching([$order->customer->addresses->first()->id => ['statut' => 1, 'created_at' => now(), 'updated_at' => now()]]);
                if ($order->customer->phones->first())
                    $order->phones()->syncWithoutDetaching([$order->customer->phones->first()->id => ['statut' => 1, 'created_at' => now(), 'updated_at' => now()]]);
            } else {
                foreach ($request["customer"]["addresses"] as $key => $address) {
                    $order->update(['city_id' => $customer->addresses->where('title', $address['title'])->first()->city_id]);
                    $order->addresses()->syncWithoutDetaching([$customer->addresses->where('title', $address['title'])->first()->id => ['statut' => 1, 'created_at' => now(), 'updated_at' => now()]]);
                }
                foreach ($request["customer"]["phones"] as $key => $phone) {
                    $order->phones()->syncWithoutDetaching([$customer->phones->where('title', $phone['title'])->first()->id => ['statut' => 1, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            foreach ($thePva[$index] as $pvaData) {
                $productVariationAttribute = ProductVariationAttribute::find($pvaData['id']);
                $initial_price = Product::find($productVariationAttribute->product_id)->price->first()->price;
                $productPrice = isset($pvaData['price']) ? $pvaData['price'] : $initial_price;
                $discount = isset($pvaData['discount']) ? $pvaData['discount'] : 0;
                $realPrice = (Product::find($productVariationAttribute->product_id)->orderPvas) ? Product::find($productVariationAttribute->product_id)->orderPvas->first()->price : 0;
                $productVariationAttribute->orders()->attach(
                    $order->id,
                    [
                        'quantity' => $pvaData['quantity'],
                        'price' => $productPrice,
                        'realprice' => $realPrice,
                        'initial_price' => $initial_price,
                        'discount' => $discount,
                        'order_status_id' => 1,
                        'account_user_id' => getAccountUser()->id,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                );
                if (isset($pvaData['offerables']) && count($pvaData['offerables']) > 0) {
                    VariationOfferableController::store(new Request(['order_id' => $order->id, 'pva' => $productVariationAttribute, 'variations' => $pvaData['offerables']]));
                }
            }
            $order->orderStatuses()->attach($order->order_status_id, ['account_user_id' => ($isImport == 1 || $isImport == 2) ? $request['account_user_id'] : getAccountUser()->id, 'statut' => 1, 'created_at' => $order->created_at, 'updated_at' => $order->created_at]);
            if ($isImport == 0) {
                $order->comments()->syncWithoutDetaching([44 => [
                    'title' => isset($request['status_comment']) ? $request['status_comment'] : 'Nouvelle Commande',
                    'order_status_id' => 1,
                    'account_user_id' => getAccountUser()->account_id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]]);
                CompensationableController::edit($order->id);
            }
            
            $order = Order::with(['customer', 'productVariationAttributes', 'comments', 'orderStatuses', 'addresses', 'phones'])->find($order->id);
        });
        return response()->json([
            'statut' => 1,
            'data' => $orders,
        ]);
    }
    public function generatePdf($id)
    {
        $order = Order::find($id);
        QrCode::size(100)->generate($order->code, public_path('qrcodes/' . $order->code . '.png'));
        $total = 0;
        $order->activePvas->map(function ($activePva) use (&$total) {
            $total += $activePva->pivot->quantity * $activePva->pivot->price;
        });
        $datas['datas'][] = [
            "code" => $order->code,
            "sender" => $order->brandSource->brand->title,
            "sender_mail" => $order->brandSource->brand->email,
            "sender_phone" => $order->brandSource->brand,
            "customer" => $order->customer->name,
            "address" => $order->addresses->first()->title . "-" . $order->city->title,
            "phones" => $order->phones->map(function ($phone) {
                return $phone->title;
            }),
            "products" => $order->activePvas->map(function ($activePva) {
                $variations = $activePva->variationAttribute->childVariationAttributes->map(function ($childVa) {
                    return $childVa->attribute->title;
                });
                return $activePva->pivot->quantity . " x " . $activePva->product->title . ' : ' . implode(", ", $variations->toArray());
            }),
            "total" => $total . ' DH ',
            'qr_code' => "{$order->code}.png" // QR code for the tracking number
        ];
        $html = view('pdf.tickets', $datas)->render();

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'orientation' => 'P', // P for Portrait, L for Landscape
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);

        $mpdf->WriteHTML($html);
        $pdfContent = $mpdf->Output('', 'S'); // Output as a string

        // Encode PDF to Base64
        $base64Pdf = base64_encode($pdfContent);

        // Return JSON response with Base64 PDF
        return response()->json([
            'statut' => 1,
            'data' => $base64Pdf,
        ]);
    }

    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        $order = Order::find($id);
        if (!$order)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        if (isset($request['orderInfo'])) {
            $data['orderInfo'] = $order->only(['id', 'code', 'shipping_code', 'discount', 'created_at', 'updated_at']);
            $data['orderInfo']['customer'] = $order->customer->only('id', 'name', 'comment');
            $data['orderInfo']['customer']['addresses'] = $order->customer->addresses->map(function ($address) {
                $addressData = $address->only('id', 'title');
                $addressData['city'] = $address->city->only('id', 'title');
                return $addressData;
            });
            $data['orderInfo']['customer']['phones'] = $order->customer->phones->map(function ($phone) {
                $phoneData = $phone->only('id', 'title');
                $phoneData['phoneTypes'] = $phone->phoneTypes->map(function ($phoneType) {
                    $phoneTypeData = $phoneType->only('id', 'title');
                    return $phoneTypeData;
                });
                return $phoneData;
            });
            $data['orderInfo']['warehouse'] = $order->warehouse;
            $data['orderInfo']['payment_type'] = $order->paymentType->only('id', 'title');
            if ($order->shipment)
                $data['orderInfo']['shipment'] = $order->shipment->only('id', 'title');
            if ($order->pickup)
                $data['orderInfo']['pickup'] = $order->pickup->only('id', 'title');
            $data['orderInfo']['payment_method'] = $order->paymentMethod->only('id', 'title');
            $data['orderInfo']['source'] = ["id" => $order->brandSource['id'], "title" => $order->brandSource->source['title']];
            $data['orderInfo']['brand'] = $order->brandSource->brand->only('id', 'title');
            $data['orderInfo']['order_status'] = $order->orderStatus->only('id', 'title');
            $data['orderInfo']['address'] = (count($order->addresses) > 0) ? $order->addresses->first()->only('id', 'title') : $order->parentOrder->addresses->first()->only('id', 'title');
            $data['orderInfo']['city'] = $order->city->only('id', 'title');
            $data['orderInfo']['phone'] = (count($order->phones) > 0) ? $order->phones->first()->phoneTypes->map(function ($phoneType) {
                $phoneTypeData = $phoneType->only('id', 'title');
                return $phoneTypeData;
            }) : $order->parentOrder->phones->first()->phoneTypes->map(function ($phoneType) {
                $phoneTypeData = $phoneType->only('id', 'title');
                return $phoneTypeData;
            });
        }
        if (isset($request['products']['active'])) {
            // Récupérer les produits du fournisseur avec leurs attributs de variation et types d'attributs
            $filters = HelperFunctions::filterColumns($request['products']['active'], ['title', 'addresse', 'phone', 'products']);
            $orderProducts = Order::find($id);
            $orderDataProducts = [];
            $orderProducts->activeOrderPvas->map(function ($orderPva) use (&$orderDataProducts) {
                // Créer un tableau avec les données de base du produit

                if (!isset($orderDataProducts[$orderPva->productVariationAttribute->product->id]))
                    $orderDataProducts[$orderPva->productVariationAttribute->product->id] = [
                        "id" => $orderPva->productVariationAttribute->product->id,
                        "title" => $orderPva->productVariationAttribute->product->title,
                        "reference" => $orderPva->productVariationAttribute->product->reference,
                        "created_at" => $orderPva->created_at,
                        "principalImage" => $orderPva->productVariationAttribute->product->principalImage,
                        "productType" => $orderPva->productVariationAttribute->product->productType->only(['id', 'title']),
                    ];
                $orderDataProducts[$orderPva->productVariationAttribute->product->id]['productVariations'][$orderPva->id] = [
                    "id" => $orderPva->id,
                    "price" => $orderPva->price,
                    "discount" => $orderPva->discount,
                    "quantity" => $orderPva->quantity,
                    "order_status_id" => $orderPva->orderStatus->only('id', 'title'),
                ];
                $orderDataProducts[$orderPva->productVariationAttribute->product->id]['productVariations'][$orderPva->id]['offers'] = FilterController::filterselect(new Request(), 'offers', $orderPva->id)['data'];
                $orderDataProducts[$orderPva->productVariationAttribute->product->id]['productVariations'][$orderPva->id]['selectedOffer'] = null;
                if ($orderPva->offer_variation_id)
                    $orderDataProducts[$orderPva->productVariationAttribute->product->id]['productVariations'][$orderPva->id]['selectedOffer'] = [
                        "id" => $orderPva->offerableVariation->childOfferableVariations->first()->offerable->offer->id,
                        "title" => $orderPva->offerableVariation->childOfferableVariations->first()->offerable->offer->title,
                    ];
                $orderDataProducts[$orderPva->productVariationAttribute->product->id]['productVariations'][$orderPva->id]['selectedAttributes'] = $orderPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                    // Vérifier si l'attribut a un type
                    if ($childVariationAttribute->attribute->typeAttribute) {
                        // Retourner les données formatées pour chaque attribut de variation
                        return [
                            "id" => $childVariationAttribute->id,
                            "attribute_type" => $childVariationAttribute->attribute->typeAttribute->title,
                            "title" => $childVariationAttribute->attribute->title
                        ];
                    }
                })->filter();
                // Récupérer les variations d'attributs pour chaque produit
                $orderDataProducts[$orderPva->productVariationAttribute->product->id]['attributes'] = $orderPva->productVariationAttribute->product->productVariationAttributes->flatMap(function ($productVariationAttribute) {
                    return $productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                        // Vérifier si l'attribut a un type
                        if ($childVariationAttribute->attribute->typeAttribute) {
                            // Retourner les données formatées pour chaque attribut de variation
                            return [
                                "id" => $childVariationAttribute->attribute->id,
                                "attribute_type" => $childVariationAttribute->attribute->typeAttribute->title,
                                "title" => $childVariationAttribute->attribute->title
                            ];
                        }
                    }); // Filtrer les valeurs nulles (attributs sans type)
                })->unique()->values(); // Filtrer les valeurs nulles (attributs sans type)
            });
            $productDatas = collect($orderDataProducts)->map(function ($orderDataProduct) {
                $orderDataProduct["productVariations"] = collect($orderDataProduct["productVariations"])->values();
                return $orderDataProduct;
            })->values();
            $data['products']['active'] =  HelperFunctions::getPagination(collect($productDatas), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        if (isset($request['comments']['active'])) {
            $model = 'App\\Models\\OrderComment';
            $request['comments']['active']['where'] = ['column' => 'order_id', 'value' => $order->id];
            $comments = FilterController::searchs(new Request($request['comments']['active']), $model, ['id', 'title'], false)->map(function ($activeComment) {
                return [
                    "id" => $activeComment->id,
                    "title" => $activeComment->comment->title,
                    "note" => $activeComment->title,
                    "created_at" => $activeComment->created_at,
                    "postpone" => $activeComment->postpone,
                    "statut" => $activeComment->order_status_id,
                    "employee" => [
                        "id" => $activeComment->accountUser->id,
                        "name" => $activeComment->accountUser->user->firstname . " " . $activeComment->accountUser->user->lastname,
                        "images" => $activeComment->accountUser->user->images
                    ]
                ];
            });
            $filters = HelperFunctions::filterColumns($request['comments']['active'], ['title']);
            $data['comments']['active'] =  HelperFunctions::getPagination(collect($comments), $filters['pagination']['per_page'], $filters['pagination']['current_page']);

           
        }
        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }


    public static function changeStatus(Request $request, $order = null)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:comments,id|max:255',
            'postponed' => 'date',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }
        $comment = Comment::find($request['id']);
        $comment->orders()->attach($order->id, [
            'title' => ($request['title']) ? $request['title'] : $comment->title,
            'order_status_id' => $comment->parentComment->current_statut,
            'account_user_id' => getAccountUser()->account_id,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Calculate and update score after adding a new order_comment
        // --- begin score calculation ---
        // Load all comments for this order, sorted by created_at
        $orderComments = $order->comments()->orderBy('order_comment.created_at')->get();
        $callTimestamps = $orderComments->pluck('created_at')->map(function($dt) {
            return \Carbon\Carbon::parse($dt);
        })->toArray();
        // Use order creation time as start
        $orderCreatedAt = $order->created_at;
        // Import the helper function
        if (!function_exists('calculateScore')) {
            require_once app_path('Helpers/OrderScoreHelper.php');
        }
        $score = calculateScore($callTimestamps, $orderCreatedAt);
        $order->score = $score;
        $order->save();
        // --- end score calculation ---

        return ['statut' => $comment->parentComment->current_statut, 'is_change' => $comment->is_change];
    }

    public static function update(Request $requests, $local = 0)
    {
        $productsToActive = [];
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'required|exists:orders,id',
            '*.comment.id' => 'exists:comments,id|max:255',
            '*.comment.shipping_price' => 'numeric|max:255',
            '*.comment.postponed' => 'date',
            '*.customer_id' => [ // Validate title field
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $account_id = getAccountUser()->account_id;
                    $titleModel = Supplier::where(['id' => $value])->where('account_id', $account_id)->first();
                    if (!$titleModel) {
                        $fail("not exist");
                    }
                },
            ],
            '*.warehouse_id' => [
                'exists:warehouses,id',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$warehouse) {
                        $fail("not exist");
                    }
                },
            ],
            '*.productsToInactive.*' => 'required|exists:order_pva,id|max:255',
            '*.productsToUpdate.*.id' => 'required|exists:order_pva,id|max:255',
            '*.productsToUpdate.*.quantity' => 'required|numeric',
            '*.productsToActive.*.offers' => 'exists:offers,id|max:255',
            '*.productsToActive.*.attributes' => 'required|exists:attributes,id|max:255',
            '*.productsToActive.*.quantity' => 'required|numeric',
            '*.productsToActive.*.price' => 'numeric',
            '*.productsToActive.*.id' => [
                'required',
                'int',
                function ($attribute, $value, $fail) use ($requests, &$productsToActive) {
                    $account = getAccountUser()->account_id;
                    // Extract index from attribute name
                    $index = str_replace(['*', '.id'], '', $attribute);
                    // Get the ID and title from the request
                    $dataProduct = [
                        'attributes' => $requests->input("{$index}.attributes"),
                        'offers' => $requests->input("{$index}.offers"),
                        'quantity' => $requests->input("{$index}.quantity"),
                        'price' => $requests->input("{$index}.price"),
                        'discount' => $requests->input("{$index}.discount"),
                    ]; // Get ID from request
                    $accountUsers = Account::find($account)->accountUsers->pluck('id')->toArray();
                    $productAttributes = Product::with(['productVariationAttributes.variationAttribute.childVariationAttributes' => function ($vattributes) use ($dataProduct) {
                        $vattributes->whereIn('attribute_id', $dataProduct['attributes']);
                    }])->where(['id' => $value])->whereIn("account_user_id", $accountUsers)->first();
                    $productAttributes->productVariationAttributes->map(function ($pva) use (&$productsToActive, $dataProduct, $index) {
                        $childs = $pva->variationAttribute->childVariationAttributes->map(function ($child) use (&$productsToActive) {
                            return $child->attribute_id;
                        });
                        $childPvas = $childs->toArray();
                        sort($childPvas);
                        sort($dataProduct['attributes']);
                        if ($childPvas == $dataProduct['attributes']) {
                            $offerIds = collect($dataProduct["offers"])->map(function ($offerId) {
                                $offer = Offer::find($offerId);
                                if (count($offer->productVariationAttributes) > 0) {
                                    $data = $offer->productVariationAttributes->first()->pivot->id;
                                } elseif (count($offer->products) > 0) {
                                    $data = $offer->products->first()->pivot->id;
                                } elseif (count($offer->taxonomies) > 0) {
                                    $data = $offer->taxonomies->first()->pivot->id;
                                } elseif (count($offer->sources) > 0) {
                                    $data = $offer->sources->first()->pivot->id;
                                } elseif (count($offer->brands) > 0) {
                                    $data = $offer->brands->first()->pivot->id;
                                } elseif (count($offer->brandSources) > 0) {
                                    $data = $offer->brandSources->first()->pivot->id;
                                } elseif (count($offer->customers) > 0) {
                                    $data = $offer->customers->first()->pivot->id;
                                } elseif (count($offer->customerTypes) > 0) {
                                    $data = $offer->customerTypes->first()->pivot->id;
                                } elseif (count($offer->cities) > 0) {
                                    $data = $offer->cities->first()->pivot->id;
                                } elseif (count($offer->countries) > 0) {
                                    $data = $offer->countries->first()->pivot->id;
                                } elseif (count($offer->regions) > 0) {
                                    $data = $offer->regions->first()->pivot->id;
                                } elseif (count($offer->sectors) > 0) {
                                    $data = $offer->sectors->first()->pivot->id;
                                }
                                return $data;
                            })->toArray();
                            $productsToActive[$index] = ['id' => $pva->id, 'discount' => $dataProduct['discount'], 'price' => $dataProduct['price'], 'offerables' => $offerIds, 'quantity' => $dataProduct['quantity']];
                        }
                    })->toArray();

                    if (!isset($productsToActive[$index])) {
                        $fail("not Exists");
                    }
                },
            ],

        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }

        $orders = collect($requests->except('_method'))->map(function ($request) use ($productsToActive, $local) {
            $comment = null;
            //récupérer la commande a modifier
            $order = Order::find($request['id']);
            /*if (isset($request['pickup_id'])) {
                $pickUp = Pickup::find($request['pickup_id']);
                if ($pickUp->carrier_id) {
                    $accountPrice = $pickUp->carrier->defaultCarriers()->where('city_id', $order->city_id)->first();
                    if ($accountPrice)
                        $request['real_carrier_price'] = $accountPrice->price;
                }
            } else {
                $request['real_carrier_price'] = 0;
            }*/
            //vérifier si y a un changement des informations du client
            if (isset($request['customer'])) {
                $request['customer']['id'] = $order->customer_id;
                $customerUpdate = CustomerController::update(new Request([$request['customer']]), $order->customer_id, $isOrder = 1);
                if ($customerUpdate->first()['addresses']) {
                    $order->addresses()->detach();
                    $addresses = collect($customerUpdate->first()['addresses'])->pluck('id');
                    $order->addresses()->attach($addresses);
                }
                if ($customerUpdate->first()['phones']) {
                    $order->phones()->detach();
                    $phones = collect($customerUpdate->first()['phones'])->pluck('id');
                    $order->phones()->attach($phones);
                }
                $commentData = [
                    'id' => $order->comments->first()->id,
                    'title' => "changement d'information du client",
                ];
                $comment = OrderController::changeStatus(new Request($commentData), $order);
            }
            if (isset($request['comment'])) {
                $comment = OrderController::changeStatus(new Request(collect($request['comment'])->toArray()), $order);
                if ($comment['statut'] == 2) {
                    $request['shipping_code'] = null;
                    $request['pickup_id'] = null;
                }
            }
            //vérifier si le dépôt est changé
            if (isset($request['warehouse_id']) && $request['warehouse_id'] !== $order->warehouse_id) {
                $commentData = [
                    'id' => $order->comments->first()->id,
                    'title' => "changement du dépôt principale",
                ];
                $comment = OrderController::changeStatus(new Request($commentData), $order);
            }
            //verifier si y a un changement au niveau des produits avant l'envoi
            if ((isset($request['productsToUpdate']) && isset($request['productsToActive']) && isset($request['productsToInactive'])) && in_array($order->order_status_id, [1, 4])) {
                $commentUpdate = Comment::where(['current_statut' => $order->order_status_id])->first()->childComments->where('is_change', 3)->first();
                $commentData = [
                    'id' => $commentUpdate->id,
                    'title' => $commentUpdate->title,
                ];
                $comment = OrderController::changeStatus(new Request($commentData), $order);
            }
            $request['comment'] = isset($request['customer']['comment']) ? $request['customer']['comment'] : null;
            $request['order_status_id'] = $comment['statut'];
            $order_only = collect($request)->only('warehouse_id', 'order_status_id',  'city_id', 'brand_source_id', 'payment_type_id', 'payment_method_id', 'pickup_id', 'real_carrier_price', 'shipment_id','shipping_code','meta');
            $order->update($order_only->all());
            $order->activePvas()->update(['order_status_id' => $comment['statut']]);
            //hna kanvérifier wach la commande 3endha parent ila kane 3endha déja parent o parent 3endo child 
            //hna bach ncrée commande d retour pour les commandes CH 
            if (count($order->parentOrder)>0) {
                $dataReturn["account_id"] = getAccountUser()->account_id;
                $dataReturn['order_status_id'] = 8;
                $dataReturn['customer_id'] = $order->parentOrder->customer_id;
                $dataReturn['city_id'] = $order->parentOrder->city_id;
                $dataReturn['payment_type_id'] = $order->parentOrder->payment_type_id;
                $dataReturn['payment_method_id'] = $order->parentOrder->payment_method_id;
                $dataReturn['brand_source_id'] = $order->parentOrder->brand_source_id;
                $dataReturn['principale'] = 1;
                $dataReturn['warehouse_id'] = $order->warehouse_id;
                $dataReturn['order_id'] = $order->parentOrder->id;
                $dataReturn['code'] = $order->parentOrder->code . "PR";
                $dataReturn['is_change'] = $comment['is_change'];
                $dataReturn['shipping_price'] = isset($request['comment']['shipping_price']) ? $request['comment']['shipping_price'] : 0;
                $returnOrder = Order::create($dataReturn);
                $order->activeOrderPvas->map(function ($orderPva) use ($returnOrder) {
                    $orderPva->update(['principale' => 1]);
                    $productVariationAttribute = ProductVariationAttribute::find($orderPva->product_variation_attribute_id);
                    $productVariationAttribute->orders()->attach(
                        $returnOrder->id,
                        [
                            'quantity' => $orderPva->quantity,
                            'price' => $orderPva->price,
                            'realprice' => $orderPva->realprice,
                            'initial_price' => $orderPva->initial_price,
                            'discount' => $orderPva->discount,
                            'order_status_id' => 8,
                            'account_user_id' => getAccountUser()->id,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]
                    );
                });
                return $returnOrder;
            }
            if ($comment['is_change'] == 1) {
                $dataNew["account_id"] = getAccountUser()->account_id;
                $dataNew['order_status_id'] = 4;
                $dataNew['customer_id'] = $order->customer_id;
                $dataNew['city_id'] = $order->city_id;
                $dataNew['payment_type_id'] = $order->payment_type_id;
                $dataNew['payment_method_id'] = $order->payment_method_id;
                $dataNew['brand_source_id'] = $order->brand_source_id;
                $dataNew['warehouse_id'] = $order->warehouse_id;
                $dataNew['order_id'] = $order->id;
                $dataNew['code'] = $order->code . "-CH";
                $dataNew['is_change'] = $comment['is_change'];
                $dataNew['shipping_price'] = isset($request['comment']['shipping_price']) ? $request['comment']['shipping_price'] : 0;
                $dataNew['discount'] = isset($request['comment']['discount']) ? $request['comment']['discount'] : 0;
                $order_new = collect($dataNew)->only('code', 'warehouse_id', 'adresse', 'city_id', 'brand_source_id', 'payment_type_id', 'payment_method_id', 'customer_id', 'order_status_id', 'account_id', 'shipping_price', 'order_id');
                //Générer une nouvelle commande pour échanger la commande principale
                $newOrder = Order::create($order_new->all());
                $newOrder->orderStatuses()->attach($newOrder->order_status_id, ['account_user_id' => getAccountUser()->id, 'statut' => 1, 'created_at' => now(), 'updated_at' => now()]);
                $commentData = [
                    'id' => 38,
                    'title' => "Nouvelle Commande",
                ];
                $comment = OrderController::changeStatus(new Request($commentData), $newOrder);

                //ajouter les produits de la commande a échangé
                foreach ($productsToActive as $pvaData) {
                    $productVariationAttribute = ProductVariationAttribute::find($pvaData['id']);
                    $initial_price = Product::find($productVariationAttribute->product_id)->price->first()->price;
                    $productPrice = isset($pvaData['price']) ? $pvaData['price'] : $initial_price;
                    $discount = isset($pvaData['discount']) ? $pvaData['discount'] : 0;
                    $realPrice = (Product::find($productVariationAttribute->product_id)->orderPvas) ? Product::find($productVariationAttribute->product_id)->orderPvas->first()->price : 0;
                    $productVariationAttribute->orders()->attach(
                        $newOrder->id,
                        [
                            'quantity' => $pvaData['quantity'],
                            'price' => $productPrice,
                            'realprice' => $realPrice,
                            'initial_price' => $initial_price,
                            'discount' => $discount,
                            'order_status_id' => 4,
                            'account_user_id' => getAccountUser()->id,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]
                    );
                    if (isset($pvaData['offerables']) && count($pvaData['offerables']) > 0) {
                        VariationOfferableController::store(new Request(['order_id' => $order->id, 'pva' => $productVariationAttribute, 'variations' => $pvaData['offerables']]));
                    }
                }
                return $newOrder;
            } elseif ($comment['is_change'] == 2) {
                $dataReturn["account_id"] = getAccountUser()->account_id;
                $dataReturn['order_status_id'] = 8;
                $dataReturn['customer_id'] = $order->customer_id;
                $dataReturn['city_id'] = $order->city_id;
                $dataReturn['payment_type_id'] = $order->payment_type_id;
                $dataReturn['payment_method_id'] = $order->payment_method_id;
                $dataReturn['brand_source_id'] = $order->brand_source_id;
                $dataReturn['principale'] = 1;
                $dataReturn['warehouse_id'] = $order->warehouse_id;
                $dataReturn['order_id'] = $order->id;
                $dataReturn['code'] = $order->code . "PR";
                $dataReturn['is_change'] = $comment['is_change'];
                $dataReturn['shipping_price'] = isset($request['comment']['shipping_price']) ? $request['comment']['shipping_price'] : 0;
                $returnOrder = Order::create($dataReturn);
                if (isset($request['productsToUpdate'])) {
                    $order->activeOrderPvas->map(function ($pva) use ($request, $returnOrder) {
                        $pvaUpdate = collect($request['productsToUpdate'])->pluck('id')->toArray();
                        if (in_array($pva->id, $pvaUpdate)) {
                            $record = collect($request['productsToUpdate'])->where('id', $pva->id)->first();
                            $shipped_record = $pva;
                            $canceled_record = $pva;
                            $canceled_record->quantity = $pva->quantity - $record['quantity'];
                            $shipped_record->order_status_id = 8;
                            $shipped_record->order_id = $returnOrder->id;
                            $shipped_record->quantity = $record['quantity'];
                            OrderPva::create($shipped_record->toArray());
                            OrderPva::create($canceled_record->toArray());
                            $pva->update(['principale' => 1, 'order_status_id' => null]);
                        }
                    });
                }
                if (isset($request['productsToInactive'])) {
                    foreach ($request['productsToInactive'] as $pvaInactive) {
                        $orderPva = OrderPva::find($pvaInactive);
                        $orderPva->update(['order_status_id' => 8]);
                    }
                }
                return $returnOrder;
            } else {

                if (isset($request['productsToInactive'])) {
                    foreach ($request['productsToInactive'] as $pvaData) {
                        $orderPva = OrderPva::find($pvaData);
                        $orderPva->update(['order_status_id' => 2]);
                    }
                }
                if (isset($request['productsToActive'])) {
                    foreach ($productsToActive as $pvaData) {
                        $productVariationAttribute = ProductVariationAttribute::find($pvaData['id']);
                        $initial_price = Product::find($productVariationAttribute->product_id)->price->first()->price;
                        $productPrice = isset($pvaData['price']) ? $pvaData['price'] : $initial_price;
                        $discount = isset($pvaData['discount']) ? $pvaData['discount'] : 0;
                        $realPrice = (Product::find($productVariationAttribute->product_id)->orderPvas) ? Product::find($productVariationAttribute->product_id)->orderPvas->first()->price : 0;
                        $productVariationAttribute->orders()->attach(
                            $order->id,
                            [
                                'quantity' => $pvaData['quantity'],
                                'price' => $productPrice,
                                'realprice' => $realPrice,
                                'initial_price' => $initial_price,
                                'discount' => $discount,
                                'order_status_id' => $order->order_status_id,
                                'account_user_id' => getAccountUser()->id,
                                'created_at' => now(),
                                'updated_at' => now()
                            ]
                        );
                        if (isset($pvaData['offerables']) && count($pvaData['offerables']) > 0) {
                            VariationOfferableController::store(new Request(['order_id' => $order->id, 'pva' => $productVariationAttribute, 'variations' => $pvaData['offerables']]));
                        }
                    }
                }
                if (isset($request['productsToUpdate'])) {
                    foreach ($request['productsToUpdate'] as $pvaData) {
                        $orderPva = OrderPva::find($pvaData['id']);
                        if ($pvaData['quantity'] >= $orderPva->quantity) {
                            $orderPva->update([
                                'quantity' => $pvaData['quantity'],
                                'price' => isset($pvaData['price']) ? $pvaData['price'] : $orderPva->price,
                                'discount' => isset($pvaData['discount']) ? $pvaData['discount'] : $orderPva->discount,
                                'account_user_id' => getAccountUser()->id,
                            ]);
                        } else {
                            $CanceledQty = $orderPva->quantity - $pvaData['quantity'];
                            $orderPva->update([
                                'quantity' => $pvaData['quantity'],
                                'price' => isset($pvaData['price']) ? $pvaData['price'] : $orderPva->price,
                                'realprice' => isset($pvaData['realprice']) ? $pvaData['realprice'] : $orderPva->realprice,
                                'discount' => isset($pvaData['discount']) ? $pvaData['discount'] : $orderPva->discount,
                                'account_user_id' => getAccountUser()->id,
                            ]);
                            $productVariationAttribute->orders()->attach(
                                $order->id,
                                [
                                    'quantity' => $CanceledQty,
                                    'price' => $orderPva->price,
                                    'realprice' => $orderPva->realprice,
                                    'initial_price' => $orderPva->initial_price,
                                    'discount' => $orderPva->discount,
                                    'order_status_id' => 2,
                                    'account_user_id' => getAccountUser()->id,
                                    'created_at' => now(),
                                    'updated_at' => now()
                                ]
                            );
                        }
                        if (isset($pvaData['offerables']) && count($pvaData['offerables']) > 0) {
                            VariationOfferableController::store(new Request(['order_pva' => $orderPva, 'variations' => $pvaData['offerables']]));
                        }
                    }
                }
                CompensationableController::edit($order->id);
                if ($local == 1)
                    return $order->activeOrderPvas;
                return $order;
            }
        });
        if ($local == 1)
            return $orders;
        return response()->json([
            'statut' => 1,
            'data' => $orders,
        ]);
    }

    public function destroy($id)
    {
        $SupplierOder = Order::find($id);
        $SupplierOder->delete();
        return response()->json([
            'statut' => 1,
            'data' => $SupplierOder,
        ]);
    }
}
