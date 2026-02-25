<?php

namespace App\Http\Controllers;

use App\Models\ProductVariationAttribute;
use App\Models\SupplierOrderPva;
use App\Models\SupplierPva;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use niklasravnsborg\LaravelPdf\Facades\Pdf as PDF;

class SupplierOrderController extends Controller
{

    public function index(Request $request)
    {
        $searchIds = [];
        $request = collect($request->query())->toArray();
        if (isset($request['suppliers']) && array_filter($request['suppliers'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['suppliers'] as $supplierId) {
                if (Supplier::find($supplierId))
                    $searchIds = array_merge($searchIds, Supplier::find($supplierId)->supplierOrders->pluck('id')->unique()->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        if (isset($request['warehouses']) && array_filter($request['warehouses'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['warehouses'] as $warehouseId) {
                if (Warehouse::find($warehouseId))
                    $searchIds = array_merge($searchIds, Warehouse::find($warehouseId)->supplierOrders->pluck('id')->unique()->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        $associated = [];
        $filters = HelperFunctions::filterColumns($request, ['id', 'code']);
        $model = 'App\\Models\\SupplierOrder';
        $request['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'code'], false, $associated);
        $datas = $datas->map(function ($supplierOrder) {
            $productData = $supplierOrder->only('id', 'code', 'shipping_date', 'created_at', 'statut');
            $productData['user'] = [
                "id" => $supplierOrder->accountUser->user->id,
                "firstname" => $supplierOrder->accountUser->user->firstname,
                "lastname" => $supplierOrder->accountUser->user->lastname,
                "images" => $supplierOrder->accountUser->user->images,
            ];
            $productData['time'] = ['statut' => 0, 'data' => "3 Days"];
            $productData['quantity'] = array_sum($supplierOrder->supplierOrderPvas->pluck('quantity')->toArray());
            $productData['reste'] = array_sum($supplierOrder->supplierOrderPvas->pluck('quantity')->toArray()) - array_sum($supplierOrder->supplierOrderPvas()->where('supplier_receipt_id', null)->get()->pluck('quantity')->toArray());
            $productData['supplier'] = $supplierOrder->supplier;
            $productData['supplier']['images'] = ($supplierOrder->supplier) ? $supplierOrder->supplier->images : [];
            $productData['warehouse'] = $supplierOrder->warehouse;
            $total = 0;
            $productData['productVariations'] = $supplierOrder->supplierOrderPvas->map(function ($supplierOrderPva) use (&$total) {
                $pvaData["id"] = $supplierOrderPva->productVariationAttribute->id;
                $pvaData["product"] = $supplierOrderPva->productVariationAttribute->product->reference;
                $pvaData["quantity"] = $supplierOrderPva->quantity;
                $pvaData["arrived"] = ($supplierOrderPva->supplier_receipt_id == null) ? 0 : $supplierOrderPva->quantity;
                $pvaData["price"] = $supplierOrderPva->price;
                $total += $supplierOrderPva->quantity * $supplierOrderPva->price;
                $pvaData['variations'] = $supplierOrderPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                    if ($childVariationAttribute->attribute->typeAttribute)
                        return ["id" => $childVariationAttribute->id, "type" => $childVariationAttribute->attribute->typeAttribute->title, "value" => $childVariationAttribute->attribute->title];
                })->values();
                return $pvaData;
            });
            $productData['total'] = $total;

            return $productData;
        });
        $datas =  HelperFunctions::getPagination($datas, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        return $datas;
    }

    public function generatePdf($id)
    {
        $supplierOrder = SupplierOrder::find($id);
        $orderPvas = SupplierOrderPva::where('supplier_order_id', $id)->get();
        $datas = [];
        $pickUpTotal = 0;
        foreach ($orderPvas as $key => $orderPva) {
            $variations = $orderPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVa) {
                return $childVa->attribute->title;
            });
            if ($orderPva->productVariationAttribute->product->images->sortByDesc('created_at')->first())
                $datas['datas'][$orderPva->productVariationAttribute->product->id]["image"] = $orderPva->productVariationAttribute->product->images->sortByDesc('created_at')->first()->photo_dir . $orderPva->productVariationAttribute->product->images->sortByDesc('created_at')->first()->photo;
            $datas['datas'][$orderPva->productVariationAttribute->product->id]["product"] = $orderPva->productVariationAttribute->product->title;
            $datas['datas'][$orderPva->productVariationAttribute->product->id]['pvas'][] = [
                "variation" => implode(", ", $variations->toArray()),
                "quantity" => $orderPva->quantity,
                "price" => $orderPva->price,

            ];
            $pickUpTotal += $orderPva->quantity * $orderPva->price;
        }
        $datas['total'] = $pickUpTotal;
        $datas['datas'] = collect($datas['datas'])->values()->toArray();
        $datas['orderFor'] = $supplierOrder->supplier->title;
        $datas['account'] = $supplierOrder->supplier->account->name;
        $datas['account'] = $supplierOrder->supplier->account->name;
        $datas['code'] = $supplierOrder->code;
        $datas['countOrders'] = $orderPvas->count();
        $html = view('pdf.supplierOrder', $datas)->render();

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

    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        if (isset($request['products']['inactive'])) {
            $model = 'App\\Models\\Product';
            $supplierId = (isset($request['products']['inactive']['supplier'])) ? $request['products']['inactive']['supplier'] : 0;
            $pvas = SupplierPva::where(['supplier_id' => $supplierId])->get()->pluck('product_variation_attribute_id')->toArray();
            $productIds = ProductVariationAttribute::whereIn('id', $pvas)->get()->pluck('product_id')->toArray();
            //permet de récupérer la liste des regions inactive filtrés
            $filters = HelperFunctions::filterColumns($request['products']['inactive'], ['id', 'code']);
            $request['products']['inactive']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $request['products']['inactive']['whereArray'] = ['column' => 'id', 'values' => $productIds];
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
            $products = FilterController::searchs(new Request($request['products']['inactive']), $model, ['id', 'title'], false, $associated)->map(function ($product) use ($pvas) {
                $productData = $product->only('id', 'title');
                $productData['productType'] = $product->productType;
                $productData['productVariations'] = $product->productVariationAttributes->map(function ($productVariationAttribute) use ($product, $pvas) {
                    if ($product->product_type_id == 1) {
                        if (in_array($productVariationAttribute->id, $pvas)) {
                            $pvaData = ["id" => $productVariationAttribute->id];
                            $pvaData['variations'] = $productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                                if ($childVariationAttribute->attribute->typeAttribute)
                                    return ["id" => $childVariationAttribute->id, "type" => $childVariationAttribute->attribute->typeAttribute->title, "value" => $childVariationAttribute->attribute->title];
                            })->values();
                            return $pvaData;
                        }
                    }
                })->filter()->values();
                return $productData;
            });
            $data['products']['inactive'] =  HelperFunctions::getPagination($products, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }

    public function store(Request $requests)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.supplier_id' => [ // Validate title field
                'required', // Title is required
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $account_id = getAccountUser()->account_id;
                    $titleModel = Supplier::where(['id' => $value])->where('account_id', $account_id)->first();
                    if (!$titleModel) {
                        $fail("not exist");
                    }
                },
            ],

            '*.productVariationAttributes.*.id' => [
                'required', 'int',
                function ($attribute, $value, $fail) use ($requests) {
                    $supplier_id = $requests->toArray()[0]["supplier_id"];
                    $account = getAccountUser()->account_id;
                    $phone = SupplierPva::where(['product_variation_attribute_id' => $value, 'supplier_id' => $supplier_id])->first();
                    if (!$phone) {
                        $fail("not exist for this supplier");
                    }
                },
            ],
            '*.warehouse_id' => [
                'required', 'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$warehouse) {
                        $fail("not exist");
                    }
                },
            ],
            '*.productVariationAttributes.*.quantity' => 'required|numeric',
            '*.productVariationAttributes.*.price' => 'numeric',
            '*.shipping_date' => 'date',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $supplierOrders = collect($requests->except('_method'))->map(function ($request) {
            $request["account_user_id"] = getAccountUser()->id;
            $account_id = getAccountUser()->account_id;
            $request['code'] = DefaultCodeController::getAccountCode('SupplierOrder', $account_id);
            $supplierOrder_only = collect($request)->only('code', 'shipping_date', 'warehouse_id', 'supplier_id', 'statut', 'account_user_id');
            $supplierOrder = SupplierOrder::create($supplierOrder_only->all());

            if (isset($request['productVariationAttributes'])) {
                foreach ($request['productVariationAttributes'] as $pvaData) {
                    $pva = SupplierPva::where(['supplier_id' => $supplierOrder->supplier_id, 'product_variation_attribute_id' => $pvaData['id']])->first();
                    SupplierOrderPva::create([
                        'product_variation_attribute_id' => $pva->product_variation_attribute_id,
                        'supplier_order_id' => $supplierOrder->id,
                        'quantity' => $pvaData["quantity"],
                        'price' => (isset($pvaData['price'])) ? $pvaData['price'] : $pva->price,
                        'account_user_id' => getAccountUser()->id,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            $supplierOrder = SupplierOrder::with('supplier')->find($supplierOrder->id);
            return $supplierOrder;
        });
        return response()->json([
            'statut' => 1,
            'data' => $supplierOrders,
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
        $supplierOrder = SupplierOrder::with(['warehouse', 'supplier.warehouses', 'supplier.addresses.city', 'supplier.phones.PhoneTypes'])->find($id);
        if (!$supplierOrder)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        if (isset($request['supplierOrderInfo'])) {
            $data["supplierOrderInfo"]['data'] = $supplierOrder;
            $data['supplierOrderInfo']['data']['principalImage'] = $supplierOrder->supplier->images;
        }
        if (isset($request['products']['active'])) {
            // Récupérer les produits du fournisseur avec leurs attributs de variation et types d'attributs
            $filters = HelperFunctions::filterColumns($request['products']['active'], ['title', 'addresse', 'phone', 'products']);
            $supplierProducts = SupplierOrder::with(['productVariationAttributes.product', 'productVariationAttributes.variationAttribute.childVariationAttributes.attribute.typeAttribute'])->find($id);
            // Mapper les données des produits pour les formater correctement
            $productDatas = $supplierProducts->productVariationAttributes->map(function ($productVariationAttribute) {
                // Créer un tableau avec les données de base du produit
                $pvaData = [
                    "id" => $productVariationAttribute->id,
                    "price" => $productVariationAttribute->pivot->price,
                    "quantity" => $productVariationAttribute->pivot->quantity,
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
            });
            $pvas = [];
            foreach ($productDatas as $key => $productData) {
                $pvas[$productData['product_id']][] = ["id" => $productData["id"], "price" => $productData["price"], "quantity" => $productData["quantity"], "variations" => $productData["variations"]];
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
            $supplierProducts = ProductVariationAttribute::with(['product', 'variationAttribute.childVariationAttributes.attribute.typeAttribute'])->whereDoesntHave('supplierOrders', function ($query) use ($supplierOrder) {
                $query->where('supplier_order_id', $supplierOrder->id);
            })->get();
            // Mapper les données des produits pour les formater correctement
            $productDatas = $supplierProducts->map(function ($productVariationAttribute) {
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
        if (isset($request['warehouses']['active'])) {
            $model = 'App\\Models\\Warehouse';
            //permet de récupérer la liste des regions inactive filtrés
            $request['warehouses']['active']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['warehouses']['active']['whereIn'][0] = ['table' => 'suppliers', 'column' => 'supplier_id', 'value' => $supplierOrder->supplier_id];
            $request['warehouses']['active']['where'] = ["column" => 'warehouse_type_id', "value" => 1];
            $data['warehouses']['active'] = FilterController::searchs(new Request($request['warehouses']['active']), $model, ['id', 'title'], true);
        }
        if (isset($request['warehouses']['inactive'])) {
            $model = 'App\\Models\\Warehouse';
            //permet de récupérer la liste des regions inactive filtrés
            $request['warehouses']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['warehouses']['inactive']['whereNotIn'][0] = ['table' => 'suppliers', 'column' => 'supplier_id', 'value' => $supplierOrder->supplier_id];
            $request['warehouses']['inactive']['where'] = ["column" => 'warehouse_type_id', "value" => 1];
            $data['warehouses']['inactive'] = FilterController::searchs(new Request($request['warehouses']['inactive']), $model, ['id', 'title'], true);
        }



        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }

    public function update(Request $requests)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'required|exists:supplier_orders,id',
            '*.supplier_id' => [ // Validate title field
                'required', // Title is required
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
                'required', 'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$warehouse) {
                        $fail("not exist");
                    }
                },
            ],
            '*.productVariationAttributes.*.id' => [
                'required', 'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $phone = SupplierPva::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$phone) {
                        $fail("not exist");
                    }
                },
            ],
            '*.productVariationAttributes.*.quantity' => 'required|numeric',
            '*.productVariationAttributes.*.price' => 'numeric',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };

        $supplierOrders = collect($requests->except('_method'))->map(function ($request) {
            $supplierOrder_only = collect($request)->only('id', 'shipping_date', 'warehouse_id', 'supplier_id', 'statut', 'statut');
            $supplierOrder = supplierOrder::find($supplierOrder_only['id']);
            $supplierOrder->update($supplierOrder_only->all());

            if (isset($request['productVariationAttributes'])) {
                //récupérer les ids produits de la commandes 
                $supplierOrderProducts = SupplierOrderPva::where('supplier_order_id', $request['id'])->get();
                $supplierOrderProductIds = collect($request['productVariationAttributes'])->pluck('id')->toArray();
                //récupérer les enregistrements a supprimée
                $sopToDeletes = $supplierOrderProducts->map(function ($supporderproduct) use ($supplierOrderProductIds) {
                    if (!in_array($supporderproduct->product_variation_attribute_id, $supplierOrderProductIds))
                        return $supporderproduct->id;
                })->filter();
                //supprimer les produits manquant
                $sopToDeletes->map(function ($sopToDelete) {
                    $supplierOrderProduct = SupplierOrderPva::find($sopToDelete);
                    $supplierOrderProduct->delete();
                });
                foreach ($request['productVariationAttributes'] as $pvaData) {
                    $isExist = SupplierOrderPva::where(['product_variation_attribute_id' => $pvaData['id'], 'supplier_order_id' => $supplierOrder->id])->first();
                    if ($isExist) {
                        $isExist->update([
                            'quantity' => (isset($pvaData['quantity'])) ? $pvaData['quantity'] : $isExist->quantity,
                            'price' => (isset($pvaData['price'])) ? $pvaData['price'] : $isExist->price,
                        ]);
                    } else {
                        $pva = SupplierPva::where(['supplier_id' => $supplierOrder->supplier_id, 'product_variation_attribute_id' => $pvaData['id']])->first();
                        if ($pva) {
                            SupplierOrderPva::create([
                                'product_variation_attribute_id' => $pva->product_variation_attribute_id,
                                'supplier_order_id' => $supplierOrder->id,
                                'quantity' => $pvaData["quantity"],
                                'price' => (isset($pvaData['price'])) ? $pvaData['price'] : $pva->price,
                                'account_user_id' => getAccountUser()->id,
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);
                        }
                    }
                }
            }

            $supplierOrder = SupplierOrder::with('supplier')->find($supplierOrder->id);
            return $supplierOrder;
        });
        return response()->json([
            'statut' => 1,
            'data' => $supplierOrders,
        ]);
    }

    public function destroy($id)
    {
        $SupplierOder = SupplierOrder::find($id);
        $SupplierOder->delete();
        return response()->json([
            'statut' => 1,
            'data' => $SupplierOder,
        ]);
    }
}
