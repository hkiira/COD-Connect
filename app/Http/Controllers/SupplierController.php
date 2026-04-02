<?php

namespace App\Http\Controllers;

use App\Http\Controllers\PhoneController;
use App\Http\Controllers\AddressController;
use Illuminate\Http\Request;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\ProductVariationAttribute;
use App\Models\Account;
use App\Models\Image;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\HelperFunctions;

class SupplierController extends Controller
{

    public static function index(Request $request, $local = 0, $columns = ['id', 'title', 'addresses', 'created_at', 'warehouses', 'statut', 'images', 'phones', 'products'], $paginate = true)
    {
        $searchIds = [];
        $request = collect($request->query())->toArray();
        if (isset($request['warehouses']) && array_filter($request['warehouses'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['warehouses'] as $warehouseId) {
                if (Warehouse::find($warehouseId))
                    $searchIds = array_merge($searchIds, Warehouse::find($warehouseId)->suppliers->pluck('id')->unique()->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        if (isset($request['products']) && array_filter($request['products'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['products'] as $productId) {
                if (Product::find($productId))
                    $searchIds = array_merge($searchIds, Product::find($productId)->productVariationAttributes->flatMap(function ($pva) {
                        return $pva->suppliers->pluck('id');
                    })->unique()->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        $filters = HelperFunctions::filterColumns($request, ['code', 'title', 'statut', 'created_at']);
        $model = 'App\\Models\\Supplier';
        $request['inAccount'] = ['account_id', getAccountUser()->account_id];
        $datas = FilterController::searchs(new Request($request), $model, ['code', 'title', 'statut', 'created_at'], false, []);
        $supplierDatas = $datas->map(function ($supplier) {
            $supplierData = $supplier->only('id', 'code', 'title', 'statut', 'created_at');
            $supplierData['addresses'] = $supplier->addresses->map(function ($address) {
                $addressData = $address->only('title');
                $addressData['city'] = ($address->city) ? $address->city->only('id', 'title') : "";
                return $addressData;
            })->unique();
            $supplierData['phones'] = $supplier->phones->map(function ($phone) {
                $phoneData = $phone->only('title');
                return $phoneData;
            })->unique();
            $supplierData['images'] = $supplier->images;
            $supplierData['warehouses'] = $supplier->warehouses->map(function ($warehouse) {
                return $warehouse->only('id', 'title');
            })->unique('id')->values();
            $supplierData['products'] = $supplier->activePvas->map(function ($pva) {
                $supplier_price = $pva->pivot->price;
                if ($pva->product)
                    return ['id' => $pva->product->id, 'title' => $pva->product->title, "price" => $supplier_price];
            })->unique()->values()->filter();
            return $supplierData;
        });
        $dataPagination = HelperFunctions::getPagination($supplierDatas, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        if ($local == 1) {
            if ($paginate == true) {
                return $dataPagination;
            } else {
                return $supplierDatas->toArray();
            }
        };
        return $dataPagination;
    }

    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        if (isset($request['products']['inactive'])) {
            $model = 'App\\Models\\Product';
            //permet de récupérer la liste des regions inactive filtrés
            $request['products']['inactive']['where'] = ['column' => 'product_type_id', 'value' => 1];
            $request['products']['inactive']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $filters = HelperFunctions::filterColumns($request['products']['inactive'], ['title', 'addresse', 'phone', 'products']);
            $products = FilterController::searchs(new Request($request['products']['inactive']), $model, ['id', 'title'], false, [0 => ['model' => 'App\\Models\\ProductVariationAttribute', 'title' => 'productVariationAttributes.variationAttribute.childVariationAttributes.attribute.typeAttribute', 'search' => false]])->map(function ($product) {
                $productData = $product->only('id', 'title', 'created_at', 'statut');
                $productData['productType'] = $product->productType;
                $productData['images'] = $product->images;
                $productData['productVariations'] = $product->productVariationAttributes->map(function ($productVariationAttribute) {
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
                });
                return $productData;
            });
            $data['products']['inactive'] =  HelperFunctions::getPagination($products, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['warehouses']['inactive'])) {
            $model = 'App\\Models\\Warehouse';
            //permet de récupérer la liste des regions inactive filtrés
            $request['warehouses']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['warehouses']['inactive']['where'] = ["column" => 'warehouse_type_id', "value" => 1];
            $data['warehouses']['inactive'] = FilterController::searchs(new Request($request['warehouses']['inactive']), $model, ['id', 'title'], true);
        }

        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }
    public static function store(Request $requests)
    {
        $phoneableType = "App\Models\Supplier";
        $validator = Validator::make($requests->except('_method'), [
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($requests) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('supplier', 'title', $value);
                    $account_id = getAccountUser()->account_id;
                    $titleModel = Supplier::where(['title' => $value])->where('account_id', $account_id)->first();
                    if ($titleModel) {
                        $fail("exist");
                    }
                },
            ],

            '*.phones.*.title' => [
                'string',
                function ($attribute, $value, $fail) use ($phoneableType) {
                    $account = getAccountUser()->account_id;
                    $phone = \App\Models\Phone::where(['title' => $value, 'account_id' => $account])->first();
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
            '*.phones.*.phoneTypes.*' => 'required|exists:phone_types,id',
            '*.addresses.*.title' => 'max:255',
            '*.warehouses.*' => 'exists:warehouses,id|max:255',
            '*.products.*.id' => 'exists:products,id|max:255',
            '*.products.*.price' => 'required',
            '*.productVariations.*.id' => 'exists:product_variation_attribute,id|max:255',
            '*.productVariations.*.price' => 'required',
            '*.addresses.*.city_id' => 'exists:cities,id|max:255',
            '*.statut' => 'required',
            '*.principalImage' => [
                'max:255',
                function ($attribute, $value, $fail) {
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage == null) {
                        $fail("not exist");
                    } elseif ($principalImage->account_id !== getAccountUser()->account_id) {
                        $fail("not exist");
                    }
                },
            ],
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg,webp,avif|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $suppliers = collect($requests->except('_method'))->map(function ($request) {
            $request["account_id"] = getAccountUser()->account_id;
            // $request['code']=DefaultCodeController::getAccountCode('Supplier',$request["account_id"]);
            $supplier_only = collect($request)->only('code', 'title', 'statut', 'account_id');
            $supplier = Supplier::create($supplier_only->all());
            if (isset($request['phones'])) {
                $request_phone = new Request($request['phones']);
                $phone = PhoneController::store($request_phone, $local = 1, $supplier);
            }

            if (isset($request['addresses'])) {
                $request_address = new Request($request['addresses']);
                $address = AddressController::store($request_address, $local = 1, $supplier);
            }

            if (isset($request['warehouses'])) {
                foreach ($request['warehouses'] as $key => $warehouseId) {
                    $warehouse = Warehouse::find($warehouseId);
                    $warehouse->suppliers()->attach($supplier, ['created_at' => now(), 'updated_at' => now()]);
                    $warehouse->save();
                }
            }

            if (isset($request['productVariations'])) {
                foreach ($request['productVariations'] as $key => $productVariationData) {
                    $productVariation = productVariationAttribute::find($productVariationData['id']);
                    $price = ($productVariationData['price']) ? $productVariationData['price'] : 0;
                    if ($productVariation) {
                        $productVariation->suppliers()->attach($supplier, ['account_id' => $supplier->account_id, 'price' => $price, 'created_at' => now(), 'updated_at' => now()]);
                        $productVariation->save();
                    }
                }
            }

            if (isset($request['products'])) {
                foreach ($request['products'] as $key => $productData) {
                    $product = Product::with('productVariationAttributes')->find($productData['id']);
                    $price = ($productData['price']) ? $productData['price'] : 0;
                    if ($product) {
                        foreach ($product->productVariationAttributes as $productVariation) {
                            $productVariation->suppliers()->syncWithoutDetaching([
                                $supplier->id => [
                                    'account_id' => $supplier->account_id,
                                    'price' => $price,
                                    'created_at' => now(),
                                    'updated_at' => now()
                                ]
                            ]);
                        }
                    }
                }
            }

            if (isset($request['principalImage'])) {
                $image = Image::find($request['principalImage']);
                $image->images()->syncWithoutDetaching([
                    $supplier->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($request['newPrincipalImage'])) {
                $images[]["image"] = $request['newPrincipalImage'];
                $imageData = [
                    'title' => $supplier->title,
                    'type' => 'supplier',
                    'image_type_id' => 1,
                    'images' => $images
                ];
                ImageController::store(new Request([$imageData]), $supplier);
            }

            $supplier = Supplier::with('images', 'phones', 'addresses')->find($supplier->id);
            return $supplier;
        });
        return response()->json([
            'statut' => 1,
            'data' => $suppliers,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $accountId = getAccountUser()->account_id;
        $data = [];
        $supplier = Supplier::with(['addresses.city', 'phones.PhoneTypes', 'activePvas.product.accountProducts'])->find($id);
        if (!$supplier)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        if (isset($request['supplierInfo'])) {
            $data["supplierInfo"]['data'] = $supplier;
            $data['supplierInfo']['data']['principalImage'] = $supplier->images;
            $data['supplierInfo']['data']['warehouses'] = $supplier->warehouses->map(function ($warehouse) {
                return $warehouse->only('id', 'title');
            })->unique('id')->values();
            $data['supplierInfo']['data']['products'] = $supplier->activePvas->map(function ($pva) use ($accountId) {
                if (
                    $pva->product
                    && $pva->product->accountProducts->where('account_id', $accountId)->isNotEmpty()
                ) {
                    return [
                        'id' => $pva->product->id,
                        'title' => $pva->product->title,
                        'price' => $pva->pivot->price,
                    ];
                }
            })->filter()->unique('id')->values();
        }
        if (isset($request['products']['active'])) {
            // Récupérer les produits du fournisseur avec leurs attributs de variation et types d'attributs
            $filters = HelperFunctions::filterColumns($request['products']['active'], ['title', 'addresse', 'phone', 'products']);
            $supplierProducts = Supplier::with([
                'activePvas.product.accountProducts',
                'activePvas.variationAttribute.childVariationAttributes.attribute.typeAttribute'
            ])->find($id);
            // Mapper les données des produits pour les formater correctement
            $productDatas = $supplierProducts->activePvas->map(function ($productVariationAttribute) use ($accountId) {
                if (
                    !$productVariationAttribute->product
                    || !$productVariationAttribute->variationAttribute
                    || $productVariationAttribute->product->accountProducts->where('account_id', $accountId)->isEmpty()
                ) {
                    return null;
                }
                // Créer un tableau avec les données de base du produit
                $pvaData = [
                    "id" => $productVariationAttribute->id,
                    "price" => $productVariationAttribute->pivot->price,
                    "title" => $productVariationAttribute->product->title,
                    "created_at" => $productVariationAttribute->product->created_at,
                    "statut" => $productVariationAttribute->product->statut,
                    "images" => $productVariationAttribute->product->images,
                    "productType" => $productVariationAttribute->product->productType,
                    "product_id" => $productVariationAttribute->product->id
                ];
                // Récupérer les variations d'attributs pour chaque produit
                $pvaData['variations'] = $productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                    // Vérifier si l'attribut a un type
                    if ($childVariationAttribute->attribute->typeAttribute) {
                        // Retourner les données formatées pour chaque attribut de variation
                        return [
                            "id" => $childVariationAttribute->id,
                            "type" => $childVariationAttribute->attribute->typeAttribute->title,
                            "value" => $childVariationAttribute->attribute->title
                        ];
                    }
                })->filter(); // Filtrer les valeurs nulles (attributs sans type)

                return $pvaData; // Retourner les données formatées du produit
            })->filter();
            $pvas = [];
            foreach ($productDatas as $key => $productData) {
                $pvas[$productData['product_id']][] = ["id" => $productData["id"], "price" => $productData["price"], "variations" => $productData["variations"]];
            }
            $products = [];
            foreach ($productDatas as $key => $productData) {
                $products[$productData['product_id']]["id"] = $productData['product_id'];
                $products[$productData['product_id']]["title"] = $productData['title'];
                $products[$productData['product_id']]["created_at"] = $productData['created_at'];
                $products[$productData['product_id']]["statut"] = $productData['statut'];
                $products[$productData['product_id']]["images"] = $productData['images'];
                $products[$productData['product_id']]["productType"] = $productData['productType'];
                $products[$productData['product_id']]["productVariations"] = $pvas[$productData['product_id']];
            }
            $data['products']['active'] =  HelperFunctions::getPagination(collect($products), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['products']['inactive'])) {
            // Récupérer les produits du fournisseur avec leurs attributs de variation et types d'attributs
            $filters = HelperFunctions::filterColumns($request['products']['inactive'], ['title', 'addresse', 'phone', 'products']);
            $supplierProducts = ProductVariationAttribute::with(['product', 'variationAttribute.childVariationAttributes.attribute.typeAttribute'])
                ->whereHas('product.accountProducts', function ($query) use ($accountId) {
                    $query->where('account_id', $accountId);
                })
                ->whereDoesntHave('suppliers', function ($query) use ($supplier) {
                    $query->where('supplier_id', $supplier->id);
                })->get();
            // Mapper les données des produits pour les formater correctement
            $productDatas = $supplierProducts->map(function ($productVariationAttribute) {
                if (!$productVariationAttribute->product) {
                    return null;
                }
                // Créer un tableau avec les données de base du produit
                $pvaData = [
                    "id" => $productVariationAttribute->id,
                    "title" => $productVariationAttribute->product->title,
                    "created_at" => $productVariationAttribute->product->created_at,
                    "statut" => $productVariationAttribute->product->statut,
                    "images" => $productVariationAttribute->product->images,
                    "productType" => $productVariationAttribute->product->productType,
                    "product_id" => $productVariationAttribute->product->id
                ];
                if ($productVariationAttribute->variationAttribute) {
                    // Récupérer les variations d'attributs pour chaque produit
                    $pvaData['variations'] = $productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                        // Vérifier si l'attribut a un type
                        if ($childVariationAttribute->attribute->typeAttribute) {
                            // Retourner les données formatées pour chaque attribut de variation
                            return [
                                "id" => $childVariationAttribute->id,
                                "type" => $childVariationAttribute->attribute->typeAttribute->title,
                                "value" => $childVariationAttribute->attribute->title
                            ];
                        }
                    })->filter(); // Filtrer les valeurs nulles (attributs sans type)

                    return $pvaData;
                } // Retourner les données formatées du produit
            })->filter();
            $pvas = [];
            foreach ($productDatas as $key => $productData) {
                $pvas[$productData['product_id']][] = ["id" => $productData["id"], "variations" => $productData["variations"]];
            }
            $products = [];
            foreach ($productDatas as $key => $productData) {
                $products[$productData['product_id']]["id"] = $productData['product_id'];
                $products[$productData['product_id']]["title"] = $productData['title'];
                $products[$productData['product_id']]["created_at"] = $productData['created_at'];
                $products[$productData['product_id']]["statut"] = $productData['statut'];
                $products[$productData['product_id']]["images"] = $productData['images'];
                $products[$productData['product_id']]["productType"] = $productData['productType'];
                $products[$productData['product_id']]["productVariations"] = $pvas[$productData['product_id']];
            }

            $data['products']['inactive'] =  HelperFunctions::getPagination(collect($products), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['products']['all'])) {
            $filters = HelperFunctions::filterColumns($request['products']['all'], ['title', 'addresse', 'phone', 'products']);
            $allProducts = ProductVariationAttribute::with(['product', 'variationAttribute.childVariationAttributes.attribute.typeAttribute'])
                ->whereHas('product.accountProducts', function ($query) use ($accountId) {
                    $query->where('account_id', $accountId);
                })
                ->whereHas('product', function ($query) {
                    $query->where('statut', 1);
                })->get();

            $productDatas = $allProducts->map(function ($productVariationAttribute) {
                if (!$productVariationAttribute->product || !$productVariationAttribute->variationAttribute) {
                    return null;
                }

                $pvaData = [
                    "id" => $productVariationAttribute->id,
                    "title" => $productVariationAttribute->product->title,
                    "created_at" => $productVariationAttribute->product->created_at,
                    "statut" => $productVariationAttribute->product->statut,
                    "images" => $productVariationAttribute->product->images,
                    "productType" => $productVariationAttribute->product->productType,
                    "product_id" => $productVariationAttribute->product->id
                ];

                $pvaData['variations'] = $productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                    if ($childVariationAttribute->attribute->typeAttribute) {
                        return [
                            "id" => $childVariationAttribute->id,
                            "type" => $childVariationAttribute->attribute->typeAttribute->title,
                            "value" => $childVariationAttribute->attribute->title
                        ];
                    }
                })->filter();

                return $pvaData;
            })->filter();

            $pvas = [];
            foreach ($productDatas as $productData) {
                $pvas[$productData['product_id']][] = [
                    "id" => $productData["id"],
                    "variations" => $productData["variations"]
                ];
            }

            $products = [];
            foreach ($productDatas as $productData) {
                $products[$productData['product_id']]["id"] = $productData['product_id'];
                $products[$productData['product_id']]["title"] = $productData['title'];
                $products[$productData['product_id']]["created_at"] = $productData['created_at'];
                $products[$productData['product_id']]["statut"] = $productData['statut'];
                $products[$productData['product_id']]["images"] = $productData['images'];
                $products[$productData['product_id']]["productType"] = $productData['productType'];
                $products[$productData['product_id']]["productVariations"] = $pvas[$productData['product_id']];
            }

            $data['products']['all'] = HelperFunctions::getPagination(collect($products), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['warehouses']['active'])) {
            $model = 'App\\Models\\Warehouse';
            //permet de récupérer la liste des regions inactive filtrés
            $request['warehouses']['active']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['warehouses']['active']['whereIn'][0] = ['table' => 'suppliers', 'column' => 'supplier_id', 'value' => $supplier->id];
            $request['warehouses']['active']['where'] = ["column" => 'warehouse_type_id', "value" => 1];
            $data['warehouses']['active'] = FilterController::searchs(new Request($request['warehouses']['active']), $model, ['id', 'title'], true);
        }
        if (isset($request['warehouses']['inactive'])) {
            $model = 'App\\Models\\Warehouse';
            //permet de récupérer la liste des regions inactive filtrés
            $request['warehouses']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['warehouses']['inactive']['whereNotIn'][0] = ['table' => 'suppliers', 'column' => 'supplier_id', 'value' => $supplier->id];
            $request['warehouses']['inactive']['where'] = ["column" => 'warehouse_type_id', "value" => 1];
            $data['warehouses']['inactive'] = FilterController::searchs(new Request($request['warehouses']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['warehouses']['all'])) {
            $model = 'App\\Models\\Warehouse';
            $request['warehouses']['all']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['warehouses']['all']['where'] = ["column" => 'warehouse_type_id', "value" => 1];
            $data['warehouses']['all'] = FilterController::searchs(new Request($request['warehouses']['all']), $model, ['id', 'title'], true);
        }



        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }

    public function update(Request $requests)
    {
        $phoneableType = "App\Models\Supplier";
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'exists:suppliers,id|max:255',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($requests) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('supplier', 'title', $value);

                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $requests->input("{$index}.id"); // Get ID from request
                    $account_id = getAccountUser()->account_id;
                    $titleModel = Supplier::where('title', $value)->where('account_id', $account_id)->first();
                    $idModel = Supplier::where('id', $id)->where('account_id', $account_id)->first(); // Find model by ID

                    // Check if a country with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],

            '*.phones.*.title' => [
                'string',
                function ($attribute, $value, $fail) use ($phoneableType, $requests) {
                    $index = str_replace(['*', '.title'], '', $attribute);
                    $phoneable_id = $requests->input("{$index}.id"); // Get ID from request
                    $account = getAccountUser()->account_id;
                    $phone = \App\Models\Phone::where(['title' => $value, 'account_id' => $account])->first();
                    if ($phone) {
                        $isUnique = \App\Models\Phoneable::where('phone_id', $phone->id)
                            ->where('phoneable_type', $phoneableType)
                            ->where('phoneable_id', $phoneable_id)
                            ->first();
                        if ($isUnique) {
                            $fail("A phone '$value' number already taken.");
                        }
                    }
                },
            ],
            '*.phones.*.phoneTypes.*' => 'required|exists:phone_types,id',
            '*.addresses.*.title' => 'max:255',
            '*.warehousestoActive.*' => 'exists:warehouses,id|max:255',
            '*.warehousestoInactive.*' => 'exists:warehouses,id|max:255',
            '*.productsToActive.*.id' => 'exists:products,id|max:255',
            '*.productsToActive.*.price' => 'required',
            '*.productsToInactive.*' => 'exists:products,id|max:255',
            '*.productVariationsToActive.*.id' => 'exists:product_variation_attribute,id|max:255',
            '*.productVariationsToActive.*.price' => 'required',
            '*.productVariationsToInactive.*' => 'exists:product_variation_attribute,id|max:255',
            '*.addresses.*.city_id' => 'exists:cities,id|max:255',
            '*.principalImage' => [
                'max:255',
                function ($attribute, $value, $fail) {
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage == null) {
                        $fail("not exist");
                    } elseif ($principalImage->account_id !== getAccountUser()->account_id) {
                        $fail("not exist");
                    }
                },
            ],
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $suppliers = collect($requests->except("_method"))->map(function ($request) {
            $request["account_id"] = getAccountUser()->account_id;
            $supplier_only = collect($request)->only('id', 'title', 'statut');
            $supplier = Supplier::find($supplier_only['id']);
            $supplier->update($supplier_only->all());
            if (isset($request['phones'])) {
                $request_phone = new Request($request['phones']);
                $phone = PhoneController::store($request_phone, $local = 1, $supplier);
            }

            if (isset($request['addresses'])) {
                $request_address = new Request($request['addresses']);
                $address = AddressController::store($request_address, $local = 1, $supplier);
            }

            if (isset($request['warehousesToInactive'])) {
                foreach ($request['warehousesToInactive'] as $key => $warehouseId) {
                    $warehouse = Warehouse::find($warehouseId);
                    $warehouse->suppliers()->detach($supplier);
                    $warehouse->save();
                }
            }
            if (isset($request['warehousesToActive'])) {
                foreach ($request['warehousesToActive'] as $key => $warehouseId) {
                    $warehouse = Warehouse::find($warehouseId);
                    $warehouse->suppliers()->syncWithoutDetaching([$supplier->id => ['created_at' => now(), 'updated_at' => now()]]);
                    $warehouse->save();
                }
            }

            if (isset($request['productVariationstoInactive'])) {
                foreach ($request['productVariationstoInactive'] as $key => $productVariationData) {
                    $productVariation = productVariationAttribute::find($productVariationData);
                    if ($productVariation) {
                        $productVariation->suppliers()->detach($supplier);
                        $productVariation->save();
                    }
                }
            }
            if (isset($request['productVariationsToActive'])) {
                foreach ($request['productVariationsToActive'] as $key => $productVariationData) {
                    $productVariation = productVariationAttribute::find($productVariationData['id']);
                    $price = ($productVariationData['price']) ? $productVariationData['price'] : 0;
                    if ($productVariation) {
                        $productVariation->suppliers()->syncWithoutDetaching([$supplier->id => ['account_id' => $supplier->account_id, 'price' => $price, 'created_at' => now(), 'updated_at' => now()]]);
                        $productVariation->save();
                    }
                }
            }

            if (isset($request['productsToActive'])) {
                foreach ($request['productsToActive'] as $key => $productData) {
                    $product = Product::with('productVariationAttributes')->find($productData['id']);
                    $price = ($productData['price']) ? $productData['price'] : 0;
                    if ($product) {
                        foreach ($product->productVariationAttributes as $productVariation) {
                            $productVariation->suppliers()->syncWithoutDetaching([
                                $supplier->id => [
                                    'account_id' => $supplier->account_id,
                                    'price' => $price,
                                    'created_at' => now(),
                                    'updated_at' => now()
                                ]
                            ]);
                        }
                    }
                }
            }

            if (isset($request['productsToInactive'])) {
                foreach ($request['productsToInactive'] as $key => $productId) {
                    $product = Product::with('productVariationAttributes')->find($productId);
                    if ($product) {
                        foreach ($product->productVariationAttributes as $productVariation) {
                            $productVariation->suppliers()->detach($supplier);
                        }
                    }
                }
            }

            if (isset($request['principalImage'])) {
                $image = Image::find($request['principalImage']);
                $supplier->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($request['newPrincipalImage'])) {
                $images[]["image"] = $request['newPrincipalImage'];
                $imageData = [
                    'title' => $supplier->title,
                    'type' => 'supplier',
                    'image_type_id' => 1,
                    'images' => $images
                ];
                ImageController::store(new Request([$imageData]), $supplier);
            }

            $supplier = Supplier::with('images', 'phones', 'addresses')->find($supplier->id);
            return $supplier;
        });
        return response()->json([
            'statut' => 1,
            'data' => $suppliers,
        ]);
    }

    public function destroy($id)
    {
        $Supplier = Supplier::find($id);
        $Supplier->delete();
        return response()->json([
            'statut' => 1,
            'data' => $Supplier,
        ]);
    }
}
