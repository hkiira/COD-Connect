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
        $associated[] = [
            'model' => 'App\\Models\\ProductType',
            'title' => 'productType',
            'search' => true,
        ];
        $associated[] = [
            'model' => 'App\\Models\\AccountProduct',
            'title' => 'accountProducts.Taxonomies',
            'search' => true,
        ];
        $associated[] = [
            'model' => 'App\\Models\\ProductVariationAttribute',
            'title' => 'productVariationAttributes.suppliers',
            'search' => true,
        ];
        $associated[] = [
            'model' => 'App\\Models\\ProductVariationAttribute',
            'title' => 'productVariationAttributes.warehousePvas.warehouse',
            'search' => true,
        ];
        $associated[] = [
            'model' => 'App\\Models\\Offer',
            'title' => 'price',
            'search' => true,
        ];
        $model = 'App\\Models\\Product';
        $request['inAccountUser'] = ['account_user_id', $account];
        $request['statut'] = 1;
        $filters = HelperFunctions::filterColumns($request, $columns);
        $products = FilterController::searchs(new Request($request), $model, $columns, true, $associated);
        
        // Get all PVA IDs from products to batch load related data
        $pvaIds = collect($products['data'])->flatMap(function ($product) {
            return $product->productVariationAttributes->pluck('id');
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
                'depot_attributes' => $product->productVariationAttributes->map(function ($pva) use ($orderPvas, $supplierOrderPvas, $warehousePvas) {
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
                        'iamges' => $pva->images,
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
                'warehouses' => $product->productVariationAttributes->flatMap(function ($pva) {
                    return $pva->activeWarehouses->where('warehouse_type_id', 1)->map(function ($activeWarehouse) {
                        return $activeWarehouse->only('id', 'title');
                    });
                })->unique(),
                'suppliers' => $product->productVariationAttributes->flatMap(function ($pva) {
                    return $pva->activeSuppliers;
                })->unique(),
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
        if (isset($request['taxonomies']['inactive'])) {
            $filters = HelperFunctions::filterColumns($request['taxonomies']['inactive'], ['title', 'description']);
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
            $filters = HelperFunctions::filterColumns($request['tags']['inactive'], ['title', 'description']);
            $model = 'App\\Models\\Taxonomy';
            $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
            $tags = $model::whereNull('taxonomy_id')->with(['images', 'childTaxonomies'])->where('type_taxonomy_id', 2)->whereIn('account_user_id', $accountUsers)->get();
            $formattedTags = [];
            foreach ($tags as $tag) {
                $formattedTags[] = TaxonomyController::formatTaxonomy($tag);
            }
            $products['tags']['inactive'] = HelperFunctions::getPagination(collect($formattedTags), $request['tags']['inactive']['pagination']['per_page'], $request['tags']['inactive']['pagination']['current_page']);
        }
        if (isset($request['suppliers']['inactive'])) {
            $model = 'App\\Models\\Supplier';
            $request['suppliers']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            //permet de récupérer la liste des regions inactive filtrés
            $products['suppliers']['inactive'] = FilterController::searchs(new Request($request['suppliers']['inactive']), $model, ['id', 'title'], true, [['model' => 'App\\Models\\Images', 'title' => 'images', 'search' => true]]);
        }

        if (isset($request['offers']['inactive'])) {
            $model = 'App\\Models\\Offer';
            $request['offers']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['offers']['inactive']['whereNot'] = ['column' => 'offer_type_id', 'value' => 1];
            //permet de récupérer la liste des regions inactive filtrés
            $products['offers']['inactive'] = FilterController::searchs(new Request($request['offers']['inactive']), $model, ['id', 'title'], true, []);
        }

        if (isset($request['warehouses']['inactive'])) {
            $model = 'App\\Models\\Warehouse';
            $associated = [];
            $request['warehouses']['inactive']['where'] = ['column' => 'warehouse_type_id', 'value' => 1];
            $request['warehouses']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $products['warehouses']['inactive'] = FilterController::searchs(new Request($request['warehouses']['inactive']), $model, ['id', 'title'], true, $associated);
        }

        if (isset($request['products']['inactive'])) {
            $columns = ['id', 'title', 'reference', 'images', 'product_type', 'variation_attributes'];
            $account = getAccountUser()->account_id;
            $filters = HelperFunctions::filterColumns($request['products']['inactive'], ['reference', 'title', 'shipping_price', 'suppliers', 'variations', 'offers']);
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
                        'productVariationAttributes' => function ($query) use ($account) {
                            $query->with([
                                'variationAttribute' => function ($query) {
                                    $query->with('attributes');
                                }
                            ]);
                        }
                    ]);
                }
            ])->find($account)->products->map(function ($product) use ($columns, $filters) {
                $product->variation_attributes = $variation_attributes = $product->productVariationAttributes->map(function ($pva) {
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
            $model = 'App\\Models\\TypeAttribute';
            $associated = [];
            $associated[] = [
                'model' => 'App\\Models\\Attribute',
                'title' => 'attributes',
                'search' => true,
            ];
            $accountUsers = AccountUser::where(['account_id' => getAccountUser()->account_id])->pluck('id')->toArray();
            $request['variations']['inactive']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $products['variations']['inactive'] = FilterController::searchs(new Request($request['variations']['inactive']), $model, ['id', 'title'], true, $associated);
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
        $validator = Validator::make($requests->except('_method'), [
            '*.default_measurement_id' => 'exists:measurements,id',
            '*.product_type_id' => 'required|exists:product_types,id',
            '*.measurements.*.id' => 'exists:measurements,id',
            '*.measurements.*.quantity' => 'numeric',
            '*.price' => 'required|numeric',
            '*.title' => [
                'required',
                'max:255',
                function ($attribute, $value, $fail) use ($users) {
                    $hasTitle = Product::where('title', $value)
                        ->whereIn('account_user_id', $users)
                        ->first();
                    // a supprimer !
                    // if (!$hasTitle) {
                    //     $fail("exist");
                    // }
                },
            ],
            '*.reference' => [
                'required',
                'max:255',
                function ($attribute, $value, $fail) use ($users) {
                    $user = getAccountUser()->account_id;
                    $hasTitle = Product::where('reference', $value)
                        ->whereIn('account_user_id', $users)
                        ->first();

                    // if ($hasTitle) {
                    //     $fail("exist");
                    // }
                },
            ],
            '*.warehouses.*' => [
                function ($attribute, $value, $fail) use ($account) {
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account, 'warehouse_type_id' => 1])->first();
                    if (!$warehouse) {
                        $fail("not exist");
                    }
                },
            ],
            '*.brands.*' => [
                function ($attribute, $value, $fail) use ($account) {
                    $brand = Brand::where(['id' => $value, 'account_id' => $account, 'statut' => 1])->first();
                    if (!$brand) {
                        $fail("not exist");
                    }
                },
            ],
            '*.categories.*' => [
                function ($attribute, $value, $fail) use ($users) {
                    $category = Taxonomy::where('id', $value)
                        ->whereIn('account_user_id', $users)
                        ->first();
                    if (!$category) {
                        $fail("not exist");
                    }
                },
            ],
            '*.suppliers' => 'array',
            '*.suppliers.*.id' => [
                'sometimes',
                'required',
                function ($attribute, $value, $fail) use ($account) {
                    $supplier = Supplier::where(['id' => $value, 'account_id' => $account])
                        ->first();
                    if (!$supplier) {
                        $fail("not exist");
                    }
                },
            ],
            '*.suppliers.*.price' => 'sometimes|required',
            '*.attributes.*' => [
                function ($attribute, $value, $fail) use ($users) {
                    $attribute = Attribute::where('id', $value)
                        ->whereIn('account_user_id', $users)
                        ->first();
                    if (!$attribute) {
                        $fail("not exist");
                    }
                },
            ],
            '*.productVariationAttributes.id' => [
                function ($attribute, $value, $fail) use ($account) {
                    $attribute = ProductVariationAttribute::where('id', $value)
                        ->where('account_id', $account)
                        ->first();
                    if (!$attribute) {
                        $fail("not exist");
                    }
                },
            ],
            '*.productVariationAttributes.quantity' => "numeric",
            '*.offers.*' => [
                'string',
                function ($attribute, $value, $fail) use ($account) {
                    $offer = Offer::where(['id' => $value, 'account_id' => $account])->whereNot('offer_type_id', 1)->first();
                    if (!$offer) {
                        $fail("not exist");
                    }
                },
            ],
            '*.images.*' => [
                'string',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $image = Image::where(['id' => $value, 'account_id' => $account])->first();
                    if ($image) {
                        $isUnique = \App\Models\Imageable::where('image_id', $image->id)
                            ->where('imageable_type', "App\Models\Product")
                            ->first();
                        if ($isUnique) {
                            $fail("exist");
                        }
                    } else {
                        $fail("not exist");
                    }
                },
            ],
            '*.imageVariations.*.image' => [
                'string',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $image = Image::where(['id' => $value, 'account_id' => $account])->first();
                    if ($image) {
                        $isUnique = \App\Models\Imageable::where('image_id', $image->id)
                            ->where('imageable_type', "App\Models\ProductVariationAttribute")
                            ->first();
                        if ($isUnique) {
                            $fail("exist");
                        }
                    } else {
                        $fail("not exist");
                    }
                },
            ],
            
            '*.imageVariations.*.attribute.*' => [
                'string',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $attributeP = Attribute::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$attributeP) 
                        $fail("not exist");
                },
            ],
            '*.principalImage' => [
                'string',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $image = Image::where(['id' => $value, 'account_id' => $account])->first();
                    if ($image) {
                        $isUnique = \App\Models\Imageable::where('image_id', $image->id)
                            ->where('imageable_type', "App\Models\Product")
                            ->first();
                        if ($isUnique) {
                            $fail("exist");
                        }
                    } else {
                        $fail("not exist");
                    }
                },
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
                    $brand->brand_sources()->map(function ($brandSource) use ($product) {
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
                }
                if (isset($request['suppliers'])) {
                    foreach ($request['suppliers'] as $supplierData) {
                        $supplier = Supplier::find($supplierData['id']);
                        $productVariation = ProductVariationAttribute::where(['variation_attribute_id' => $supplierData['variation_id'], 'product_id' => $product->id])->get()->first();
                        if ($productVariation)
                            $productVariation->suppliers()->syncWithoutDetaching([$supplier->id => ["account_id" => getAccountUser()->account_id, 'price' => $supplierData['price'], 'created_at' => now(), 'updated_at' => now(), 'statut' => 1]]);
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
            if ($imageData) {
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
        //
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        $product = Product::with('ProductType')->find($id);
        if (!$product)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        $pvas = ProductVariationAttribute::where('product_id', $product->id)->get()->pluck('id')->toArray();
        if (isset($request['productInfo'])) {
            $info = collect($product)->toArray();
            $info['price'] = $product->price->first()->price;
            $info['principalImage'] = $product->principalImage->toArray();
            $info['images'] = $product->images->where('pivot.statut', 1)->values()->toArray();
            $data["productInfo"]['data'] = $info;
        }
        if (isset($request['taxonomies']['active'])) {
            $filters = HelperFunctions::filterColumns($request['taxonomies']['active'], ['title', 'description']);
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
        if (isset($request['suppliers']['inactive'])) {
            $filters = HelperFunctions::filterColumns($request['suppliers']['inactive'], ['title', 'code']);
            $model = 'App\\Models\\Supplier';
            $supplierPvas = SupplierPva::whereIn('product_variation_attribute_id', $pvas)->get()->pluck('supplier_id')->unique()->toArray();
            $request['suppliers']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['suppliers']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $supplierPvas];
            //permet de récupérer la liste des regions inactive filtrés
            $suppliers = FilterController::searchs(new Request($request['suppliers']['inactive']), $model, ['id', 'title'], false, [])->map(function ($supplier) use ($supplierPvas, $product) {
                $supplierDatas = $supplier->only('id', 'title');
                $supplierDatas['variations'] = $product->productVariationAttributes->whereNotIn('id', $supplierPvas)->map(function ($pva) {
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
            $data['suppliers']['inactive']['variations'] = $product->productVariationAttributes->map(function ($pva) {
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
            $filters = HelperFunctions::filterColumns($request['suppliers']['active'], ['title', 'code']);
            $model = 'App\\Models\\Supplier';
            $supplierPvas = SupplierPva::whereIn('product_variation_attribute_id', $pvas)->get()->pluck('supplier_id')->unique()->toArray();
            $request['suppliers']['active']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['suppliers']['active']['whereArray'] = ['column' => 'id', 'values' => $supplierPvas];
            //permet de récupérer la liste des regions active filtrés
            $suppliers = FilterController::searchs(new Request($request['suppliers']['active']), $model, ['id', 'title'], false, [])->map(function ($supplier) use ($pvas) {
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
        if (isset($request['warehouses']['inactive'])) {
            $model = 'App\\Models\\Warehouse';
            $warehousePvas = WarehousePva::whereIn('product_variation_attribute_id', $pvas)->get()->pluck('warehouse_id')->unique()->toArray();
            $request['warehouses']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['warehouses']['inactive']['where'] = ['column' => 'warehouse_type_id', 'value' => 1];
            $request['warehouses']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $warehousePvas];
            //permet de récupérer la liste des regions inactive filtrés
            $data['warehouses']['inactive'] = FilterController::searchs(new Request($request['warehouses']['inactive']), $model, ['id', 'title'], true, [['model' => 'App\\Models\\Images', 'title' => 'images', 'search' => true]]);
        }

        if (isset($request['warehouses']['active'])) {
            $model = 'App\\Models\\Warehouse';
            $warehousePvas = WarehousePva::whereIn('product_variation_attribute_id', $pvas)->get()->pluck('warehouse_id')->unique()->toArray();
            $request['warehouses']['active']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['warehouses']['active']['where'] = ['column' => 'warehouse_type_id', 'value' => 1];
            $request['warehouses']['active']['whereArray'] = ['column' => 'id', 'values' => $warehousePvas];
            //permet de récupérer la liste des regions active filtrés
            $data['warehouses']['active'] = FilterController::searchs(new Request($request['warehouses']['active']), $model, ['id', 'title'], true, [['model' => 'App\\Models\\Images', 'title' => 'images', 'search' => true]]);
        }
        if (isset($request['offers']['active'])) {
            $model = 'App\\Models\\Offer';
            $request['offers']['active']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['offers']['active']['whereArray'] = ['column' => 'id', 'values' => $product->offers->pluck('id')->toArray()];
            $request['offers']['active']['whereNot'] = ['column' => 'offer_type_id', 'value' => 1];
            //permet de récupérer la liste des regions active filtrés
            $data['offers']['active'] = FilterController::searchs(new Request($request['offers']['active']), $model, ['id', 'title'], true, []);
        }
        if (isset($request['offers']['inactive'])) {
            $model = 'App\\Models\\Offer';
            $request['offers']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['offers']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $product->offers->pluck('id')->toArray()];
            $request['offers']['inactive']['whereNot'] = ['column' => 'offer_type_id', 'value' => 1];
            //permet de récupérer la liste des regions inactive filtrés
            $data['offers']['inactive'] = FilterController::searchs(new Request($request['offers']['inactive']), $model, ['id', 'title'], true, []);
        }

        if (isset($request['variations']['active'])) {
            $model = 'App\\Models\\TypeAttribute';
            $associated[] = [
                'model' => 'App\\Models\\Attribute',
                'title' => 'attributes',
                'search' => true,
            ];
            $selectedAttributes = $product->productVariationAttributes->flatMap(function ($pva) {
                return $pva->variationAttribute->childVariationAttributes->map(function ($variationAttribute) {
                    return $variationAttribute->attribute_id;
                });
            })->unique()->values()->toArray();
            $request['variations']['active']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $variations = FilterController::searchs(new Request($request['variations']['active']), $model, ['id', 'title'], false, $associated);
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
            $filters = HelperFunctions::filterColumns($request['variations']['active'], ['id', 'title']);
            $data['variations']['active'] = HelperFunctions::getPagination(collect($variations), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }


    public function update(Request $requests, $id)
    {
        //$variations = VariationAttributesController::store(new Request($request->only('variations')), 1);
        // tester si il n'y a pas probleme en validation et aprés enregistrer si il ya de nouvaux attributs
        $account = getAccountUser()->account_id;
        $users = AccountUser::where(['account_id' => $account, 'statut' => 1])->get()->pluck('id')->toArray();
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
            '*.warehousesToActive.*' => [
                function ($attribute, $value, $fail) use ($account) {
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account, 'warehouse_type_id' => 1])->first();
                    if (!$warehouse) {
                        $fail("not exist");
                    }
                },
            ],
            '*.warehousesToInactive.*' => [
                function ($attribute, $value, $fail) use ($account) {
                    //remarque 7ta nrje3 nchof l'illimité f les sous warehouses
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account, 'warehouse_type_id' => 1])->first();
                    if (!$warehouse) {
                        $fail("not exist");
                    }
                },
            ],
            '*.categories.*' => [
                function ($attribute, $value, $fail) use ($users) {
                    $category = Taxonomy::where('id', $value)
                        ->whereIn('account_user_id', $users)
                        ->first();
                    if (!$category) {
                        $fail("not exist");
                    }
                },
            ],
            '*.suppliersToActive.*.price' => 'required',
            '*.suppliersToActive.*.id' => [
                function ($attribute, $value, $fail) use ($account) {
                    $supplier = Supplier::where(['id' => $value, 'account_id' => $account])
                        ->first();
                    if (!$supplier) {
                        $fail("not exist");
                    }
                },
            ],
            '*.suppliersToInactive.*' => [
                function ($attribute, $value, $fail) use ($account) {
                    $supplier = Supplier::where(['id' => $value, 'account_id' => $account])
                        ->first();
                    if (!$supplier) {
                        $fail("not exist");
                    }
                },
            ],
            '*.attributesToActive.*' => [
                function ($attribute, $value, $fail) use ($users) {
                    $attribute = Attribute::where('id', $value)
                        ->whereIn('account_user_id', $users)
                        ->first();
                    if (!$attribute) {
                        $fail("not exist");
                    }
                },
            ],
            '*.attributesToInactive.*' => [
                function ($attribute, $value, $fail) use ($users) {
                    $attribute = Attribute::where('id', $value)
                        ->whereIn('account_user_id', $users)
                        ->first();
                    if (!$attribute) {
                        $fail("not exist");
                    }
                },
            ],
            '*.brandsToActive.*' => [
                function ($attribute, $value, $fail) use ($account) {
                    $brand = Brand::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$brand) {
                        $fail("not exist");
                    }
                },
            ],
            '*.brandsToInactive.*' => [
                function ($attribute, $value, $fail) use ($account) {
                    $brand = Brand::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$brand) {
                        $fail("not exist");
                    }
                },
            ],
            '*.offersToActive.*' => [
                function ($attribute, $value, $fail) use ($account) {
                    $offer = Offer::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$offer) {
                        $fail("not exist");
                    }
                },
            ],
            '*.offersToInactive.*' => [
                function ($attribute, $value, $fail) use ($account) {
                    $offer = Offer::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$offer) {
                        $fail("not exist");
                    }
                },
            ],
            
            '*.imageVariations.*.attributes.*' => [
                function ($attribute, $value, $fail) {
                    $accountUsers = AccountUser::where('account_id',getAccountUser()->account_id)->pluck('id')->toArray();
                    $attributeP = Attribute::where(['id' => $value])->first();
                    if (!$attributeP) 
                        $fail("not exist");
                },
            ],
            '*.images.*' => [
                'string',
                // function ($attribute, $value, $fail) {
                //     $account = getAccountUser()->account_id;
                //     $image = Image::where(['id' => $value, 'account_id' => $account])->first();
                //     if ($image) {
                //         // $isUnique = \App\Models\Imageable::where('image_id', $image->id)
                //         //     ->where('imageable_type', "App\Models\Product")
                //         //     ->first();
                //         // if ($isUnique) {
                //         //     $fail("exist");
                //         // }
                //     } else {
                //         $fail("not exist");
                //     }

                // },
            ],
            '*.principalImage' => [
                'string',
                // function ($attribute, $value, $fail) {
                //     $account = getAccountUser()->account_id;
                //     $image = Image::where(['id' => $value, 'account_id' => $account])->first();
                //     if ($image) {
                //         // $isUnique = \App\Models\Imageable::where('image_id', $image->id)
                //         //     ->where('imageable_type', "App\Models\Product")
                //         //     ->first();
                //         // if ($isUnique) {
                //         //     $fail("exist");
                //         // }
                //     } else {
                //         $fail("not exist");
                //     }

                // },
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
            $request["reference"]=$product->reference;
            $request["title"]=$product->title;
            $product_only = collect($request)->only('title', 'reference', 'statut');
            $attributeByTypes = [];
            $product->update($product_only->all());
            if (isset($request['price']))
                if (doubleVal($request['price']) != $product->price->first()->price) {
                    $product->price->first()->update(['statut' => 0]);
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

            if (isset($request['attributes'])) {
                foreach ($request['attributes'] as $key => $attributeId) {
                    $attribute = Attribute::where('id', $attributeId)->first();
                    $attributeByTypes[$attribute->type_attribute_id][] = $attribute->id;
                }
            }
            if (isset($request['categories'])) {

                $product->accountProducts->where('account_id', getAccountUser()->account_id)->map(function ($accountproduct) {
                    $accountproduct->taxonomies->map(function ($taxonomy) use ($accountproduct) {
                        $taxonomy->products()->detach($accountproduct->id);
                    });
                });
                foreach ($request['categories'] as $key => $categoryId) {
                    $category = Taxonomy::find($categoryId);
                    $category->products()->syncWithoutDetaching([
                        $product->accountProducts->where('account_id', getAccountUser()->account_id)->first()->id => [
                            'created_at' => now(),
                            'updated_at' => now()
                        ]
                    ]);
                }
            }
            if (isset($request['categoriesToInactive'])) {
                foreach ($request['categoriesToInactive'] as $key => $categoryId) {
                    $category = Taxonomy::find($categoryId);
                    $category->products()->detach($category);
                }
            }

            if (isset($request['brandsToActive'])) {
                foreach ($request['brandsToActive'] as $key => $brandId) {
                    $brand = Brand::find($brandId);
                    $brand->brand_sources()->map(function ($brandSource) use ($product) {
                        $product->brandSources()->syncWithoutDetaching([$brandSource->id => ['statut' => 1, 'created_at' => now(), 'account_user_id' => getAccountUser()->id, 'updated_at' => now()]]);
                    });
                }
            }
            if (isset($request['brandsToInactive'])) {
                foreach ($request['brandsToInactive'] as $key => $brandId) {
                    $brand = Brand::find($brandId);
                    $brand->brand_sources()->map(function ($brandSource) use ($product) {
                        $product->brandSources()->syncWithoutDetaching([$brandSource->id => ['statut' => 0, 'created_at' => now(), 'account_user_id' => getAccountUser()->id, 'updated_at' => now()]]);
                    });
                }
            }
            if (isset($request['offersToActive'])) {
                foreach ($request['offersToActive'] as $key => $offerId) {
                    $offer = Offer::find($offerId);
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
                        if ($supplier->productVariationAttributes->where('id', $pvaToInactive->id)->first())
                            $supplier->productVariationAttributes()->syncWithoutDetaching([
                                $pvaToInactive->id => [
                                    'statut' => 0,
                                    'updated_at' => now(),
                                ]
                            ]);
                    }
                }
                if (isset($request['attributes']))
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
                            $variationAttributes= $pva->variationAttribute->childVariationAttributes->pluck(['attribute_id'])->toArray();
                            if($variationAttributes==$imageVariation['attributes']){
                                return $pva->id;
                            }
                            })->filter()->values()->first();
                        if($pvaId){
                            $productVariationAttribute=productVariationAttribute::find($pvaId);
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
                    $supplier = Supplier::find($supplierData['id']);
                    $productVariation = ProductVariationAttribute::where(['variation_attribute_id' => $supplierData['variation_id'], 'product_id' => $product->id])->get()->first();
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
            if ($imageData) {
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


    public function destroy($id)
    {
        $product = Product::find($id);
        $product->delete();
        return response()->json([
            'statut' => 1,
            'data' => $product,
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
