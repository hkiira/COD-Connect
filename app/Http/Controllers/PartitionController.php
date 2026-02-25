<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Warehouse;
use App\Models\WarehouseNature;
use App\Models\ProductVariationAttribute;
use App\Models\AccountUser;
use App\Models\Supplier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PartitionController extends Controller
{
    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated[] = [
            'model' => 'App\\Models\\Warehouse',
            'title' => 'parentWarehouse',
            'search' => true,
        ];
        $model = 'App\\Models\\Warehouse';
        $request['where'] = ['column' => 'warehouse_type_id', 'value' => 3];
        $request['inAccount'] = ['account_id', getAccountUser()->account_id];
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'title'], true, $associated);
        return $datas;
    }

    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $warehouse = [];
        if (isset($request['products']['inactive'])) {
            $model = 'App\\Models\\Product';
            //permet de récupérer la liste des regions inactive filtrés
            $request['products']['inactive']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $products = FilterController::searchs(new Request($request['products']['inactive']), $model, ['id', 'title'], false, [0 => ['model' => 'App\\Models\\ProductVariationAttribute', 'title' => 'productVariationAttributes.variationAttribute.childVariationAttributes.attribute.typeAttribute', 'search' => false]])->map(function ($product) {
                $productData = $product->only('id', 'title');
                $productData['productVariations'] = $product->productVariationAttributes->map(function ($productVariationAttribute) {
                    $pvaData = ["id" => $productVariationAttribute->id];
                    $pvaData['variations'] = $productVariationAttribute->variationAttribute->all()->flatMap(function ($variationAttribute) {
                        return $variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                            if ($childVariationAttribute->attribute->typeAttribute)
                                return ["id" => $childVariationAttribute->id, "type" => $childVariationAttribute->attribute->typeAttribute->title, "value" => $childVariationAttribute->attribute->title];
                        })->filter();
                    })->values();
                    return $pvaData;
                });
                return $productData;
            });
            $warehouse['products']['inactive'] =  HelperFunctions::getPagination($products, $request['products']['inactive']['pagination']['per_page'], $request['products']['inactive']['pagination']['current_page']);
        }
        if (isset($request['natures']['inactive'])) {
            $model = 'App\\Models\\WarehouseNature';
            $request['natures']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $warehouse['natures']['inactive'] = FilterController::searchs(new Request($request['natures']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['suppliers']['inactive'])) {
            $model = 'App\\Models\\Supplier';
            $request['suppliers']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $warehouse['suppliers']['inactive'] = FilterController::searchs(new Request($request['suppliers']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['users']['inactive'])) {
            $account = getAccountUser()->account_id;
            $model = 'App\\Models\\User';

            $request['users']['inactive']['whereIn'][0] = ['table' => 'accounts', 'column' => 'account_id', 'value' => $account];
            $warehouse['users']['inactive'] = FilterController::searchs(new Request($request['users']['inactive']), $model, ['id', 'firstname'], true);
        }

        return response()->json([
            'statut' => 1,
            'data' => $warehouse,
        ]);
    }

    public function store(Request $requests)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($requests) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('warehouse', 'title', $value);
                    $account_id = getAccountUser()->account_id;
                    $titleModel = Warehouse::where(['title' => $value])->where('account_id', $account_id)->first();
                    if ($titleModel) {
                        $fail("exist");
                    }
                },
            ],
            '*.productVariations.*' => 'required|exists:product_variation_attribute,id|max:255',
            '*.users.*' => 'required|exists:account_user,id|max:255',
            '*.image' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $warehouses = collect($requests->except('_method'))->map(function ($request) {
            $account_id = getAccountUser()->account_id;
            $warehouse_all = collect($request)->all();
            $warehouse = Warehouse::create([
                'code' => "WH1",
                'title' => $request['title'],
                'statut' => 1,
                'warehouse_type_id' => 1,
                'account_id' => $account_id,

            ]);
            $partition = Warehouse::create([
                'code' => "P1",
                'title' => "partition" . $request['title'],
                'statut' => 1,
                'warehouse_type_id' => 1,
                'account_id' => $account_id,
                'warehouse_id' => $warehouse->id,
            ]);


            if (isset($request['users'])) {
                foreach ($request['users'] as $key => $account_user) {
                    $accountUser = AccountUser::where('id', $account_user)->first();
                    $warehouse->accountUsers()->attach($accountUser, ['statut' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            if (isset($request['suppliers'])) {
                foreach ($request['suppliers'] as $key => $supplierId) {
                    $supplier = Supplier::find($supplierId);
                    $supplier->warehouses()->attach($supplier, ['statut' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            if (isset($request['productVariations'])) {
                foreach ($request['productVariations'] as $key => $productVariation) {
                    $productVAttribute = ProductVariationAttribute::fin($productVariation);
                    $productVAttribute->warehouses()->attach($warehouse, ['statut' => 1, 'quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            if (isset($warehouse_all['image'])) {
                $imageData = [
                    'title' => $warehouse->title,
                    'type' => 'warehouse',
                    'image' => $warehouse_all['image']
                ];
                $source_image = ImageController::store(new Request([$imageData]), $warehouse);
            }
            $warehouse = Warehouse::with('images', 'childWarehouses')->find($warehouse->id);

            return $warehouse;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $warehouses,
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
        $warehouse = Warehouse::find($id);
        if (!$warehouse)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        if (isset($request['warehouseInfo'])) {
            $data["warehouseInfo"]['data'] = $warehouse;
        }
        //récupérer les produits active et innactive
        if (isset($request['products']['active'])) {
            // Récupérer les produits du fournisseur avec leurs attributs de variation et types d'attributs
            $filters = HelperFunctions::filterColumns($request['products']['active'], ['title', 'addresse', 'phone', 'products']);
            $warehouseProducts = Warehouse::with(['products.product', 'products.variationAttribute.childVariationAttributes.attribute.typeAttribute'])->find($id);
            // Mapper les données des produits pour les formater correctement
            $productDatas = $warehouseProducts->activeProducts->map(function ($productVariationAttribute) {
                // Créer un tableau avec les données de base du produit
                $pvaData = [
                    "id" => $productVariationAttribute->id,
                    "title" => $productVariationAttribute->product->title,
                    "product_id" => $productVariationAttribute->product->id
                ];

                // Récupérer les variations d'attributs pour chaque produit
                $pvaData['variations'] = $productVariationAttribute->variationAttribute->all()->flatMap(function ($variationAttribute) {
                    return $variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
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
                })->values(); // Réindexer les clés après le flatMap

                return $pvaData; // Retourner les données formatées du produit
            });

            // Mapper les données formatées des produits pour les indexer par l'ID du produit
            $products = collect($productDatas)->map(function ($productData) {
                $productId = $productData['product_id'];
                $title = $productData['title'];
                unset($productData['product_id']);
                unset($productData['title']);
                return [
                    'id' => $productId,
                    'title' => $title,
                    'productVariations' => $productData,
                ];
            })->keyBy('id'); // Indexer les produits par l'ID du produit
            $data['products']['active'] =  HelperFunctions::getPagination($products, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['products']['inactive'])) {
            $filters = HelperFunctions::filterColumns($request['products']['inactive'], ['title', 'addresse', 'phone', 'products']);
            // Récupérer les produits du fournisseur avec leurs attributs de variation et types d'attributs
            $warehouseNotProducts = ProductVariationAttribute::with(['variationAttribute.childVariationAttributes.attribute.typeAttribute'])->join('warehouse_product', 'product_variation_attribute.id', '=', 'warehouse_product.product_variation_attribute_id')->whereNot('warehouse_id', $warehouse->id)->get();
            // Mapper les données des produits pour les formater correctement
            $productDatas = $warehouseNotProducts->map(function ($productVariationAttribute) {
                // Créer un tableau avec les données de base du produit
                $pvaData = [
                    "id" => $productVariationAttribute->id,
                    "title" => $productVariationAttribute->product->title,
                    "product_id" => $productVariationAttribute->product->id
                ];

                // Récupérer les variations d'attributs pour chaque produit
                $pvaData['variations'] = $productVariationAttribute->variationAttribute->all()->flatMap(function ($variationAttribute) {
                    return $variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
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
                })->values(); // Réindexer les clés après le flatMap

                return $pvaData; // Retourner les données formatées du produit
            });

            // Mapper les données formatées des produits pour les indexer par l'ID du produit
            $products = collect($productDatas)->map(function ($productData) {
                $productId = $productData['product_id'];
                $title = $productData['title'];
                unset($productData['product_id']);
                unset($productData['title']);
                return [
                    'id' => $productId,
                    'title' => $title,
                    'productVariations' => $productData,
                ];
            })->keyBy('id'); // Indexer les produits par l'ID du produit
            $data['products']['inactive'] =  HelperFunctions::getPagination($products, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        //récupérer les fournisseurs active et innactive
        if (isset($request['suppliers']['active'])) {
            $model = 'App\\Models\\Supplier';
            //permet de récupérer la liste des regions inactive filtrés
            $request['suppliers']['active']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['suppliers']['active']['whereIn'][0] = ['table' => 'warehouses', 'column' => 'warehouse_supplier.warehouse_id', 'value' => $warehouse->id];
            $data['suppliers']['active'] = FilterController::searchs(new Request($request['suppliers']['active']), $model, ['id', 'title'], true);
        }
        if (isset($request['suppliers']['inactive'])) {
            $model = 'App\\Models\\Supplier';
            //permet de récupérer la liste des regions inactive filtrés
            $request['suppliers']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['suppliers']['inactive']['whereNotIn'][0] = ['table' => 'warehouses', 'column' => 'warehouse_supplier.warehouse_id', 'value' => $warehouse->id];
            $data['suppliers']['inactive'] = FilterController::searchs(new Request($request['suppliers']['inactive']), $model, ['id', 'title'], true);
        }

        //récupérer les utilisateurs active et innactive
        if (isset($request['users']['inactive'])) {
            $account = getAccountUser()->account_id;
            $model = 'App\\Models\\User';
            $accountUsers = $warehouse->accountUsers->pluck('user_id');
            $request['users']['inactive']['whereArray'] = ['column' => 'id', 'values' => $accountUsers];
            $request['users']['inactive']['whereIn'][] = ['table' => 'accounts', 'column' => 'account_id', 'value' => $account];
            $data['users']['inactive'] = FilterController::searchs(new Request($request['users']['inactive']), $model, ['id', 'firstname'], true);
        }
        //récupérer les utilisateurs active et innactive
        if (isset($request['users']['active'])) {
            $account = getAccountUser()->account_id;
            $model = 'App\\Models\\User';
            $accountUsers = $warehouse->accountUsers->pluck('user_id');
            $request['users']['active']['whereNotArray'] = ['column' => 'id', 'values' => $accountUsers->toArray()];
            $request['users']['active']['whereIn'][] = ['table' => 'accounts', 'column' => 'account_id', 'value' => $account];
            $data['users']['active'] = FilterController::searchs(new Request($request['users']['active']), $model, ['id', 'firstname'], true);
        }

        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }

    public function update(Request $requests, $id)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'required|exists:brands,id',
            '*.title' => 'required|max:255',

            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($requests) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('warehouse', 'title', $value);

                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $requests->input("{$index}.id"); // Get ID from request
                    $account_id = getAccountUser()->account_id;
                    $titleModel = Warehouse::where('title', $value)->where('account_id', $account_id)->first();
                    $idModel = Warehouse::where('id', $id)->where('account_id', $account_id)->first(); // Find model by ID

                    // Check if a country with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.image' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.suppliersToActive.*' => 'exists:suppliers,id',
            '*.suppliersToInnactive.*' => 'exists:suppliers,id',
            '*.productsToActive.*' => 'exists:product_variation_attribute,id',
            '*.productsToInnactive.*' => 'exists:product_variation_attribute,id',
            '*.usersToInactive.*' => 'exists:account_user,id',
            '*.usersToActive.*' => 'exists:account_user,id',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        return $requests;
        $brands = collect($requests->except('_method'))->map(function ($request) {
            $brand_all = collect($request)->all();
            $brand = Brand::find($brand_all['id']);
            $brand->update($brand_all);
            if (isset($brand_all['sourcesToActive'])) {
                foreach ($brand_all['sourcesToActive'] as $key => $sourceId) {
                    $source = Source::find($sourceId);
                    $source->brands()->attach($brand, ['account_id' => $brand->account_id]);
                    $source->save();
                }
            }
            if (isset($brand_all['sourcesToInactive'])) {
                foreach ($brand_all['sourcesToInactive'] as $key => $sourceId) {
                    $source = Source::find($sourceId);
                    $source->brands()->detach($brand);
                    $source->save();
                }
            }
            if (isset($brand_all['image'])) {
                $imageData = [
                    'title' => $brand->title,
                    'type' => 'brand',
                    'image' => $brand_all['image']
                ];
                $brand_image = ImageController::store(new Request([$imageData]), $brand);
            }
            $brand = Brand::with('images', 'sources')->find($brand->id);
            return $brand;
        });

        return response()->json([
            'statut' => 1,
            'data' => $brands,
        ]);
    }



    public function destroy($id)
    {
        $Warehouse = Warehouse::find($id);
        $Warehouse->delete();
        return response()->json([
            'statut' => 1,
            'data' => $Warehouse,
        ]);
    }
}
