<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf as PDF;
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
use App\Models\OrderStatus;
use App\Services\GoogleSheetsService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{

    public function index(Request $request)
    {

        $request = collect($request->query())->toArray();
        $filter = [];
        if (isset($request['pagination'])) {
            $filter['limit'] = isset($request['pagination']['per_page']) ? $request['pagination']['per_page'] : 10;
            $filter['page'] = isset($request['pagination']['current_page']) ? $request['pagination']['current_page'] : 0;
            if (isset($request['sort']) && isset($request['sort'][0]['column'])) {
                $filter['sort']['by'] = $request['sort'][0]['column'];
            } else {
                $filter['sort']['by'] = 'created_at';
            }
            if (isset($request['sort']) && isset($request['sort'][0]['order'])) {
                $filter['sort']['order'] = $request['sort'][0]['order'];
            } else {
                $filter['sort']['order'] = 'desc';
            }
        }


        $ordersQuery = Order::orderBy($filter['sort']['by'], $filter['sort']['order'])->where('account_id', getAccountUser()->account_id);

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
            'carriers' => ['relation', 'pickup.carrier', 'id'],
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
                $query->where('code', 'like', "%$search%")->orWhere('shipping_code', 'like', "%$search%")
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

        // Add date filter
        if (!empty($request['startDate']) || !empty($request['endDate'])) {
            if (!empty($request['startDate'])) {
                $startDateTime = $request['startDate'] . ' 00:00:00';
                $ordersQuery = $ordersQuery->where('created_at', '>=', $startDateTime);
            }
            if (!empty($request['endDate'])) {
                $endDateTime = $request['endDate'] . ' 23:59:59';
                $ordersQuery = $ordersQuery->where('created_at', '<=', $endDateTime);
            }
        }

        // Exclude brand_source_id=108 when status is 1, unless source filter includes 70
        if (!empty($request['status']) && is_array($request['status']) && in_array(1, $request['status'])) {
            $sourceFilterIncludesSeventy = !empty($request['sources']) && is_array($request['sources']) && in_array(36, $request['sources']);
            if (!$sourceFilterIncludesSeventy) {
                $ordersQuery = $ordersQuery->where('brand_source_id', '!=', 108);
            }
        }

        $total = $ordersQuery->count();
        $orders = $ordersQuery
            ->skip($filter['page'] * $filter['limit'])
            ->take($filter['limit'])
            ->get();

        $datas = $orders->map(function ($data) {
            $orderData = $data->only('id', 'code', 'shipping_code', 'note', 'order_id', 'created_at', 'updated_at');
            
            // Calculate score dynamically from account_user_order_status and order_comment tables
            if (!function_exists('calculateTotalOrderScore')) {
                require_once app_path('Helpers/OrderScoreHelper.php');
            }
            $orderData['score'] = calculateTotalOrderScore($data->id);
            
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
            $orderData['comments'] = $data->lastOrderComments()->where('type', 'comment')->get()->map(function ($comment) {
                $data = [
                    "id" => $comment->id,
                    "comment" => $comment->comment_id,
                    "title" => $comment->title,
                    "created_at" => $comment->created_at,
                    "user" => $comment->accountUser->user,
                    "status" => $comment->orderStatus->only('id', 'title', 'statut'),
                ];
                $data["status"]['created_at'] = $comment->created_at;
                return $data;
            });
            if ($data->customer) {
                $orderData['customer'] = $data->customer->only('id', 'name');
                $orderData['customer']['images'] = $data->customer->images;
                $orderData['customer']['phones'] = $data->customer->phones->map(function ($phone) {
                    return $phone->only('id', 'title');
                });
                $orderData['customer']['address'] = $data->customer->addresses->map(function ($address) {
                    return $address->only('id', 'title', 'city');
                });
            } else {
                $orderData['customer'] = ['id' => null, 'name' => null, 'images' => [], 'phones' => [], 'address' => []];
            }
            $totalOrder = 0;
            $orderData['products'] = ($data->order_status_id==2 ? $data->inactiveOrderPvas : $data->activeOrderPvas)->map(function ($actfOrderPva) use (&$totalOrder) {
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
            $source = $data->brandSource->source;
            $sourceArr = $source->only('id', 'title', 'images');
            if (isset($source->images) && $source->images instanceof \Illuminate\Support\Collection) {
                $sourceArr['images'] = $source->images->sortByDesc('created_at')->values();
            }
            $orderData['source'] = $sourceArr;
            return $orderData;
        });

        return [
            'statut' => 1,
            'data' => $datas,
            'per_page' => (int)($filter['limit'] ?? 10),
            'current_page' => (int)($filter['page'] ?? 0) + 1,
            'total' => $total,
            'score' => 30
        ];
    }

    public function countByPhones(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phones' => 'required|array',
            'phones.*' => 'string'
        ]);

        if ($validator->fails()) {
            return [
                'statut' => 0,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ];
        }

        $phones = collect($request->input('phones', []))
            ->filter(function ($phone) {
                return is_string($phone) && trim($phone) !== '';
            })
            ->map(function ($phone) {
                return trim($phone);
            })
            ->unique()
            ->values()
            ->all();

        $result = [];
        foreach ($phones as $phone) {
            $result[$phone] = 0;
        }

        if (empty($phones)) {
            return $result;
        }

        // Extract last 8 digits from each phone number (ignoring all non-digit characters)
        $phoneLast8Map = [];
        foreach ($phones as $phone) {
            // Remove all non-digit characters
            $digitsOnly = preg_replace('/\D/', '', $phone);
            // Get last 8 digits
            $last8 = substr($digitsOnly, -8);
            // Only process if we have exactly 8 digits
            if (strlen($last8) === 8) {
                $phoneLast8Map[$last8] = $phone;
            }
        }

        if (empty($phoneLast8Map)) {
            return $result;
        }

        // Build a LIKE query that matches the last 8 digits in any format
        // Pattern: %d%i%g%i%t%1%...%digit8% matches digits in sequence with any chars between
        $phoneCounts = Phone::where(function ($query) use ($phoneLast8Map) {
            foreach (array_keys($phoneLast8Map) as $last8) {
                // Split into individual digits and join with % wildcard
                // This will match "44001015", "44-00-10-15", "44 00 10 15", etc.
                $pattern = '%' . implode('%', str_split($last8)) . '%';
                $query->orWhere('title', 'like', $pattern);
            }
        })
        ->withCount([
            'orders as orders_count' => function ($query) {
                $query->where('account_id', getAccountUser()->account_id);
            }
        ])
        ->get(['id', 'title']);

        // Map results back to original phone numbers by comparing last 8 digits
        foreach ($phoneCounts as $phoneModel) {
            // Extract digits from database phone and get last 8
            $dbPhoneDigits = preg_replace('/\D/', '', $phoneModel->title);
            $dbLast8 = substr($dbPhoneDigits, -8);
            
            // If this matches one of our searched phones, add the count
            if (isset($phoneLast8Map[$dbLast8])) {
                $originalPhone = $phoneLast8Map[$dbLast8];
                $result[$originalPhone] += (int)$phoneModel->orders_count;
            }
        }

        return $result;
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
    //             if (Brand::find($brandId))
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
    //                 $searchIds = array_merge($searchIds, City::find($cityId)->orders->pluck('id')->toArray());
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
    //         $orderData = $data->only('id', 'code', 'shipping_code', 'comment', 'order_id', 'created_at', 'updated_at','score');
    //         if (!$orderData['shipping_code'])
    //             $orderData['shipping_code'] = "---";
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
    //         $orderData['score']=$data->score;
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
        // Normalize incoming payload (array of orders) and remove method spoofing key.
        $ordersPayload = collect($requests->except('_method'))->values();

        // Cache account context once to avoid repeated helper calls.
        $accountUser = getAccountUser();
        $accountId = $accountUser->account_id;

        // This array stores the resolved PVA rows per order index.
        $resolvedPvas = [];

        // Resolve the pivot id of each offer relation (product, brand, city, etc.).
        $resolveOfferablePivotIds = function (array $offerIds) {
            return collect($offerIds)->map(function ($offerId) {
                $offer = Offer::find($offerId);
                if (!$offer) {
                    return null;
                }

                if ($offer->productVariationAttributes->count() > 0) {
                    return $offer->productVariationAttributes->first()->pivot->id;
                }
                if ($offer->products->count() > 0) {
                    return $offer->products->first()->pivot->id;
                }
                if ($offer->taxonomies->count() > 0) {
                    return $offer->taxonomies->first()->pivot->id;
                }
                if ($offer->sources->count() > 0) {
                    return $offer->sources->first()->pivot->id;
                }
                if ($offer->brands->count() > 0) {
                    return $offer->brands->first()->pivot->id;
                }
                if ($offer->brandSources->count() > 0) {
                    return $offer->brandSources->first()->pivot->id;
                }
                if ($offer->customers->count() > 0) {
                    return $offer->customers->first()->pivot->id;
                }
                if ($offer->customerTypes->count() > 0) {
                    return $offer->customerTypes->first()->pivot->id;
                }
                if ($offer->cities->count() > 0) {
                    return $offer->cities->first()->pivot->id;
                }
                if ($offer->countries->count() > 0) {
                    return $offer->countries->first()->pivot->id;
                }
                if ($offer->regions->count() > 0) {
                    return $offer->regions->first()->pivot->id;
                }
                if ($offer->sectors->count() > 0) {
                    return $offer->sectors->first()->pivot->id;
                }

                return null;
            })->filter()->values()->all();
        };

        // Generate order code and append brand/source initials when available.
        $generateOrderCode = function (array $orderData) use ($accountId) {
            $baseCode = isset($orderData['code']) ? $orderData['code'] : DefaultCodeController::getAccountCode('Order', $accountId);

            if (empty($orderData['brand_source_id'])) {
                return $baseCode;
            }

            $brandSource = \App\Models\BrandSource::with(['brand', 'source'])->find($orderData['brand_source_id']);
            if (!$brandSource || !$brandSource->brand || !$brandSource->source) {
                return $baseCode;
            }

            $brandLetter = strtoupper(substr($brandSource->brand->title, 0, 1));
            $sourceLetter = strtoupper(substr($brandSource->source->title, 0, 1));

            return $baseCode . $brandLetter . $sourceLetter;
        };

        // Validate payload and resolve product variations when request is not import mode.
        if ($isImport == 0) {
            $phoneableType = Customer::class;
            $validator = Validator::make($ordersPayload->toArray(), [
                '*.warehouse_id' => [
                    'nullable',
                    'int',
                    function ($attribute, $value, $fail) use ($accountId) {
                        if ($value === null) {
                            return;
                        }
                        $warehouse = Warehouse::where(['id' => $value, 'account_id' => $accountId])->first();
                        if (!$warehouse) {
                            $fail('not exist');
                        }
                    },
                ],
                '*.payment_type_id' => 'nullable|exists:payment_types,id|max:255',
                '*.payment_method_id' => 'nullable|exists:payment_methods,id|max:255',
                '*.customer.id' => [
                    'nullable',
                    function ($attribute, $value, $fail) use ($accountId) {
                        if ($value === null) {
                            return;
                        }
                        $customer = Customer::where('id', $value)->where('account_id', $accountId)->first();
                        if (!$customer) {
                            $fail('not exist');
                        }
                    },
                ],
                '*.customer.name' => 'nullable|max:255',
                '*.customer.phones.*.title' => [
                    'nullable',
                    'string',
                    /*function ($attribute, $value, $fail) use ($phoneableType, $accountId) {
                        if ($value === null || trim($value) === '') {
                            return;
                        }
                        $phone = Phone::where(['title' => $value, 'account_id' => $accountId])->first();
                        if ($phone) {
                            $exists = \App\Models\Phoneable::where('phone_id', $phone->id)
                                ->where('phoneable_type', $phoneableType)
                                ->exists();
                            if ($exists) {
                                $fail("A phone '$value' number already taken.");
                            }
                        }
                    },*/
                ],
                '*.customer.phones.*.phoneTypes' => 'nullable|array',
                '*.customer.phones.*.phoneTypes.*' => 'exists:phone_types,id|max:255',
                '*.customer.customer_type_id' => 'nullable|exists:customer_types,id|max:255',
                '*.customer.addresses.*.title' => 'nullable|max:255',
                '*.customer.addresses.*.city_id' => 'nullable|exists:cities,id|max:255',
                '*.sector_id' => 'nullable|exists:sectors,id|max:255',
                '*.order_status_id' => 'nullable|exists:order_statuses,id|max:255',
                '*.brand_source_id' => 'nullable|exists:brand_source,id|max:255',
                '*.products' => 'required|array|min:1',
                '*.products.*.offers' => 'nullable|array',
                '*.products.*.offers.*' => 'exists:offers,id|max:255',
                '*.products.*.attributes' => 'required|array|min:1',
                '*.products.*.attributes.*' => 'exists:attributes,id|max:255',
                '*.products.*.quantity' => 'required|numeric|min:1',
                '*.products.*.price' => 'nullable|numeric',
                '*.products.*.discount' => 'nullable|numeric',
                '*.products.*.id' => 'required|int',
                '*.discount' => 'nullable|numeric',
                '*.carrier_price' => 'nullable|numeric',
                '*.scarrier_price' => 'nullable|numeric',
            ]);

            // Stop early when request shape is invalid.
            if ($validator->fails()) {
                return response()->json([
                    'statut' => 0,
                    'data' => $validator->errors(),
                ]);
            }

            // Build the list of account users allowed to own products.
            $accountUsers = Account::find($accountId)?->accountUsers->pluck('id')->toArray() ?? [];

            // Resolve each product to its matching variation based on selected attributes.
            foreach ($ordersPayload as $orderIndex => $orderData) {
                $resolvedPvas[$orderIndex] = [];

                foreach (($orderData['products'] ?? []) as $productData) {
                    $selectedAttributes = collect($productData['attributes'] ?? [])->map(function ($id) {
                        return (int)$id;
                    })->sort()->values()->all();

                    $product = Product::with('productVariationAttributes.variationAttribute.childVariationAttributes')
                        ->where('id', $productData['id'])
                        ->whereIn('account_user_id', $accountUsers)
                        ->first();

                    if (!$product) {
                        return response()->json([
                            'statut' => 0,
                            'data' => ["$orderIndex.products" => ['not exist']],
                        ]);
                    }

                    $matchedPva = null;
                    foreach ($product->productVariationAttributes as $pva) {
                        $pvaAttributes = $pva->variationAttribute->childVariationAttributes
                            ->pluck('attribute_id')
                            ->map(function ($id) {
                                return (int)$id;
                            })
                            ->sort()
                            ->values()
                            ->all();

                        if ($pvaAttributes === $selectedAttributes) {
                            $matchedPva = $pva;
                            break;
                        }
                    }

                    if (!$matchedPva) {
                        return response()->json([
                            'statut' => 0,
                            'data' => ["$orderIndex.products" => ['not Exists']],
                        ]);
                    }

                    $resolvedPvas[$orderIndex][] = [
                        'id' => $matchedPva->id,
                        'price' => $productData['price'] ?? null,
                        'discount' => $productData['discount'] ?? 0,
                        'offerables' => $resolveOfferablePivotIds($productData['offers'] ?? []),
                        'quantity' => $productData['quantity'],
                    ];
                }
            }
        }

        try {
            // Create all orders in a single transaction to keep data consistent.
            $orderIds = DB::transaction(function () use ($ordersPayload, $resolvedPvas, $isImport, $accountUser, $accountId, $generateOrderCode) {
                return $ordersPayload->map(function ($request, $index) use ($resolvedPvas, $isImport, $accountUser, $accountId, $generateOrderCode) {
                    // Resolve or create the customer linked to the order.
                    $customer = null;
                    if (isset($request['customer']['id'])) {
                        $customer = Customer::where('id', $request['customer']['id'])->where('account_id', $accountId)->first();
                        if ($customer) {
                            $updateResult = CustomerController::update(new Request([$request['customer']]), $customer->id, $isOrder = 1);
                            if ($updateResult instanceof \Illuminate\Http\JsonResponse) {
                                $payload = $updateResult->getData(true);
                                $message = isset($payload[1]) ? json_encode($payload[1]) : 'Customer update failed.';
                                throw new \Exception($message);
                            }
                        } 
                    } elseif (isset($request['customer']['phones'])) {
                        $request['customer']['customer_type_id'] = $request['customer']['customer_type_id'] ?? 1;
                        $request['customer']['name'] = (isset($request['customer']['name']) && trim($request['customer']['name']) !== '') ? $request['customer']['name'] : 'client';

                        // Reuse an existing customer when one of the provided phones already exists.
                        $phoneTitles = collect($request['customer']['phones'])
                            ->pluck('title')
                            ->filter()
                            ->map(function ($title) {
                                return formatPhoneNumber($title);
                            })
                            ->values()
                            ->all();

                        $phoneWithCustomer = null;
                        if (!empty($phoneTitles)) {
                            $phoneWithCustomer = Phone::with('customers')
                                ->where('account_id', $accountId)
                                ->whereIn('title', $phoneTitles)
                                ->whereHas('customers')
                                ->orderBy('created_at', 'DESC')
                                ->first();
                        }

                        if ($phoneWithCustomer && $phoneWithCustomer->customers->first()) {
                            $customer = $phoneWithCustomer->customers->first();
                            $request['customer']['id'] = $customer->id;

                            $updateResult = CustomerController::update(new Request([$request['customer']]), $customer->id, $isOrder = 1);
                            if ($updateResult instanceof \Illuminate\Http\JsonResponse) {
                                $payload = $updateResult->getData(true);
                                $message = isset($payload[1]) ? json_encode($payload[1]) : 'Customer update failed.';
                                throw new \Exception($message);
                            }
                        } else {
                            $customerData = new Request([$request['customer']]);
                            $customerResult = CustomerController::store($customerData, 1);

                            // CustomerController::store returns a JsonResponse on validation failure even in local mode.
                            // Unwrap the collection or surface the validation message as an exception.
                            if ($customerResult instanceof \Illuminate\Http\JsonResponse) {
                                $payload = $customerResult->getData(true);
                                $message = isset($payload[1]) ? json_encode($payload[1]) : 'Customer validation failed.';
                                throw new \Exception($message);
                            }

                            $customer = $customerResult->first();
                        }
                    }

                    // Ensure customer exists when a customer id is required.
                    if (!$customer) {
                        throw new \Exception('Customer is required to create order.');
                    }

                    // Fill required order ownership fields.
                    $request['account_id'] = $accountId;
                    $request['customer_id'] = $customer->id;
                    $request['order_status_id'] = 4;

                    // Apply duplicate guard only for non-import creation flow.
                    if ($isImport == 0 && isset($request['customer']['phones']) && count($request['customer']['phones']) > 0) {
                        $phoneTitles = collect($request['customer']['phones'])->pluck('title')->filter()->values()->all();

                        if (count($phoneTitles) > 0) {
                            $recentOrders = Order::where('account_id', $accountId)
                                ->where('created_at', '>=', now()->subMinutes(5))
                                ->whereHas('phones', function ($query) use ($phoneTitles) {
                                    $query->whereIn('title', $phoneTitles);
                                })
                                ->with('orderPvas')
                                ->get();

                            $newProductIds = collect($resolvedPvas[$index] ?? [])->pluck('id')->sort()->values()->toArray();
                            foreach ($recentOrders as $recentOrder) {
                                $recentProductIds = $recentOrder->orderPvas->pluck('product_variation_attribute_id')->sort()->values()->toArray();
                                if ($recentProductIds === $newProductIds) {
                                    throw new \Exception('Duplicate order detected for phone number(s): ' . implode(', ', $phoneTitles));
                                }
                            }
                        }
                    }

                    // Build business order code for non-import flow.
                    if ($isImport == 0) {
                        $request['code'] = $generateOrderCode($request);
                    }

                    // Fallback to first account warehouse when none was sent.
                    if (!isset($request['warehouse_id'])) {
                        $warehouse = Warehouse::where('account_id', $accountId)->first();
                        if (!$warehouse) {
                            throw new \Exception('No warehouse found for account.');
                        }
                        $request['warehouse_id'] = $warehouse->id;
                    }

                    // Persist the order row.
                    $order = Order::create($request);

                    // For import mode, attach first customer phone/address and use provided order_pva.
                    if ($isImport == 1 || $isImport == 2) {
                        $requestPvas = $request['order_pva'] ?? [];

                        if ($order->customer && $order->customer->addresses->first()) {
                            $address = $order->customer->addresses->first();
                            $order->addresses()->syncWithoutDetaching([$address->id => ['statut' => 1, 'created_at' => now(), 'updated_at' => now()]]);
                        }

                        if ($order->customer && $order->customer->phones->first()) {
                            $phone = $order->customer->phones->first();
                            $order->phones()->syncWithoutDetaching([$phone->id => ['statut' => 1, 'created_at' => now(), 'updated_at' => now()]]);
                        }
                    } else {
                        $requestPvas = $resolvedPvas[$index] ?? [];

                        // Attach customer addresses to the order and set city from matched address.
                        foreach (($request['customer']['addresses'] ?? []) as $addressData) {
                            $customerAddress = $customer->addresses->where('title', $addressData['title'] ?? null)->first();
                            if ($customerAddress) {
                                $order->update(['city_id' => $customerAddress->city_id]);
                                $order->addresses()->syncWithoutDetaching([$customerAddress->id => ['statut' => 1, 'created_at' => now(), 'updated_at' => now()]]);
                            }
                        }

                        // Attach customer phones to the order.
                        foreach (($request['customer']['phones'] ?? []) as $phoneData) {
                            $customerPhone = $customer->phones->where('title', $phoneData['title'] ?? null)->first();
                            if ($customerPhone) {
                                $order->phones()->syncWithoutDetaching([$customerPhone->id => ['statut' => 1, 'created_at' => now(), 'updated_at' => now()]]);
                            }
                        }
                    }

                    // Attach each resolved product variation to order with computed pricing fields.
                    foreach ($requestPvas as $pvaData) {
                        $productVariationAttribute = ProductVariationAttribute::find($pvaData['id']);
                        if (!$productVariationAttribute) {
                            continue;
                        }

                        $product = Product::find($productVariationAttribute->product_id);

                        // price() is morphToMany → always a Collection, but may be empty.
                        $initialPrice = $product?->price->first()?->price ?? 0;

                        $productPrice = isset($pvaData['price']) ? $pvaData['price'] : $initialPrice;
                        $discount = isset($pvaData['discount']) ? $pvaData['discount'] : 0;

                        // orderPvas is not defined on Product, so the property is null.
                        // Use optional() so ->first() on null returns null safely instead of throwing.
                        $realPrice = optional($product?->orderPvas)->first()?->price ?? 0;

                        $productVariationAttribute->orders()->attach($order->id, [
                            'quantity' => $pvaData['quantity'],
                            'price' => $productPrice,
                            'realprice' => $realPrice,
                            'initial_price' => $initialPrice,
                            'discount' => $discount,
                            'order_status_id' => 4,
                            'account_user_id' => $accountUser->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        // Persist offer variation links when provided.
                        if (!empty($pvaData['offerables'])) {
                            VariationOfferableController::store(new Request([
                                'order_id' => $order->id,
                                'pva' => $productVariationAttribute,
                                'variations' => $pvaData['offerables'],
                            ]));
                        }
                    }

                    // Sync order creation status to Google Sheets (if enabled).
                    if ($isImport == 0 && config('google-sheets.enabled')) {
                        try {
                            app(GoogleSheetsService::class)->appendOrderStatusRow(
                                $order,
                                null,
                                $accountUser,
                                'Nouvelle commande créée'
                            );

                            $order->comments()->syncWithoutDetaching([44 => [
                                'title' => 'Sync with Google Sheets',
                                'order_status_id' => 4,
                                'account_user_id' => $accountId,
                                'score' => 0,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]]);

                            $order->sync = true;
                            $order->save();
                        } catch (\Throwable $e) {
                            $order->sync = false;
                            $order->save();
                            Log::warning('Google Sheets sync failed for order ' . $order->id . ': ' . $e->getMessage());
                        }
                    }

                    // Return only created order id to preserve endpoint response contract.
                    return $order->id;
                });
            });

            // Return success payload with all created order ids.
            return response()->json([
                'statut' => 1,
                'data' => $orderIds,
            ]);
        } catch (\Throwable $e) {
            // Return a safe error payload and log details for debugging.
            Log::error('Order store failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'statut' => 0,
                'data' => $e->getMessage(),
            ], 422);
        }
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
            "address" => ($order->addresses->first()?->title ?? '') . "-" . ($order->city?->title ?? ''),
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
            $data['orderInfo'] = $order->only(['id', 'code','carrier_price', 'shipping_code', 'discount', 'created_at', 'updated_at']);
            $orderCustomer = $order->customer;
            $data['orderInfo']['customer'] = $orderCustomer
                ? $orderCustomer->only('id', 'name', 'note')
                : ['id' => null, 'name' => null, 'note' => null];
            $data['orderInfo']['customer']['addresses'] = $orderCustomer
                ? $orderCustomer->addresses->map(function ($address) {
                    $addressData = $address->only('id', 'title');
                    $addressData['city'] = $address->city ? $address->city->only('id', 'title') : null;
                    return $addressData;
                })
                : [];
            $data['orderInfo']['customer']['phones'] = $orderCustomer
                ? $orderCustomer->phones->map(function ($phone) {
                    $phoneData = $phone->only('id', 'title');
                    $phoneData['phoneTypes'] = $phone->phoneTypes->map(function ($phoneType) {
                        return $phoneType->only('id', 'title');
                    });
                    return $phoneData;
                })
                : [];
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
            $data['orderInfo']['address'] = (count($order->addresses) > 0)
                ? $order->addresses->first()->only('id', 'title')
                : ($order->parentOrder && count($order->parentOrder->addresses) > 0
                    ? $order->parentOrder->addresses->first()->only('id', 'title')
                    : null);
            $data['orderInfo']['city'] = $order->city?->only('id', 'title');
            $data['orderInfo']['phone'] = (count($order->phones) > 0)
                ? $order->phones->first()->phoneTypes->map(function ($phoneType) {
                    return $phoneType->only('id', 'title');
                })
                : ($order->parentOrder && count($order->parentOrder->phones) > 0
                    ? $order->parentOrder->phones->first()->phoneTypes->map(function ($phoneType) {
                        return $phoneType->only('id', 'title');
                    })
                    : []);
        }
        if (isset($request['products']['active'])) {
            // Récupérer les produits du fournisseur avec leurs attributs de variation et types d'attributs
            $filters = HelperFunctions::filterColumns($request['products']['active'], ['title', 'addresse', 'phone', 'products']);
            $orderProducts = Order::find($id);
            $orderDataProducts = [];
            $orderProducts->activeOrderPvas->map(function ($orderPva) use (&$orderDataProducts) {
                // Créer un tableau avec les données de base du produit

                if (!isset($orderDataProducts[$orderPva->id]))
                    $orderDataProducts[$orderPva->id] = [
                        "id" => $orderPva->productVariationAttribute->product->id,
                        "title" => $orderPva->productVariationAttribute->product->title,
                        "reference" => $orderPva->productVariationAttribute->product->reference,
                        "created_at" => $orderPva->created_at,
                        "principalImage" => $orderPva->productVariationAttribute->product->principalImage,
                        "productType" => $orderPva->productVariationAttribute->product->productType->only(['id', 'title']),
                    ];
                $orderDataProducts[$orderPva->id]['productVariations'][$orderPva->id] = [
                    "id" => $orderPva->id,
                    "price" => $orderPva->price,
                    "discount" => $orderPva->discount,
                    "quantity" => $orderPva->quantity,
                    "order_status_id" => $orderPva->orderStatus->only('id', 'title'),
                ];
                $orderDataProducts[$orderPva->id]['productVariations'][$orderPva->id]['offers'] = FilterController::filterselect(new Request(), 'offers', $orderPva->id)['data'];
                $orderDataProducts[$orderPva->id]['productVariations'][$orderPva->id]['selectedOffer'] = null;
                if ($orderPva->offer_variation_id)
                    $orderDataProducts[$orderPva->id]['productVariations'][$orderPva->id]['selectedOffer'] = [
                        "id" => $orderPva->offerableVariation->childOfferableVariations->first()->offerable->offer->id,
                        "title" => $orderPva->offerableVariation->childOfferableVariations->first()->offerable->offer->title,
                    ];
                $orderDataProducts[$orderPva->id]['productVariations'][$orderPva->id]['selectedAttributes'] = $orderPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
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
                $orderDataProducts[$orderPva->id]['attributes'] = $orderPva->productVariationAttribute->product->productVariationAttributes->flatMap(function ($productVariationAttribute) {
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
        
        // Calculate comment score based on timing, considering postponed periods
        if (!function_exists('calculateDayBasedScore')) {
            require_once app_path('Helpers/OrderScoreHelper.php');
        }
        
        $commentScore = calculateDayBasedScore($order->created_at, now(), $order->id);
        
        $comment->orders()->attach($order->id, [
            'title' => ($request['title']) ? $request['title'] : $comment->title,
            'order_status_id' => $comment->parentComment->current_statut,
            'account_user_id' => getAccountUser()->account_id,
            'score' => $commentScore,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        // Update order status with score if status is changing
        if ($comment->is_change) {
            $statusScore = calculateDayBasedScore($order->created_at, now(), $order->id);
            $order->orderStatuses()->attach($comment->parentComment->current_statut, [
                'account_user_id' => getAccountUser()->id,
                'statut' => 1,
                'score' => $statusScore,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
        
        // Recalculate total order score
        // Note: No longer updating orders table score since we removed the column
        // Score is now calculated on-demand from account_user_order_status and order_comment tables
        
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
            $request['note'] = isset($request['note']) ? $request['note'] : null;
            $request['order_status_id'] = $comment['statut'];
            $order_only = collect($request)->only('warehouse_id', 'discount',  'order_status_id',  'city_id', 'brand_source_id', 'payment_type_id', 'payment_method_id', 'pickup_id', 'real_carrier_price', 'shipment_id','shipping_code','note','meta','carrier_price');
            
            $order->update($order_only->all());
            $order->activePvas()->update(['order_status_id' => $comment['statut']]);
            //hna kanvérifier wach la commande 3endha parent ila kane 3endha déja parent o parent 3endo child 
            //hna bach ncrée commande d retour pour les commandes CH 
            if ($order->parentOrder) {
                $dataReturn["account_id"] = getAccountUser()->account_id;
                $dataReturn['order_status_id'] = 8;
                $dataReturn['customer_id'] = $order->parentOrder->customer_id;
                $dataReturn['city_id'] = $order->parentOrder->city_id;
                $dataReturn['payment_type_id'] = $order->parentOrder->payment_type_id;
                $dataReturn['payment_method_id'] = $order->parentOrder->payment_method_id;
                $dataReturn['brand_source_id'] = $order->parentOrder->brand_source_id;
                $dataReturn['principale'] = 1;
                $dataReturn['warehouse_id'] = $order->warehouse_id;
                $dataReturn['pickup_id'] = $order->pickup_id;
                $dataReturn['order_id'] = $order->parentOrder->id;
                $dataReturn['code'] = $order->parentOrder->code . "PR";
                $dataReturn['is_change'] = $comment['is_change'];
                $dataReturn['carrier_price'] = isset($request['comment']['carrier_price']) ? $request['comment']['carrier_price'] : 0;
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
                $dataNew['carrier_price'] = isset($request['comment']['carrier_price']) ? $request['comment']['carrier_price'] : 0;
                $dataNew['discount'] = isset($request['comment']['discount']) ? $request['comment']['discount'] : 0;
                $order_new = collect($dataNew)->only('code', 'warehouse_id', 'adresse', 'city_id', 'brand_source_id', 'payment_type_id', 'payment_method_id', 'customer_id', 'order_status_id', 'account_id', 'carrier_price', 'order_id');
                //Générer une nouvelle commande pour échanger la commande principale
                $newOrder = Order::create($order_new->all());
                $newOrder->orderStatuses()->attach($newOrder->order_status_id, [
                    'account_user_id' => getAccountUser()->id, 
                    'statut' => 1, 
                    'score' => 10, // Initial score for new order status
                    'created_at' => now(), 
                    'updated_at' => now()
                ]);
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
                // Note: No longer updating order score in orders table since we removed the column
                // Score is now calculated on-demand from account_user_order_status and order_comment tables
                
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
