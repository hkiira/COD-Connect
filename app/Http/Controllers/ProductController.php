<?php

namespace App\Http\Controllers;

use App\Models\Measurement;
use Illuminate\Http\Request;
use App\Models\Attribute;
use App\Models\Warehouse;
use App\Models\WarehousePva;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierPva;
use App\Models\AccountUser;
use App\Models\AccountProduct;
use App\Models\Account;
use App\Models\OrderPva;
use App\Models\SupplierOrderPva;
use App\Models\Brand;
use App\Models\Image;
use App\Models\ProductVariationAttribute;
use App\Models\Offer;
use App\Models\Offerable;
use App\Models\Taxonomy;
use App\Models\PvaMeasurement;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\VariationAttributesController;
use App\Http\Controllers\HelperFunctions;
use App\Models\BrandSource;

class ProductController extends Controller
{

    public static function index(Request $request, $local = 0, $columns = ['id', 'title', 'reference', 'price', 'image', 'suppliers', 'images', 'product_type', 'offers', 'depot_attributes'], $offerController = false, $paginate = false)
    {
        $searchIds = [];
        $account = getAccountUser()->account_id;
        $request = collect($request->query())->toArray();
        
        if (
            isset($request['brands']) && array_filter($request['brands'], function ($value) {
                return $value !== null;
            })
        ) {
            $brandIds = array_filter($request['brands'], function ($value) {
                return $value !== null;
            });
            $brands = Brand::whereIn('id', $brandIds)->with('offers.productVariationAttributes', 'offers.products')->get();
            $dataProduct = [];
            foreach ($brands as $brand) {
                foreach ($brand->offers as $offer) {
                    $dataProduct = array_merge($dataProduct, $offer->productVariationAttributes->pluck('product_id')->unique()->toArray());
                    $dataProduct = array_merge($dataProduct, $offer->products->pluck('id')->toArray());
                }
            }
            $request['whereArray'] = ['column' => 'id', 'values' => array_unique($dataProduct)];
        }

        if (
            isset($request['suppliers']) && array_filter($request['suppliers'], function ($value) {
                return $value !== null;
            })
        ) {
            $supplierIds = array_filter($request['suppliers'], function ($value) {
                return $value !== null;
            });
            $suppliers = Supplier::whereIn('id', $supplierIds)->with('productVariationAttributes')->get();
            $searchIds = $suppliers->flatMap(function ($supplier) {
                return $supplier->productVariationAttributes->pluck('product_id');
            })->unique()->toArray();
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }

        if (
            isset($request['offers']) && array_filter($request['offers'], function ($value) {
                return $value !== null;
            })
        ) {
            $offerIds = array_filter($request['offers'], function ($value) {
                return $value !== null;
            });
            $offers = Offer::whereIn('id', $offerIds)->with('productVariationAttributes', 'products')->get();
            $searchIds = $offers->flatMap(function ($offer) {
                return array_merge(
                    $offer->productVariationAttributes->pluck('product_id')->toArray(),
                    $offer->products->pluck('id')->toArray()
                );
            })->unique()->toArray();
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }

        if (
            isset($request['attributes']) && array_filter($request['attributes'], function ($value) {
                return $value !== null;
            })
        ) {
            $attributeIds = array_filter($request['attributes'], function ($value) {
                return $value !== null;
            });
            $attributes = Attribute::whereIn('id', $attributeIds)->with('variationAttributes.parentVariationAttribute.productVariationAttributes')->get();
            $searchIds = $attributes->flatMap(function ($attribute) {
                return $attribute->variationAttributes->flatMap(function ($variationAttribute) {
                    return $variationAttribute->parentVariationAttribute->productVariationAttributes->pluck('product_id');
                });
            })->unique()->toArray();
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }

        if (
            isset($request['categories']) && array_filter($request['categories'], function ($value) {
                return $value !== null;
            })
        ) {
            $categoryIds = array_filter($request['categories'], function ($value) {
                return $value !== null;
            });
            $taxonomies = Taxonomy::whereIn('id', $categoryIds)->with('taxonomyProducts.accountProduct')->get();
            $searchIds = $taxonomies->flatMap(function ($taxonomy) {
                return $taxonomy->taxonomyProducts->pluck('accountProduct.product_id');
            })->unique()->toArray();
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }

        if (
            isset($request['warehouses']) && array_filter($request['warehouses'], function ($value) {
                return $value !== null;
            })
        ) {
            $warehouseIds = array_filter($request['warehouses'], function ($value) {
                return $value !== null;
            });
            $warehouses = Warehouse::whereIn('id', $warehouseIds)->with('productVariationAttributes')->get();
            $searchIds = $warehouses->flatMap(function ($warehouse) {
                return $warehouse->productVariationAttributes->pluck('product_id');
            })->unique()->toArray();
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        $columns = ['id', 'title', 'reference', 'created_at', 'product_type_id'];
        $request['inAccountUser'] = ['account_user_id', $account];
        $request['statut'] = 1;

        $search = $request['search'] ?? null;
        $startDate = $request['startDate'] ?? null;
        $endDate = $request['endDate'] ?? null;
        $paginationCurrentPage = $request['pagination']['current_page'] ?? 0;
        $paginationPerPage = $request['pagination']['per_page'] ?? 10;
        $sorts = $request['sort'] ?? [['column' => 'created_at', 'order' => 'DESC']];

        $productsQuery = Product::with([
            'productType',
            'accountProducts.taxonomies',
            'activePvas.suppliers',
            'activePvas.warehousePvas.warehouse',
            'activePvas.variationAttribute.childVariationAttributes.attribute',
            'activePvas.activeWarehouses',
            'activePvas.activeSuppliers',
            'activePvas.images',
            'price',
            'principalImage',
            'images',
            'offers',
        ]);

        if ($search !== null && $search !== '') {
            $productsQuery->where(function ($query) use ($columns, $search) {
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', "%{$search}%");
                }
            });
        }

        if (isset($request['whereHas'])) {
            $productsQuery->whereHas($request['whereHas']);
        }

        if (isset($request['whereDoesntHave'])) {
            $productsQuery->whereDoesntHave($request['whereDoesntHave']['table'], function ($query) use ($request) {
                $query->where($request['whereDoesntHave']['column'], $request['whereDoesntHave']['value']);
            });
        }

        if (isset($request['inAccount'])) {
            $productsQuery->where($request['inAccount'][0], $request['inAccount'][1]);
        }

        if (isset($request['statut'])) {
            $productsQuery->where('statut', $request['statut']);
        }

        if (isset($request['inAccountUser'])) {
            $accountUsers = AccountUser::where('account_id', $request['inAccountUser'][1])->pluck('id')->toArray();
            $productsQuery->whereIn($request['inAccountUser'][0], $accountUsers);
        }

        if (isset($request['where'])) {
            $productsQuery->where($request['where']['column'], $request['where']['value']);
        }

        if (isset($request['wheres'])) {
            foreach ($request['wheres'] as $where) {
                $productsQuery->where($where['column'], $where['value']);
            }
        }

        if (isset($request['whereNots'])) {
            foreach ($request['whereNots'] as $whereNot) {
                $productsQuery->whereNot($whereNot['column'], $whereNot['value']);
            }
        }

        if (isset($request['whereArray'])) {
            $productsQuery->whereIn($request['whereArray']['column'], $request['whereArray']['values']);
        }

        if (isset($request['whereNot'])) {
            $productsQuery->where($request['whereNot']['column'], '!=', $request['whereNot']['value'])
                ->orWhere($request['whereNot']['column'], null);
        }

        if (isset($request['whereNotArray'])) {
            $productsQuery->whereNotIn($request['whereNotArray']['column'], $request['whereNotArray']['values']);
        }

        if (isset($request['whereIn'])) {
            foreach ($request['whereIn'] as $whereIn) {
                $productsQuery->whereHas($whereIn['table'], function ($query) use ($whereIn) {
                    $query->where($whereIn['column'], $whereIn['value']);
                });
            }
        }

        if (isset($request['whereNotIn'])) {
            foreach ($request['whereNotIn'] as $whereNotIn) {
                $productsQuery->whereDoesntHave($whereNotIn['table'], function ($query) use ($whereNotIn) {
                    $query->where($whereNotIn['column'], $whereNotIn['value']);
                });
            }
        }

        if ($startDate && $endDate) {
            $productsQuery->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        }

        foreach ($sorts as $sort) {
            $sortColumn = $sort['column'] ?? 'created_at';
            $sortOrder = $sort['order'] ?? 'DESC';
            $productsQuery->orderBy($sortColumn, $sortOrder);
        }

        $productsCollection = $productsQuery->get();
        $totalRows = $productsCollection->count();
        $perPage = ($paginationPerPage == null || $paginationPerPage == 0) ? 10 : (int) $paginationPerPage;
        $currentPage = ($paginationCurrentPage == null) ? 1 : ((int) $paginationCurrentPage + 1);

        $products = [
            'statut' => 1,
            'data'   => $productsCollection->forPage($currentPage, $perPage)->values(),
            'meta'   => [
                'total'        => $totalRows,
                'per_page'     => $perPage,
                'current_page' => $currentPage,
            ],
        ];
        
        // Get all PVA IDs from products to batch load related data
        $pvaIds = collect($products['data'])->flatMap(function ($product) {
            return $product->activePvas->pluck('id');
        })->unique()->toArray();
        
        // Batch load all required data for PVAs
        $orderPvas = !empty($pvaIds) ? OrderPva::whereIn('product_variation_attribute_id', $pvaIds)
            ->whereIn('order_status_id', [1, 4, 5, 8, 9])
            ->get()
            ->groupBy('product_variation_attribute_id') : collect();
        
        $supplierOrderPvas = !empty($pvaIds) ? SupplierOrderPva::whereIn('product_variation_attribute_id', $pvaIds)
            ->where('statut', 1)
            ->get()
            ->groupBy('product_variation_attribute_id') : collect();
        
        $warehousePvas = !empty($pvaIds) ? WarehousePva::whereIn('product_variation_attribute_id', $pvaIds)
            ->get()
            ->groupBy('product_variation_attribute_id') : collect();
        
        $products['data'] = collect($products['data'])->map(function ($product) use ($account, $orderPvas, $supplierOrderPvas, $warehousePvas) {
            $productData = [
                'id' => $product->id,
                'title' => $product->title,
                'statut' => $product->statut,
                'price' => $product->price->first()->price,
                'image' => collect($product->principalImage->only('id', 'photo', 'photo_dir')),
                'reference' => $product->reference,
                'created_at' => $product->created_at,
                'depot_attributes' => $product->activePvas->map(function ($pva) use ($orderPvas, $supplierOrderPvas, $warehousePvas) {
                    $pvaOrderPvas = $orderPvas->get($pva->id, collect());
                    $pvaSupplierOrderPvas = $supplierOrderPvas->get($pva->id, collect());
                    $pvaWarehousePvas = $warehousePvas->get($pva->id, collect());
                    
                    $deliveryOrders = $pvaOrderPvas->whereIn('order_status_id', [5, 8, 9]);
                    $pendingOrders = $pvaOrderPvas->whereIn('order_status_id', [1, 4, 5]);
                    
                    $delivery = $deliveryOrders->sum('quantity');
                    $pending = $pendingOrders->sum('quantity');
                    $ordered = $pvaSupplierOrderPvas->sum('quantity');
                    $realStock = $pvaWarehousePvas->sum('quantity');
                    
                    return [
                        'id' => $pva->id,
                        'variation_attributes_id' => $pva->variationAttribute->id ?? null,
                        'delivery' => $delivery,
                        'real' => $realStock,
                        'available' => $realStock - $pending,
                        'images' => $pva->images,
                        'ordered' => $ordered,
                        'attribute' => isset($pva->variationAttribute->childVariationAttributes) ? implode(',', $pva->variationAttribute->childVariationAttributes->map(function ($childV) {
                            return $childV->attribute->title;
                        })->toArray()) : null
                    ];
                }),
                'categories' => $product->accountProducts->where('account_id', $account)->flatMap(function ($accountProduct) {
                    return $accountProduct->taxonomies->where('type_taxonomy_id', 1)->map(function ($taxonomy) {
                        return $taxonomy->only('id', 'title');
                    });
                }),
                'warehouses' => $product->activePvas->flatMap(function ($pva) {
                    return $pva->activeWarehouses->where('warehouse_type_id', 1)->map(function ($activeWarehouse) {
                        return $activeWarehouse->only('id', 'title');
                    });
                })->unique(),
                'suppliers' => $product->activePvas->flatMap(function ($pva) {
                    return $pva->activeSuppliers;
                })->unique('title')->values(),
                'images' => $product->images,
                'product_type' => $product->productType->only('id', 'code', 'title'),
                'offers' => $product->offers,
            ];
            return $productData;
        });
        return $products;
    }


    public function create(Request $request)
    {
        //transformer les données sous des array
        $request = collect($request->query())->toArray();
        $products = [];

        $normalize = function ($payload, $columns = []) {
            $payload = is_array($payload) ? $payload : [];
            $filters = [];
            foreach ($columns as $column) {
                $filters[$column] = $payload['filters'][$column] ?? null;
            }
            return [
                'search' => $payload['search'] ?? null,
                'filters' => $filters,
                'pagination' => [
                    'current_page' => $payload['pagination']['current_page'] ?? 0,
                    'per_page' => $payload['pagination']['per_page'] ?? 10,
                ],
                'sort' => $payload['sort'] ?? [['column' => 'created_at', 'order' => 'DESC']],
            ];
        };

        $applySearchAndSort = function ($query, $params, $columns) {
            if (!empty($params['search'])) {
                $search = $params['search'];
                $query->where(function ($subQuery) use ($columns, $search) {
                    foreach ($columns as $column) {
                        $subQuery->orWhere($column, 'like', "%{$search}%");
                    }
                });
            }

            if (!empty($params['sort'])) {
                foreach ($params['sort'] as $sort) {
                    $query->orderBy($sort['column'] ?? 'created_at', $sort['order'] ?? 'DESC');
                }
            }

            return $query;
        };

        if (isset($request['taxonomies']['inactive'])) {
            $filters = $normalize($request['taxonomies']['inactive'], ['title', 'description']);
            $model = 'App\\Models\\Taxonomy';
            $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
            $categories = $model::whereNull('taxonomy_id')->with(['images', 'childTaxonomies'])->where('type_taxonomy_id', 1)->whereIn('account_user_id', $accountUsers)->get();
            $formattedCategories = [];
            foreach ($categories as $category) {
                $formattedCategories[] = TaxonomyController::formatTaxonomy($category);
            }
            $products['taxonomies']['inactive'] = HelperFunctions::getPagination(collect($formattedCategories), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['tags']['inactive'])) {
            $filters = $normalize($request['tags']['inactive'], ['title', 'description']);
            $model = 'App\\Models\\Taxonomy';
            $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
            $tags = $model::whereNull('taxonomy_id')->with(['images', 'childTaxonomies'])->where('type_taxonomy_id', 2)->whereIn('account_user_id', $accountUsers)->get();
            $formattedTags = [];
            foreach ($tags as $tag) {
                $formattedTags[] = TaxonomyController::formatTaxonomy($tag);
            }
            $products['tags']['inactive'] = HelperFunctions::getPagination(collect($formattedTags), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['suppliers']['inactive'])) {
            $filters = $normalize($request['suppliers']['inactive'], ['id', 'title']);
            $query = Supplier::with('images')->where('account_id', getAccountUser()->account_id);
            $suppliers = $applySearchAndSort($query, $filters, ['id', 'title'])->get();
            $products['suppliers']['inactive'] = HelperFunctions::getPagination($suppliers, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        if (isset($request['offers']['inactive'])) {
            $filters = $normalize($request['offers']['inactive'], ['id', 'title']);
            $query = Offer::where('account_id', getAccountUser()->account_id)
                ->where(function ($q) {
                    $q->where('offer_type_id', '!=', 1)->orWhereNull('offer_type_id');
                });
            $offers = $applySearchAndSort($query, $filters, ['id', 'title'])->get();
            $products['offers']['inactive'] = HelperFunctions::getPagination($offers, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        if (isset($request['warehouses']['inactive'])) {
            $filters = $normalize($request['warehouses']['inactive'], ['id', 'title']);
            $query = Warehouse::where('warehouse_type_id', 1)
                ->where('account_id', getAccountUser()->account_id);
            $warehouses = $applySearchAndSort($query, $filters, ['id', 'title'])->get();
            $products['warehouses']['inactive'] = HelperFunctions::getPagination($warehouses, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        if (isset($request['products']['inactive'])) {
            $columns = ['id', 'title', 'reference', 'images', 'product_type', 'variation_attributes'];
            $account = getAccountUser()->account_id;
            $filters = $normalize($request['products']['inactive'], ['reference', 'title', 'shipping_price', 'suppliers', 'variations', 'offers']);
            $products = Account::with([
                'products' => function ($query) use ($account, $request, $filters) {
                    $query->with(['images', 'productType']);
                    if ($filters['search'] == null) {
                        if ($filters['filters']['reference'] != null) {
                            $query->where('reference', 'like', "%{$filters['filters']['reference']}%");
                        }
                        if ($filters['filters']['title'] != null) {
                            $query->Where('title', 'like', "%{$filters['filters']['title']}%");
                        }
                    } else {
                        $query->where('reference', 'like', "%{$filters['search']}%")
                            ->orWhere('title', 'like', "%{$filters['search']}%");
                    }
                    $query->with([
                        'activePvas' => function ($query) use ($account) {
                            $query->with([
                                'variationAttribute' => function ($query) {
                                    $query->with('attributes');
                                }
                            ]);
                        }
                    ]);
                }
            ])->find($account)->products->map(function ($product) use ($columns, $filters) {
                $product->variation_attributes = $variation_attributes = $product->activePvas->map(function ($pva) {
                    $depots = [];
                    $attributes = $pva->variationAttribute->attributes->map(function ($attr) {
                        return $attr->title;
                    })->toArray();
                    $variationAttributes_id = $pva->variationAttribute->id;
                    return array_merge(
                        ['id' => $pva->id, 'attribute' => implode("-", $attributes)],
                        $depots,
                        ["variation_attributes_id" => $variationAttributes_id]
                    );
                });
                $product->product_type = ['id' => $product->productType->id, 'title' => $product->productType->title];
                $product->images = $product->images->map(function ($image) {
                    return $image->only('id', 'photo', 'photo_dir');
                });
                $variationsExisting = HelperFunctions::filterExisting($filters['filters']['variations'], $variation_attributes->pluck('variation_attributes_id'));
                $product->id = $product->accountProducts->first()->id;
                if ($variationsExisting) {
                    return $product->only($columns);
                }
            })->filter()->values();

            $products['variations']['inactive'] = HelperFunctions::getPagination($products, intval($filters['pagination']['per_page']), intval($filters['pagination']['current_page']));
        }

        if (isset($request['variations']['inactive'])) {
            $filters = $normalize($request['variations']['inactive'], ['id', 'title']);
            $accountUsers = AccountUser::where(['account_id' => getAccountUser()->account_id])->pluck('id')->toArray();
            $query = \App\Models\TypeAttribute::with('attributes')
                ->whereIn('account_user_id', $accountUsers);
            $variations = $applySearchAndSort($query, $filters, ['id', 'title'])->get();
            $products['variations']['inactive'] = HelperFunctions::getPagination($variations, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        return response()->json([
            'statut' => 1,
            'data' => $products,
        ]);
    }

    public static function store(Request $requests)
    {
        $account = getAccountUser()->account_id;
        $users = AccountUser::where(['account_id' => $account, 'statut' => 1])->get()->pluck('id')->toArray();

        $existsById = function (string $model, ?callable $scope = null) {
            return function ($attribute, $value, $fail) use ($model, $scope) {
                $query = $model::query()->where('id', $value);
                if ($scope) {
                    $scope($query, $value, $attribute);
                }
                if (!$query->exists()) {
                    $fail('not exist');
                }
            };
        };

        $existsInAccount = function (string $model, array $extraConditions = [], ?callable $scope = null) use ($account, $existsById) {
            return $existsById($model, function ($query) use ($account, $extraConditions, $scope) {
                $query->where('account_id', $account)->where($extraConditions);
                if ($scope) {
                    $scope($query);
                }
            });
        };

        $existsInUsers = function (string $model) use ($users, $existsById) {
            return $existsById($model, function ($query) use ($users) {
                $query->whereIn('account_user_id', $users);
            });
        };

        $validateAvailableImage = function (string $imageableType) use ($account) {
            return function ($attribute, $value, $fail) use ($account, $imageableType) {
                $image = Image::where(['id' => $value, 'account_id' => $account])->first();
                if (!$image) {
                    $fail('not exist');
                    return;
                }

                $alreadyAttached = \App\Models\Imageable::where('image_id', $image->id)
                    ->where('imageable_type', $imageableType)
                    ->exists();

                if ($alreadyAttached) {
                    $fail('exist');
                }
            };
        };

        $validator = Validator::make($requests->except('_method'), [
            '*.default_measurement_id' => 'exists:measurements,id',
            '*.product_type_id' => 'exists:product_types,id',
            '*.measurements.*.id' => 'exists:measurements,id',
            '*.measurements.*.quantity' => 'numeric',
            '*.price' => 'required|numeric',
            '*.title' => ['required', 'string', 'max:255'],
            '*.reference' => ['required', 'string', 'max:255'],
            '*.warehouses.*' => [
                $existsInAccount(Warehouse::class, ['warehouse_type_id' => 1]),
            ],
            '*.brands.*' => [
                $existsInAccount(Brand::class, ['statut' => 1]),
            ],
            '*.categories.*' => [
                $existsInUsers(Taxonomy::class),
            ],
            '*.suppliers' => 'array',
            '*.suppliers.*.id' => [
                'sometimes',
                'required',
                $existsInAccount(Supplier::class),
            ],
            '*.suppliers.*.price' => 'sometimes|required|numeric',
            '*.attributes.*' => [
                $existsInUsers(Attribute::class),
            ],
            '*.productVariationAttributes.id' => [
                $existsInAccount(ProductVariationAttribute::class),
            ],
            '*.productVariationAttributes.quantity' => "numeric",
            '*.offers.*' => [
                'string',
                $existsInAccount(Offer::class, [], function ($query) {
                    $query->where('offer_type_id', '!=', 1);
                }),
            ],
            '*.images.*' => [
                'string',
                $validateAvailableImage("App\Models\Product"),
            ],
            '*.imageVariations.*.image' => [
                'string',
                $validateAvailableImage("App\Models\ProductVariationAttribute"),
            ],
            
            '*.imageVariations.*.attribute.*' => [
                'string',
                $existsInUsers(Attribute::class),
            ],
            '*.imageVariations.*.attributes.*' => [
                'string',
                $existsInUsers(Attribute::class),
            ],
            '*.principalImage' => [
                'string',
                $validateAvailableImage("App\Models\Product"),
            ],
            '*.statut' => 'required',
            '*.newImages.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }
        $products = collect($requests->except('_method'))->map(function ($request) {
            $request["account_user_id"] = getAccountUser()->id;
            $request['code'] = DefaultCodeController::getAccountCode('Product', getAccountUser()->account_id);
            $request['product_type_id'] = $request['product_type_id'] ?? 1;
            $product_only = collect($request)->only('code', 'title', 'reference', 'statut', 'account_user_id', 'product_type_id');

            $product = Product::create($product_only->all());
            $accountProduct = AccountProduct::create([
                "product_id" => $product->id,
                "account_id" => getAccountUser()->account_id,
                "statut" => 1
            ]);
            //definir une offre de type par défault pour définir le prix de base du produit
            $dafaultOffer = Offer::create([
                'code' => DefaultCodeController::getAccountCode('Offer', getAccountUser()->account_id),
                'title' => $product->title,
                'price' => $request['price'],
                'account_id' => getAccountUser()->account_id,
                'offer_type_id' => 1
            ]);
            $dafaultOffer->products()->syncWithoutDetaching([$product->id => ['account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);

            if (isset($request['categories'])) {
                foreach ($request['categories'] as $key => $categoryId) {
                    $category = Taxonomy::find($categoryId);
                    $category->products()->syncWithoutDetaching([$accountProduct->id => ['created_at' => now(), 'updated_at' => now(), 'statut' => 1]]);
                }
            }
            if (isset($request['brands'])) {
                foreach ($request['brands'] as $key => $brandId) {
                    $brand = Brand::find($brandId);
                    $brand->brand_sources->map(function ($brandSource) use ($product) {
                        $product->brandSources()->syncWithoutDetaching([$brandSource->id => ['statut' => 1, 'created_at' => now(), 'account_user_id' => getAccountUser()->id, 'updated_at' => now()]]);
                    });
                }
            }
            if ($product->product_type_id == 1) {

                //organiser les attributes par type d'attribute (par ex: couleur,taille,etc...)
                $attributeByTypes = [];
                if (isset($request['attributes'])) {
                    foreach ($request['attributes'] as $key => $attributeId) {
                        $attribute = Attribute::where('id', $attributeId)->first();
                        $attributeByTypes[$attribute->types_attribute_id][] = $attribute->id;
                    }
                }
                //enregistrer les différentes variation entre les types et récupérer les Ids
                $variationAttributes = VariationAttributesController::store(new Request(array_values($attributeByTypes)), 1, 0);
                foreach ($variationAttributes as $key => $variationAttributetId) {
                    $productVariation = ProductVariationAttribute::create([
                        "account_id" => getAccountUser()->account_id,
                        "code" => 'REF:' . $product->reference . $variationAttributetId,
                        "product_id" => $product->id,
                        "variation_attribute_id" => $variationAttributetId,
                        "statut" => 1
                    ]);
                    //definir la mesure par défault pour les différentes pva
                    $measurement = PvaMeasurement::create(['product_variation_attribute_id' => $productVariation->id, 'measurement_id' => 1, 'quantity' => 1]);
                    //definir les différentes mesures du pva qui rassemble le mesure par défault (par ex : Carton, Sachet, Palette , etc ...)
                    if (isset($request['measurements'])) {
                        foreach ($request['measurements'] as $measurementData) {
                            $measurement = Measurement::find($measurementData['id']);
                            $productVariation->measurements()->syncWithoutDetaching([$measurement->id => ['quantity' => $measurementData['quantity'], 'created_at' => now(), 'updated_at' => now()]]);
                        }
                    }

                    //definir les offres pour chaque pva 
                    if (isset($request['offers'])) {
                        foreach ($request['offers'] as $offerId) {
                            $offer = Offer::find($offerId);
                            $productVariation->offers()->syncWithoutDetaching([$offer->id => ['created_at' => now(), 'updated_at' => now(), 'statut' => 1, 'account_user_id' => getAccountUser()->id]]);
                        }
                    }
                    //definir les offres pour chaque pva 

                    if (isset($request['warehouses'])) {
                        foreach ($request['warehouses'] as $warehouseId) {
                            $warehouse = Warehouse::find($warehouseId);
                            $productVariation->warehouses()->syncWithoutDetaching([$warehouse->id => ['quantity' => 0, 'created_at' => now(), 'updated_at' => now()]]);
                        }
                    }
                    
                    if (isset($request['suppliers'])) {
                        foreach ($request['suppliers'] as $supplierData) {
                            $supplier = Supplier::find($supplierData['id']);
                            $productVariation->suppliers()->syncWithoutDetaching([$supplier->id => ["account_id" => getAccountUser()->account_id, 'price' => $supplierData['price'], 'created_at' => now(), 'updated_at' => now(), 'statut' => 1]]);
                        }
                    }
                }
            } else {
                $productVariation = ProductVariationAttribute::create([
                    "account_id" => getAccountUser()->account_id,
                    "code" => 'REF:' . $product->reference,
                    "product_id" => $product->id,
                    "statut" => 1
                ]);
                $newRequest = new Request(['id' => $productVariation->id, 'productVariationAttributes' => $request['productVariationAttributes']]);
                $pvaPack = PvaPackController::store($newRequest, 1);

                if (isset($request['warehouses']) && $product->product_type_id == 3) {
                    foreach ($request['warehouses'] as $warehouseId) {
                        $warehouse = Warehouse::find($warehouseId);
                        $productVariation->warehouses()->syncWithoutDetaching([$warehouse->id => ['quantity' => 0, 'created_at' => now(), 'updated_at' => now()]]);
                    }
                }
            }

            $images = [];
            if (isset($request['newImages'])) {
                foreach ($request['newImages'] as $key => $newImage) {
                    $images[] = ["image" => $newImage];
                }
            }
            if (isset($request['newPrincipalImage'])) {
                $images[] = ["image" => $request['newPrincipalImage'], "as_principal" => true];
            }
            $imageData = [
                'title' => $product->title,
                'type' => 'product',
                'image_type_id' => 2,
                'images' => $images
            ];
            if (!empty($images)) {
                $image = ImageController::store(new Request([$imageData]), $product, false);
            }

            if (isset($request['newPrincipalImage']) && isset($request['principalImage'])) {
                $image = Image::find($request['principalImage'])->first();
                $product->images()->attach($image->id, ['created_at' => now(), 'updated_at' => now(), 'statut' => 2]);
            } elseif (isset($request['principalImage'])) {
                $image = Image::find($request['principalImage'])->first();
                $product->images()->attach($image->id, ['created_at' => now(), 'updated_at' => now(), 'statut' => 1]);
            }
            if (isset($request['images'])) {
                foreach ($request['images'] as $imageInfo) {
                    $image = Image::find($imageInfo)->first();
                    $product->images()->attach($image->id, ['created_at' => now(), 'updated_at' => now(), 'statut' => 2]);
                }
            }

            return $product;
        });

        return response()->json([
            'statut' => 1,
            'data' => $products,
        ]);
    }


    public function show($id)
    {
        $account = getAccountUser()->account_id;
        $accountUsers = AccountUser::where('account_id', $account)->pluck('id')->toArray();

        $product = Product::with([
            'productType',
            'accountProducts.taxonomies',
            'activePvas.suppliers',
            'activePvas.warehousePvas.warehouse',
            'activePvas.variationAttribute.childVariationAttributes.attribute',
            'activePvas.activeWarehouses',
            'activePvas.activeSuppliers',
            'activePvas.images',
            'price',
            'principalImage',
            'images',
            'offers',
        ])
            ->whereIn('account_user_id', $accountUsers)
            ->where('statut', 1)
            ->find($id);

        if (!$product) {
            return response()->json(['statut' => 0, 'data' => 'not exist'], 404);
        }

        $pvaIds = $product->activePvas->pluck('id')->toArray();

        $orderPvas = !empty($pvaIds)
            ? OrderPva::whereIn('product_variation_attribute_id', $pvaIds)
                ->whereIn('order_status_id', [1, 4, 5, 8, 9])
                ->get()
                ->groupBy('product_variation_attribute_id')
            : collect();

        $supplierOrderPvas = !empty($pvaIds)
            ? SupplierOrderPva::whereIn('product_variation_attribute_id', $pvaIds)
                ->where('statut', 1)
                ->get()
                ->groupBy('product_variation_attribute_id')
            : collect();

        $warehousePvas = !empty($pvaIds)
            ? WarehousePva::whereIn('product_variation_attribute_id', $pvaIds)
                ->get()
                ->groupBy('product_variation_attribute_id')
            : collect();

        $productData = [
            'id'         => $product->id,
            'title'      => $product->title,
            'statut'     => $product->statut,
            'price'      => $product->price->first()->price,
            'image'      => collect($product->principalImage->only('id', 'photo', 'photo_dir')),
            'reference'  => $product->reference,
            'created_at' => $product->created_at,
            'depot_attributes' => $product->activePvas->map(function ($pva) use ($orderPvas, $supplierOrderPvas, $warehousePvas) {
                $pvaOrderPvas        = $orderPvas->get($pva->id, collect());
                $pvaSupplierOrderPvas = $supplierOrderPvas->get($pva->id, collect());
                $pvaWarehousePvas    = $warehousePvas->get($pva->id, collect());

                $deliveryOrders = $pvaOrderPvas->whereIn('order_status_id', [5, 8, 9]);
                $pendingOrders  = $pvaOrderPvas->whereIn('order_status_id', [1, 4, 5]);

                $realStock = $pvaWarehousePvas->sum('quantity');

                return [
                    'id'                    => $pva->id,
                    'variation_attributes_id' => $pva->variationAttribute->id ?? null,
                    'delivery'              => $deliveryOrders->sum('quantity'),
                    'real'                  => $realStock,
                    'available'             => $realStock - $pendingOrders->sum('quantity'),
                    'images'                => $pva->images,
                    'ordered'               => $pvaSupplierOrderPvas->sum('quantity'),
                    'attribute'             => isset($pva->variationAttribute->childVariationAttributes)
                        ? implode(',', $pva->variationAttribute->childVariationAttributes->map(function ($childV) {
                            return $childV->attribute->title;
                        })->toArray())
                        : null,
                ];
            }),
            'categories' => $product->accountProducts->where('account_id', $account)->flatMap(function ($accountProduct) {
                return $accountProduct->taxonomies->where('type_taxonomy_id', 1)->map(function ($taxonomy) {
                    return $taxonomy->only('id', 'title');
                });
            }),
            'warehouses' => $product->activePvas->flatMap(function ($pva) {
                return $pva->activeWarehouses->where('warehouse_type_id', 1)->map(function ($activeWarehouse) {
                    return $activeWarehouse->only('id', 'title');
                });
            })->unique(),
            'suppliers'    => $product->activePvas->flatMap(function ($pva) {
                return $pva->activeSuppliers;
            })->unique(),
            'images'       => $product->images,
            'product_type' => $product->productType->only('id', 'code', 'title'),
            'offers'       => $product->offers,
        ];

        return response()->json(['statut' => 1, 'data' => $productData]);
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        $normalize = function ($payload, $columns = []) {
            $payload = is_array($payload) ? $payload : [];
            $filters = [];
            foreach ($columns as $column) {
                $filters[$column] = $payload['filters'][$column] ?? null;
            }
            return [
                'search' => $payload['search'] ?? null,
                'filters' => $filters,
                'pagination' => [
                    'current_page' => $payload['pagination']['current_page'] ?? 0,
                    'per_page' => $payload['pagination']['per_page'] ?? 10,
                ],
                'sort' => $payload['sort'] ?? [['column' => 'created_at', 'order' => 'DESC']],
            ];
        };

        $applySearchAndSort = function ($query, $params, $columns) {
            if (!empty($params['search'])) {
                $search = $params['search'];
                $query->where(function ($subQuery) use ($columns, $search) {
                    foreach ($columns as $column) {
                        $subQuery->orWhere($column, 'like', "%{$search}%");
                    }
                });
            }

            if (!empty($params['sort'])) {
                foreach ($params['sort'] as $sort) {
                    $query->orderBy($sort['column'] ?? 'created_at', $sort['order'] ?? 'DESC');
                }
            }

            return $query;
        };

        $product = Product::with('ProductType', 'activePvas.variationAttribute.childVariationAttributes.attribute')->find($id);
        if (!$product)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        $pvas = $product->activePvas->pluck('id')->toArray();
        if (isset($request['productInfo'])) {
            $accountId = getAccountUser()->account_id;
            $info = collect($product)->toArray();
            $info['price'] = $product->price->first()->price;
            $info['principalImage'] = $product->principalImage->toArray();
            $info['images'] = $product->images->where('pivot.statut', 1)->values()->toArray();

            $activeSupplierIds = SupplierPva::whereIn('product_variation_attribute_id', $pvas)
                ->where('statut', 1)
                ->pluck('supplier_id')
                ->unique()
                ->toArray();

            $activeSupplierPvasBySupplier = SupplierPva::whereIn('product_variation_attribute_id', $pvas)
                ->where('statut', 1)
                ->get()
                ->sortByDesc('updated_at')
                ->groupBy('supplier_id');

            $info['suppliers'] = Supplier::where('account_id', $accountId)
                ->whereIn('id', $activeSupplierIds)
                ->get()
                ->map(function ($supplier) use ($activeSupplierPvasBySupplier) {
                    $supplierData = $supplier->only('id', 'title');
                    $supplierData['price'] = optional($activeSupplierPvasBySupplier->get($supplier->id)->first())->price;
                    return $supplierData;
                })
                ->values()
                ->toArray();

            $activeWarehouseIds = WarehousePva::whereIn('product_variation_attribute_id', $pvas)
                ->where('statut', 1)
                ->pluck('warehouse_id')
                ->unique()
                ->toArray();
            $info['warehouses'] = Warehouse::where('account_id', $accountId)
                ->where('warehouse_type_id', 1)
                ->whereIn('id', $activeWarehouseIds)
                ->get()
                ->map(function ($warehouse) {
                    return $warehouse->only('id', 'title');
                })
                ->values()
                ->toArray();

            $activeBrandIds = $product->brandSources()
                ->wherePivot('statut', 1)
                ->pluck('brand_source.brand_id')
                ->unique()
                ->toArray();
            $info['brands'] = Brand::where('account_id', $accountId)
                ->whereIn('id', $activeBrandIds)
                ->get()
                ->map(function ($brand) {
                    return $brand->only('id', 'title', 'code');
                })
                ->values()
                ->toArray();

            $activeOfferIds = Offerable::where('offerable_type', Product::class)
                ->where('offerable_id', $product->id)
                ->where('statut', 1)
                ->pluck('offer_id')
                ->unique()
                ->toArray();
            $info['offers'] = Offer::where('account_id', $accountId)
                ->whereIn('id', $activeOfferIds)
                ->where(function ($q) {
                    $q->where('offer_type_id', '!=', 1)->orWhereNull('offer_type_id');
                })
                ->get()
                ->map(function ($offer) {
                    return $offer->only('id', 'title', 'code');
                })
                ->values()
                ->toArray();

            $info['taxonomies'] = $product->accountProducts
                ->where('account_id', $accountId)
                ->flatMap(function ($accountProduct) {
                    return $accountProduct->taxonomies
                        ->where('type_taxonomy_id', 1)
                        ->map(function ($taxonomy) {
                            return $taxonomy->only('id', 'title');
                        });
                })
                ->unique('id')
                ->values()
                ->toArray();

            $info['variations'] = $product->activePvas->map(function ($pva) {
                return [
                    'id' => $pva->id,
                    'variation_attributes_id' => $pva->variationAttribute->id ?? null,
                    'attributes' => $pva->variationAttribute->childVariationAttributes->map(function ($childVariation) {
                        return [
                            'id' => $childVariation->id,
                            'type' => $childVariation->attribute->TypeAttribute->title ?? null,
                            'value' => $childVariation->attribute->title,
                        ];
                    })->values(),
                ];
            })->values()->toArray();

            $data["productInfo"]['data'] = $info;
        }
        if (isset($request['taxonomies']['active'])) {
            $filters = $normalize($request['taxonomies']['active'], ['title', 'description']);
            $model = 'App\\Models\\Taxonomy';
            $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
            $categories = $model::whereNull('taxonomy_id')->with(['images', 'childTaxonomies'])->where('type_taxonomy_id', 1)->whereIn('account_user_id', $accountUsers)->get();
            $formattedCategories = [];
            $taxonomyIds = $product->accountProducts->first()->taxonomies->pluck('id')->toArray();
            foreach ($categories as $category) {
                if (in_array($category->id, $taxonomyIds))
                    $category->checked = true;
                $formattedCategories[] = TaxonomyController::formatTaxonomy($category, $taxonomyIds);
            }
            $data['taxonomies']['active'] = HelperFunctions::getPagination(collect($formattedCategories), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['taxonomies']['all'])) {
            $filters = $normalize($request['taxonomies']['all'], ['title', 'description']);
            $model = 'App\\Models\\Taxonomy';
            $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
            $categories = $model::whereNull('taxonomy_id')->with(['images', 'childTaxonomies'])->where('type_taxonomy_id', 1)->whereIn('account_user_id', $accountUsers)->get();
            $formattedCategories = [];
            $taxonomyIds = $product->accountProducts->first()->taxonomies->pluck('id')->toArray();
            foreach ($categories as $category) {
                if (in_array($category->id, $taxonomyIds))
                    $category->checked = true;
                $formattedCategories[] = TaxonomyController::formatTaxonomy($category, $taxonomyIds);
            }
            $data['taxonomies']['all'] = HelperFunctions::getPagination(collect($formattedCategories), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['suppliers']['inactive'])) {
            $filters = $normalize($request['suppliers']['inactive'], ['title', 'code']);
            $supplierPvas = SupplierPva::whereIn('product_variation_attribute_id', $pvas)->get()->pluck('supplier_id')->unique()->toArray();
            $query = Supplier::where('account_id', getAccountUser()->account_id)
                ->whereNotIn('id', $supplierPvas);
            $suppliers = $applySearchAndSort($query, $filters, ['id', 'title'])->get()->map(function ($supplier) use ($supplierPvas, $product) {
                $supplierDatas = $supplier->only('id', 'title');
                $supplierDatas['variations'] = $product->activePvas->whereNotIn('id', $supplierPvas)->map(function ($pva) {
                    $variation['id'] = $pva->id;
                    $variation['attributes'] = $pva->variationAttribute->childVariationAttributes->map(function ($childVariation) {
                        $attribute['id'] = $childVariation->id;
                        $attribute['type'] = $childVariation->attribute->TypeAttribute->title;
                        $attribute['value'] = $childVariation->attribute->title;
                        return $attribute;
                    });
                    return $variation;
                })->values();
                return $supplierDatas;
            });
            $data['suppliers']['inactive'] = HelperFunctions::getPagination(collect($suppliers), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
            $data['suppliers']['inactive']['variations'] = $product->activePvas->map(function ($pva) {
                $variation['id'] = $pva->id;
                $variation['attributes'] = $pva->variationAttribute->childVariationAttributes->map(function ($childVariation) {
                    $attribute['id'] = $childVariation->id;
                    $attribute['type'] = $childVariation->attribute->TypeAttribute->title;
                    $attribute['value'] = $childVariation->attribute->title;
                    return $attribute;
                });
                return $variation;
            });
        }
        if (isset($request['suppliers']['active'])) {
            $filters = $normalize($request['suppliers']['active'], ['title', 'code']);
            $supplierPvas = SupplierPva::whereIn('product_variation_attribute_id', $pvas)->get()->pluck('supplier_id')->unique()->toArray();
            $query = Supplier::where('account_id', getAccountUser()->account_id)
                ->whereIn('id', $supplierPvas);
            $suppliers = $applySearchAndSort($query, $filters, ['id', 'title'])->get()->map(function ($supplier) use ($pvas) {
                $supplierDatas = $supplier->only('id', 'title');
                $supplierDatas['variations'] = $supplier->activePvas->whereIn('id', $pvas)->map(function ($pva) {
                    $variation['id'] = $pva->id;
                    $variation['price'] = $pva->pivot->price;
                    $variation['attributes'] = $pva->variationAttribute->childVariationAttributes->map(function ($childVariation) {
                        $attribute['id'] = $childVariation->id;
                        $attribute['type'] = $childVariation->attribute->TypeAttribute->title;
                        $attribute['value'] = $childVariation->attribute->title;
                        return $attribute;
                    });
                    return $variation;
                })->values();
                return $supplierDatas;
            });
            $data['suppliers']['active'] = HelperFunctions::getPagination(collect($suppliers), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['suppliers']['all'])) {
            $filters = $normalize($request['suppliers']['all'], ['title', 'code']);
            $query = Supplier::where('account_id', getAccountUser()->account_id);
            $suppliers = $applySearchAndSort($query, $filters, ['id', 'title'])->get()->map(function ($supplier) use ($pvas, $product) {
                $supplierDatas = $supplier->only('id', 'title');
                $supplierActivePvas = $supplier->activePvas->whereIn('id', $pvas)->keyBy('id');
                $supplierDatas['variations'] = $product->activePvas->map(function ($pva) use ($supplierActivePvas) {
                    $variation['id'] = $pva->id;
                    $activePva = $supplierActivePvas->get($pva->id);
                    $variation['price'] = $activePva && $activePva->pivot ? $activePva->pivot->price : null;
                    $variation['attributes'] = $pva->variationAttribute->childVariationAttributes->map(function ($childVariation) {
                        $attribute['id'] = $childVariation->id;
                        $attribute['type'] = $childVariation->attribute->TypeAttribute->title;
                        $attribute['value'] = $childVariation->attribute->title;
                        return $attribute;
                    });
                    return $variation;
                })->values();
                return $supplierDatas;
            });
            $data['suppliers']['all'] = HelperFunctions::getPagination(collect($suppliers), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['warehouses']['inactive'])) {
            $filters = $normalize($request['warehouses']['inactive'], ['id', 'title']);
            $warehousePvas = WarehousePva::whereIn('product_variation_attribute_id', $pvas)->get()->pluck('warehouse_id')->unique()->toArray();
            $query = Warehouse::with('images')
                ->where('account_id', getAccountUser()->account_id)
                ->where('warehouse_type_id', 1)
                ->whereNotIn('id', $warehousePvas);
            $warehouses = $applySearchAndSort($query, $filters, ['id', 'title'])->get();
            $data['warehouses']['inactive'] = HelperFunctions::getPagination($warehouses, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        if (isset($request['warehouses']['active'])) {
            $filters = $normalize($request['warehouses']['active'], ['id', 'title']);
            $warehousePvas = WarehousePva::whereIn('product_variation_attribute_id', $pvas)->get()->pluck('warehouse_id')->unique()->toArray();
            $query = Warehouse::with('images')
                ->where('account_id', getAccountUser()->account_id)
                ->where('warehouse_type_id', 1)
                ->whereIn('id', $warehousePvas);
            $warehouses = $applySearchAndSort($query, $filters, ['id', 'title'])->get();
            $data['warehouses']['active'] = HelperFunctions::getPagination($warehouses, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['warehouses']['all'])) {
            $filters = $normalize($request['warehouses']['all'], ['id', 'title']);
            $query = Warehouse::with('images')
                ->where('account_id', getAccountUser()->account_id)
                ->where('warehouse_type_id', 1);
            $warehouses = $applySearchAndSort($query, $filters, ['id', 'title'])->get();
            $data['warehouses']['all'] = HelperFunctions::getPagination($warehouses, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['offers']['active'])) {
            $filters = $normalize($request['offers']['active'], ['id', 'title']);
            $query = Offer::where('account_id', getAccountUser()->account_id)
                ->whereIn('id', $product->offers->pluck('id')->toArray())
                ->where(function ($q) {
                    $q->where('offer_type_id', '!=', 1)->orWhereNull('offer_type_id');
                });
            $offers = $applySearchAndSort($query, $filters, ['id', 'title'])->get();
            $data['offers']['active'] = HelperFunctions::getPagination($offers, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['offers']['inactive'])) {
            $filters = $normalize($request['offers']['inactive'], ['id', 'title']);
            $query = Offer::where('account_id', getAccountUser()->account_id)
                ->whereNotIn('id', $product->offers->pluck('id')->toArray())
                ->where(function ($q) {
                    $q->where('offer_type_id', '!=', 1)->orWhereNull('offer_type_id');
                });
            $offers = $applySearchAndSort($query, $filters, ['id', 'title'])->get();
            $data['offers']['inactive'] = HelperFunctions::getPagination($offers, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['offers']['all'])) {
            $filters = $normalize($request['offers']['all'], ['id', 'title']);
            $query = Offer::where('account_id', getAccountUser()->account_id)
                ->where(function ($q) {
                    $q->where('offer_type_id', '!=', 1)->orWhereNull('offer_type_id');
                });
            $offers = $applySearchAndSort($query, $filters, ['id', 'title'])->get();
            $data['offers']['all'] = HelperFunctions::getPagination($offers, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['brands']['all'])) {
            $filters = $normalize($request['brands']['all'], ['id', 'title']);
            $query = Brand::with('images')
                ->where('account_id', getAccountUser()->account_id);
            $brands = $applySearchAndSort($query, $filters, ['id', 'title', 'code'])->get();
            $data['brands']['all'] = HelperFunctions::getPagination($brands, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        if (isset($request['pva']['active'])) {
                $filters = $normalize($request['pva']['active'], ['id', 'title']);
                $query = ProductVariationAttribute::with('variationAttribute.childVariationAttributes.attribute')
                    ->whereIn('id', $pvas);
                $pvasData = $applySearchAndSort($query, $filters, ['id', 'code'])->get()->map(function ($pva) {
                    $pvaData = $pva->only('id', 'code');
                    $pvaData['attributes'] = $pva->variationAttribute->childVariationAttributes->map(function ($childVariation) {
                        $attribute['id'] = $childVariation->id;
                        $attribute['type'] = $childVariation->attribute->TypeAttribute->title;
                        $attribute['value'] = $childVariation->attribute->title;
                        return $attribute;
                    });
                    return $pvaData;
                });
                $data['pva']['active'] = HelperFunctions::getPagination($pvasData, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['pva']['inactive'])) {}
        if (isset($request['variations']['active'])) {
            $filters = $normalize($request['variations']['active'], ['id', 'title']);
            $selectedAttributes = $product->activePvas->flatMap(function ($pva) {
                return $pva->variationAttribute->childVariationAttributes->map(function ($variationAttribute) {
                    return $variationAttribute->attribute_id;
                });
            })->unique()->values()->toArray();
            $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
            $query = \App\Models\TypeAttribute::with('attributes')
                ->whereIn('account_user_id', $accountUsers);
            $variations = $applySearchAndSort($query, $filters, ['id', 'title'])->get();
            foreach ($variations as $variation) {
                foreach ($variation->attributes as $attribute) {
                    $attribute->checked = false;
                    $variation->checked = $variation->checked ? true : false;
                    if (in_array($attribute->id, $selectedAttributes)) {
                        $attribute->checked = true;
                        $variation->checked = true;
                    }
                }
            }
            $data['variations']['active'] = HelperFunctions::getPagination(collect($variations), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['variations']['all'])) {
            $filters = $normalize($request['variations']['all'], ['id', 'title']);
            $selectedAttributes = $product->activePvas->flatMap(function ($pva) {
                return $pva->variationAttribute->childVariationAttributes->map(function ($variationAttribute) {
                    return $variationAttribute->attribute_id;
                });
            })->unique()->values()->toArray();
            $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
            $query = \App\Models\TypeAttribute::with('attributes')
                ->whereIn('account_user_id', $accountUsers);
            $attributes = $applySearchAndSort($query, $filters, ['id', 'title'])->get();
            foreach ($attributes as $attributeType) {
                foreach ($attributeType->attributes as $attribute) {
                    $attribute->checked = false;
                    $attributeType->checked = $attributeType->checked ? true : false;
                    if (in_array($attribute->id, $selectedAttributes)) {
                        $attribute->checked = true;
                        $attributeType->checked = true;
                    }
                }
            }
            $data['variations']['all'] = HelperFunctions::getPagination(collect($attributes), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }


    public function update(Request $requests, $id)
    {
        $account = getAccountUser()->account_id;
        $users = AccountUser::where(['account_id' => $account, 'statut' => 1])->get()->pluck('id')->toArray();

        $existsById = function (string $model) {
            return function ($attribute, $value, $fail) use ($model) {
                if (!$model::where('id', $value)->first()) {
                    $fail("not exist");
                }
            };
        };

        $existsInAccount = function (string $model, array $extraConditions = []) use ($account) {
            return function ($attribute, $value, $fail) use ($model, $account, $extraConditions) {
                $conditions = array_merge(['id' => $value, 'account_id' => $account], $extraConditions);
                if (!$model::where($conditions)->first()) {
                    $fail("not exist");
                }
            };
        };

        $existsInUsers = function (string $model) use ($users) {
            return function ($attribute, $value, $fail) use ($model, $users) {
                if (!$model::where('id', $value)->whereIn('account_user_id', $users)->first()) {
                    $fail("not exist");
                }
            };
        };

        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'required|exists:products,id',
            '*.reference' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($requests, $users) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('product', 'title', $value);

                    // Extract index from Taxonomy name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $requests->input("{$index}.id"); // Get ID from request
                    $titleModel = Product::where('title', $value)->whereIn('account_user_id', $users)->first();
                    $idModel = Product::where('id', $id)->whereIn('account_user_id', $users)->first(); // Find model by ID

                    // Check if a country with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.title' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($requests, $users) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('Product', 'title', $value);

                    // Extract index from Taxonomy name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $requests->input("{$index}.id"); // Get ID from request
                    $titleModel = Product::where('title', $value)->whereIn('account_user_id', $users)->first();
                    $idModel = Product::where('id', $id)->whereIn('account_user_id', $users)->first(); // Find model by ID

                    // Check if a country with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.warehousesToActive.*' => [$existsInAccount(Warehouse::class, ['warehouse_type_id' => 1])],
            '*.warehousesToInactive.*' => [$existsInAccount(Warehouse::class, ['warehouse_type_id' => 1])],
            '*.taxonomiesToActive.*' => [$existsById(Taxonomy::class)],
            '*.taxonomiesToInactive.*' => [$existsById(Taxonomy::class)],
            '*.suppliersToActive.*.price' => 'required',
            '*.suppliersToActive.*.id' => [$existsInAccount(Supplier::class)],
            '*.suppliersToInactive.*' => [$existsInAccount(Supplier::class)],
            '*.attributes.*' => [$existsInUsers(Attribute::class)],
            '*.brandsToActive.*' => [$existsInAccount(Brand::class)],
            '*.brandsToInactive.*' => [$existsInAccount(Brand::class)],
            '*.offersToActive.*' => [$existsInAccount(Offer::class)],
            '*.offersToInactive.*' => [$existsInAccount(Offer::class)],
            '*.imageVariations.*.image' => ['string', $existsInAccount(Image::class)],
            '*.imageVariations.*.attributes.*' => [$existsById(Attribute::class)],
            '*.images.*' => [
                'string',
                $existsInAccount(Image::class),
            ],
            '*.principalImage' => [
                'string',
                $existsInAccount(Image::class),
            ],
            '*.newImages.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }
        //update mazal khassni nfker liha f logique dialha mezian 7ite fiha 3 type de produits de préférence 7ta ndwiw fiha ensemble
        $products = collect($requests->except('_method'))->map(function ($request) {
            $request["account_user_id"] = getAccountUser()->id;
            $product = Product::find($request['id']);
            if (!$product) {
                return null;
            }
            // $request["reference"]=$product->reference;
            // $request["title"]=$product->title;
            $product_only = collect($request)->only('title', 'reference', 'statut');
            $attributeByTypes = [];
            $product->update($product_only->all());
            if (isset($request['price'])) {
                $currentPrice = $product->price->first();
                if (!$currentPrice || doubleVal($request['price']) != $currentPrice->price) {
                    if ($currentPrice) {
                        $currentPrice->update(['statut' => 0]);
                    }
                    //definir une offre de type par défault pour définir le prix de base du produit
                    $dafaultOffer = Offer::create([
                        'code' => DefaultCodeController::getAccountCode('Offer', getAccountUser()->account_id),
                        'title' => $product->title,
                        'price' => $request['price'],
                        'account_id' => getAccountUser()->account_id,
                        'offer_type_id' => 1
                    ]);
                    $dafaultOffer->products()->syncWithoutDetaching([$product->id => ['account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }
            if (isset($request['attributes'])) {
                foreach ($request['attributes'] as $key => $attributeId) {
                    $attribute = Attribute::where('id', $attributeId)->first();
                    if (!$attribute) {
                        continue;
                    }
                    $attributeByTypes[$attribute->types_attribute_id][] = $attribute->id;
                }
            }

            // Handle taxonomiesToActive
            if (isset($request['taxonomiesToActive'])) {
                $accountProduct = $product->accountProducts->where('account_id', getAccountUser()->account_id)->first();
                if (!$accountProduct) {
                    $accountProduct = $product->accountProducts->first();
                }
                foreach ($request['taxonomiesToActive'] as $taxonomyId) {
                    $taxonomy = Taxonomy::find($taxonomyId);
                    if (!$taxonomy || !$accountProduct) {
                        continue;
                    }
                    $taxonomy->products()->syncWithoutDetaching([
                        $accountProduct->id => [
                            'created_at' => now(),
                            'updated_at' => now(),
                            'statut' => 1
                        ]
                    ]);
                }
            }

            // Handle taxonomiesToInactive
            if (isset($request['taxonomiesToInactive'])) {
                $accountProduct = $product->accountProducts->where('account_id', getAccountUser()->account_id)->first();
                if (!$accountProduct) {
                    $accountProduct = $product->accountProducts->first();
                }
                foreach ($request['taxonomiesToInactive'] as $taxonomyId) {
                    $taxonomy = Taxonomy::find($taxonomyId);
                    if (!$taxonomy || !$accountProduct) {
                        continue;
                    }
                    $taxonomy->products()->detach($accountProduct->id);
                }
            }

            if (isset($request['brandsToActive'])) {
                foreach ($request['brandsToActive'] as $key => $brandId) {
                    $brand = Brand::find($brandId);
                    if (!$brand) {
                        continue;
                    }
                    $brand->brand_sources->map(function ($brandSource) use ($product) {
                        $product->brandSources()->syncWithoutDetaching([$brandSource->id => ['statut' => 1, 'created_at' => now(), 'account_user_id' => getAccountUser()->id, 'updated_at' => now()]]);
                    });
                }
            }
            if (isset($request['brandsToInactive'])) {
                foreach ($request['brandsToInactive'] as $key => $brandId) {
                    $brand = Brand::find($brandId);
                    if (!$brand) {
                        continue;
                    }
                    $brand->brand_sources->map(function ($brandSource) use ($product) {
                        $product->brandSources()->syncWithoutDetaching([$brandSource->id => ['statut' => 0, 'created_at' => now(), 'account_user_id' => getAccountUser()->id, 'updated_at' => now()]]);
                    });
                }
            }
            if (isset($request['offersToActive'])) {
                foreach ($request['offersToActive'] as $key => $offerId) {
                    $offer = Offer::find($offerId);
                    if (!$offer) {
                        continue;
                    }
                    $offer->products()->syncWithoutDetaching([
                        $product->id => [
                            'account_user_id' => getAccountUser()->id,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]
                    ]);
                }
            }
            if (isset($request['offersToInactive'])) {
                foreach ($request['offersToInactive'] as $key => $offerId) {
                    $offer = Offer::find($offerId);
                    if (!$offer) {
                        continue;
                    }
                    if ($offer->products->where('id', $product->id)->first())
                        $offer->products()->syncWithoutDetaching([
                            $product->id => [
                                'statut' => 0,
                                'updated_at' => now()
                            ]
                        ]);
                }
            }
            foreach ($product->productVariationAttributes as $key => $pvaToInactive) {
                if (isset($request['warehousesToInactive'])) {
                    foreach ($request['warehousesToInactive'] as $key => $warehouseId) {
                        $warehouse = Warehouse::find($warehouseId);
                        if (!$warehouse) {
                            continue;
                        }
                        if ($warehouse->productVariationAttributes->where('id', $pvaToInactive->id)->first())
                            $warehouse->products()->syncWithoutDetaching([
                                $pvaToInactive->id => [
                                    'statut' => 0,
                                    'updated_at' => now(),
                                ]
                            ]);
                    }
                }
                if (isset($request['suppliersToInactive'])) {
                    foreach ($request['suppliersToInactive'] as $key => $supplierId) {
                        $supplier = Supplier::find($supplierId);
                        if (!$supplier) {
                            continue;
                        }
                        if ($supplier->productVariationAttributes->where('id', $pvaToInactive->id)->first())
                            $supplier->productVariationAttributes()->syncWithoutDetaching([
                                $pvaToInactive->id => [
                                    'statut' => 0,
                                    'updated_at' => now(),
                                ]
                            ]);
                    }
                }
            }
            foreach($product->productVariationAttributes as $key => $pvaToInactive) {
                    $pvaToInactive->update(['statut' => 0]);
            }
            $variationAttributes = VariationAttributesController::store(new Request(array_values($attributeByTypes)), 1, 0);
            foreach ($variationAttributes as $key => $variationAttributetId) {
                $productVariation = ProductVariationAttribute::where(['product_id' => $product->id, 'variation_attribute_id' => $variationAttributetId])->first();
                if (!$productVariation) {
                    $productVariation = ProductVariationAttribute::create([
                        "account_id" => getAccountUser()->account_id,
                        "code" => 'REF:' . $product->reference . $variationAttributetId,
                        "product_id" => $product->id,
                        "variation_attribute_id" => $variationAttributetId,
                        "statut" => 1
                    ]);
                } else {
                    $productVariation->update(['statut' => 1]);
                }

                if (isset($request['warehousesToActive'])) {
                    foreach ($request['warehousesToActive'] as $warehouseId) {
                        $warehouse = Warehouse::find($warehouseId);
                        if (!$warehouse) {
                            continue;
                        }
                        if ($warehouse->productVariationAttributes->where('id', $productVariation->id)->first()) {
                            $warehouse->products()->syncWithoutDetaching([
                                $productVariation->id => [
                                    'statut' => 1,
                                    'updated_at' => now(),
                                ]
                            ]);
                        } else {
                            $warehouse->products()->syncWithoutDetaching([
                                $productVariation->id => [
                                    'statut' => 1,
                                    'quantity' => 0,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]
                            ]);
                        }
                    }
                }
            }

            if (isset($request['imageVariations'])) {
                foreach ($request['imageVariations'] as $key => $imageVariation) {
                    if(isset($imageVariation['image'])){
                        $pvaId= $product->productVariationAttributes->map( function($pva) use ($imageVariation){
                            if (!$pva->variationAttribute) {
                                return null;
                            }
                            $variationAttributes= $pva->variationAttribute->childVariationAttributes->pluck(['attribute_id'])->toArray();
                            if($variationAttributes==$imageVariation['attributes']){
                                return $pva->id;
                            }
                            })->filter()->values()->first();
                        if($pvaId){
                            $productVariationAttribute=productVariationAttribute::find($pvaId);
                            if (!$productVariationAttribute) {
                                continue;
                            }
                            $imagePva[] = ["image" => $imageVariation['image'], "as_principal" => true];
                            $dataImage = [
                                'title' => $product->title,
                                'type' => 'productVariationAttribute',
                                'image_type_id' => 17,
                                'images' => $imagePva
                            ];
                            if ($dataImage) {
                                $image = ImageController::store(new Request([$dataImage]), $productVariationAttribute, false);
                            }
                        }
                    }
                }
            }
            if (isset($request['suppliersToActive'])) {
                foreach ($request['suppliersToActive'] as $supplierData) {
                    if (!isset($supplierData['id'],$supplierData['price'])) {
                        continue;
                    }
                    $supplier = Supplier::find($supplierData['id']);
                    if (!$supplier) {
                        continue;
                    }
                    $productVariations = ProductVariationAttribute::where(['product_id' => $product->id])->get()->map(function ($productVariation) use ($supplierData,$supplier) {
                        if ($productVariation) {
                            $productVariation->suppliers()->syncWithoutDetaching([$supplier->id => ["account_id" => getAccountUser()->account_id, 'price' => $supplierData['price'], 'created_at' => now(), 'updated_at' => now(), 'statut' => 1]]);
                            if ($supplier->productVariationAttributes->where('id', $productVariation->id)->first()) {
                                $supplier->productVariationAttributes()->syncWithoutDetaching([
                                    $productVariation->id => [
                                        'statut' => 1,
                                        'price' => $supplierData['price'],
                                        'updated_at' => now(),
                                    ]
                                ]);
                            } else {
                                $supplier->productVariationAttributes()->syncWithoutDetaching([
                                    $productVariation->id => [
                                        'statut' => 1,
                                        'price' => $supplierData['price'],
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]
                                ]);
                            }
                        }
                    })->filter();
                    
                }
            }
            $images = [];
            if (isset($request['newImages'])) {
                foreach ($request['newImages'] as $key => $newImage) {
                    $images[]["image"] = $newImage;
                }
            }
            if (isset($request['newPrincipalImage'])) {
                $images[] = ["image" => $request['newPrincipalImage'], "as_principal" => true];
            }
            $imageData = [
                'title' => $product->title,
                'type' => 'product',
                'image_type_id' => 2,
                'images' => $images
            ];
            if (!empty($images)) {
                $image = ImageController::store(new Request([$imageData]), $product, false);
            }
            if (isset($request['newPrincipalImage']) && isset($request['principalImage'])) {
                $image = Image::find($request['principalImage']);
                if ($image) {
                    $product->images()->attach($image->id, ['created_at' => now(), 'updated_at' => now(), 'statut' => 2]);
                }
            } elseif (isset($request['principalImage'])) {
                $image = Image::find($request['principalImage']);
                if ($image) {
                    $product->images()->attach($image->id, ['created_at' => now(), 'updated_at' => now(), 'statut' => 1]);
                }
            }
            if (isset($request['images'])) {
                foreach ($request['images'] as $imageInfo) {
                    $image = Image::find($imageInfo);
                    if ($image) {
                        $product->images()->attach($image->id, ['created_at' => now(), 'updated_at' => now(), 'statut' => 2]);
                    }
                }
            }
            $product=Product::find($product->id);
            return $product;
        })->filter()->values();

        return response()->json([
            'statut' => 1,
            'data' => $products,
        ]);
    }


    public function destroy($id)
    {
        $product = Product::find($id);
        $product->delete();
        return response()->json([
            'statut' => 1,
            'data' => $product,
        ]);
    }

    public function updateVariationImages(Request $request, $id)
    {
        $product = Product::with('activePvas.imageables')->find($id);
        if (!$product) {
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        }

        $validator = Validator::make($request->all(), [
            'variations' => 'required|array|min:1',
            'variations.*.id' => [
                'required',
                function ($attribute, $value, $fail) use ($product) {
                    $exists = $product->activePvas->contains('id', $value);
                    if (!$exists) {
                        $fail('not exist');
                    }
                },
            ],
            'variations.*.images' => 'nullable|array',
            'variations.*.images.*' => [
                'integer',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $image = Image::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$image) {
                        $fail('not exist');
                    }
                },
            ],
            'variations.*.principalImage' => [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $image = Image::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$image) {
                        $fail('not exist');
                    }
                },
            ],
            'variations.*.newImages' => 'nullable|array',
            'variations.*.newImages.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'variations.*.newPrincipalImage' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }

        $updatedVariations = collect($request->input('variations', []))->map(function ($variationData) use ($request, $product) {
            $pva = $product->activePvas->firstWhere('id', $variationData['id']);
            if (!$pva) {
                return null;
            }

            $existingSecondaryIds = collect($variationData['images'] ?? [])->filter()->unique()->values();
            $existingPrincipalId = $variationData['principalImage'] ?? null;

            $newImageFiles = $request->file('variations.' . $variationData['id'] . '.newImages', []);
            if (empty($newImageFiles)) {
                $newImageFiles = $request->file('variations.' . array_search($variationData['id'], array_column($request->input('variations', []), 'id')) . '.newImages', []);
            }

            $newPrincipalFile = $request->file('variations.' . $variationData['id'] . '.newPrincipalImage');
            if (!$newPrincipalFile) {
                $variationIndex = array_search($variationData['id'], array_column($request->input('variations', []), 'id'));
                if ($variationIndex !== false) {
                    $newPrincipalFile = $request->file('variations.' . $variationIndex . '.newPrincipalImage');
                }
            }

            $imagesPayload = [];
            foreach ($newImageFiles as $file) {
                $imagesPayload[] = ['image' => $file];
            }
            if ($newPrincipalFile) {
                $imagesPayload[] = ['image' => $newPrincipalFile, 'as_principal' => true];
            }

            if (!empty($imagesPayload)) {
                $imageData = [
                    'title' => $product->title,
                    'type' => 'productVariationAttribute',
                    'image_type_id' => 17,
                    'images' => $imagesPayload,
                ];
                ImageController::store(new Request([$imageData]), $pva, false);
                $pva->load('images');
            }

            $currentImageables = $pva->imageables()->get();
            $desiredSecondaryIds = $existingSecondaryIds->values()->all();
            $desiredPrincipalId = $existingPrincipalId;

            foreach ($currentImageables as $imageable) {
                if ($desiredPrincipalId && (int) $imageable->image_id === (int) $desiredPrincipalId) {
                    $imageable->update(['statut' => 2]);
                } elseif (in_array((int) $imageable->image_id, array_map('intval', $desiredSecondaryIds), true)) {
                    $imageable->update(['statut' => 1]);
                } else {
                    $imageable->update(['statut' => 0]);
                }
            }

            if ($desiredPrincipalId) {
                $hasPrincipal = $currentImageables->contains('image_id', $desiredPrincipalId);
                if (!$hasPrincipal) {
                    $pva->images()->attach($desiredPrincipalId, ['created_at' => now(), 'updated_at' => now(), 'statut' => 2]);
                }
            }

            foreach ($desiredSecondaryIds as $imageId) {
                $hasSecondary = $currentImageables->contains('image_id', $imageId);
                if (!$hasSecondary) {
                    $pva->images()->attach($imageId, ['created_at' => now(), 'updated_at' => now(), 'statut' => 1]);
                }
            }

            $pva = ProductVariationAttribute::with('principalImage', 'images')->find($pva->id);
            return [
                'id' => $pva->id,
                'principalImage' => $pva->principalImage->first(),
                'images' => $pva->images->where('pivot.statut', 1)->values(),
            ];
        })->filter()->values();

        return response()->json([
            'statut' => 1,
            'data' => $updatedVariations,
        ]);
    }


    public function store_product_suppliers($supplier_id, $suppliers)
    {
        $product_variationAttribute = product::find($supplier_id)
            ->suppliers()->attach($suppliers);
    }

    /*  public static function update_product_variationAttributes($product_id, $account_id, $variations)
    {
        // change variations
        $product = product::find($product_id);
        $product_variationAttributes = product::find($product_id)->product_variationAttribute;
        foreach ($product_variationAttributes as $product_variationAttribute) {
            // dd($product_variationAttribute->variationAttribute_id, $variations);
            if (in_array($product_variationAttribute->variationAttribute_id, $variations) == true) {
                // dd($product_variationAttribute->statut, $variations);

                if ($product_variationAttribute->statut == 0) {
                    // dd($product_variationAttribute);
                    product_variationAttribute::where('id', $product_variationAttribute->id)->update(['statut' => 1]);
                }

            } else {
                product_variationAttribute::where('id', $product_variationAttribute->id)->update(['statut' => 0]);
            }
        }
        foreach ($variations as $variation) {
            $exist = collect($product_variationAttributes)->contains('variationAttribute_id', $variation);
            if ($exist == false) {
                // $product->attributes()->attach($variation);
                $product->variationAttributes()->attach($variation, ['account_user_id' => 1]);

                $depots = account::find($account_id)->depots('id')->pluck('id')->all();
                product_variationAttribute::where(['variationAttribute_id' => $variation, 'product_id' => $product_id])->first()
                    ->depots()->attach($depots);
            }
        }
        return true;
    }
    public static function update_product_offers($account_product_id, $offers, $toActivity = 1)
    {
        // change attributes
        $account_product = account_product::find($account_product_id);
        $product_offers = account_product::find($account_product_id)->product_offer;
        $statut = $toActivity == 1 ? 0 : 1;
        $changed = [];
        foreach ($product_offers as $product_offer) {
            if (in_array($product_offer->offer_id, $offers) == true) {
                if ($product_offer->statut == $statut) {
                    product_offer::where('id', $product_offer->id)->update(['statut' => !$statut]);
                    array_push($changed, $product_offer->toArray());
                }

            }
        }
        if ($toActivity = 1) {
            foreach ($offers as $offer) {
                $exist = collect($product_offers)->contains('offer_id', $offer);
                if ($exist == false) {
                    $account_product->offers()->attach($offer);
                    array_push($changed, product_offer::firstWhere([
                        'offer_id' => $offer,
                        'account_product_id' => $account_product_id
                    ]));
                }
            }
        }
        return $changed;
    }
    public static function update_product_suppliers($productId, $suppliers, $toActivity = 1, $supplierPrice)
    {
        // change attributes
        $product = product::find($productId);
        $product_suppliers = product::find($productId)->product_supplier;
        $statut = $toActivity == 1 ? 0 : 1;
        $changed = [];
        foreach ($product_suppliers as $product_supplier) {
            if (in_array($product_supplier->supplier_id, $suppliers) == true) {
                if ($product_supplier->status == $statut || $product_supplier->price != $supplierPrice) {
                    product_supplier::where('id', $product_supplier->id)->update(['status' => !$statut, 'price' => $supplierPrice]);
                    array_push($changed, $product_supplier->toArray());
                }
            }
        }
        if ($toActivity = 1) {
            foreach ($suppliers as $supplier) {
                $exist = collect($product_suppliers)->contains('supplier_id', $supplier);
                if ($exist == false) {
                    $product->suppliers()->attach($supplier, ['price', $supplierPrice]);
                    array_push($changed, product_supplier::firstWhere([
                        'supplier_id' => $supplier,
                        'product_id' => $productId
                    ]));
                }
            }
        }

        return $changed;
    }*/
}
