<?php


namespace App\Http\Controllers;

use App\Models\SupplierOrder;
use App\Models\SupplierOrderPva;
use App\Models\SupplierPva;
use Illuminate\Http\Request;
use App\Models\AccountUser;
use App\Models\Account;
use App\Models\Warehouse;
use App\Models\User;
use App\Models\SupplierReceipt;
use App\Models\Supplier;
use App\Models\ProductVariationAttribute;
use App\Models\ProductSupplier;
use App\Models\SupplierOrderProduct;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SupplierReceiptController extends Controller
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
        $model = 'App\\Models\\SupplierReceipt';
        $request['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'code'], false, $associated);
        $datas = $datas->map(function ($supplierReceipt) {
            $productData = $supplierReceipt;
            $productData['user'] = [
                "id" => $supplierReceipt->accountUser->user->id,
                "firstname" => $supplierReceipt->accountUser->user->firstname,
                "lastname" => $supplierReceipt->accountUser->user->lastname,
                "images" => $supplierReceipt->accountUser->user->images,
            ];
            $total = 0;
            $quantity = 0;
            $productData['supplier'] = $supplierReceipt->supplier;
            $productData['supplier']['images'] = $supplierReceipt->supplier->images;
            $productData['warehouse'] = $supplierReceipt->warehouse;
            $productData['productVariations'] = $supplierReceipt->productVariationAttributes->map(function ($productVariationAttribute) use (&$total, &$quantity) {
                $pvaData["id"] = $productVariationAttribute->id;
                $pvaData["product"] = $productVariationAttribute->product->reference;
                $pvaData["quantity"] = $productVariationAttribute->pivot->quantity;
                $pvaData["price"] = $productVariationAttribute->pivot->price;
                $total += $productVariationAttribute->pivot->quantity * $productVariationAttribute->pivot->price;
                $quantity += $productVariationAttribute->pivot->quantity;
                $pvaData['variations'] = $productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                    if ($childVariationAttribute->attribute->typeAttribute)
                        return ["id" => $childVariationAttribute->id, "type" => $childVariationAttribute->attribute->typeAttribute->title, "value" => $childVariationAttribute->attribute->title];
                })->values();
                return $pvaData;
            });
            $productData['total'] = $total;
            $productData['quantity'] = $quantity;
            return $productData;
        });
        $datas =  HelperFunctions::getPagination($datas, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        return $datas;
    }


    public function generatePdf($id)
    {
        $supplierOrder = SupplierReceipt::find($id);
        $orderPvas = SupplierOrderPva::where('supplier_receipt_id', $id)->get();
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
        $html = view('pdf.supplierReceipt', $datas)->render();

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
            $supplierOrders = SupplierOrder::where('supplier_id', $supplierId)->get()->pluck('id')->toArray();
            $pvas = SupplierOrderPva::whereIn('supplier_order_id', $supplierOrders)->where(['supplier_receipt_id' => null])->get()->pluck('product_variation_attribute_id')->toArray();
            $productIds = ProductVariationAttribute::whereIn('id', $pvas)->get()->pluck('product_id')->toArray();
            //permet de récupérer la liste des regions inactive filtrés
            $filters = HelperFunctions::filterColumns($request['products']['inactive'], ['id', 'code']);
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
            $products = FilterController::searchs(new Request($request['products']['inactive']), $model, ['id', 'title'], false, $associated)->map(function ($product) use ($pvas) {
                $productData = $product->only('id', 'title');
                $productData['productType'] = $product->productType;
                $productData['images'] = [$product->images->first()];
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

    public function store(Request $requests)
    {
        $warehouse = null;
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
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $phone = ProductVariationAttribute::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$phone) {
                        $fail("not exist");
                    }
                },
            ],
            '*.warehouse_id' => [
                'required', 'int',
                function ($attribute, $value, $fail) use (&$warehouse) {
                    $account = getAccountUser()->account_id;
                    $parentWarehouse = Warehouse::where(['warehouse_type_id' => 1, 'account_id' => $account,'id' => $value])->first()->childWarehouses->first();
                    if(!$parentWarehouse){
                        $fail("not exist");
                    }
                    $warehouse = Warehouse::where(['warehouse_type_id' => 3, 'warehouse_id' => $parentWarehouse->id, 'account_id' => $account])->first();
                    if (!$warehouse) {
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
        $supplierReceipts = collect($requests->except('_method'))->map(function ($request) use ($warehouse) {
            $request["account_user_id"] = getAccountUser()->id;
            $account_id = getAccountUser()->account_id;
            $request['code'] = DefaultCodeController::getAccountCode('SupplierReceipt', $account_id);
            $supplierReceipt_only = collect($request)->only('code', 'shipping_date', 'warehouse_id', 'supplier_id', 'statut', 'account_user_id');
            $supplierReceipt = SupplierReceipt::create($supplierReceipt_only->all());
            if (isset($request['productVariationAttributes'])) {
                foreach ($request['productVariationAttributes'] as $pvaData) {
                    $pva = SupplierPva::where(['supplier_id' => $supplierReceipt->supplier_id, 'product_variation_attribute_id' => $pvaData['id']])->first();
                    SupplierOrderPva::create([
                        'product_variation_attribute_id' => $pva->product_variation_attribute_id,
                        'supplier_receipt_id' => $supplierReceipt->id,
                        'quantity' => $pvaData["quantity"],
                        'price' => (isset($pvaData['price'])) ? $pvaData['price'] : $pva->price,
                        'account_user_id' => getAccountUser()->id,
                        'created_at' => now(),
                        'sop_type_id' => 1,
                        'updated_at' => now()
                    ]);
                }
                if ($supplierReceipt->statut == 1) {
                    $receiptData = [
                        'to_warehouse' => $warehouse->id,
                        'statut' => 1,
                        'productVariationAttributes' => $request['productVariationAttributes']
                    ];
                    $receipt = ReceiptController::store(new Request($receiptData));
                    $supplierReceipt->update(['mouvement_id' => $receipt->id]);
                }
            }

            $supplierReceipt = SupplierReceipt::find($supplierReceipt->id);
            return $supplierReceipt;
        });
        return response()->json([
            'statut' => 1,
            'data' => $supplierReceipts,
        ]);
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        $supplierReceipt = SupplierReceipt::with(['warehouse', 'supplier.addresses.city', 'supplier.warehouses', 'supplier.phones.PhoneTypes'])->find($id);
        if (!$supplierReceipt)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        if (isset($request['supplierReceiptInfo'])) {
            $data["supplierReceiptInfo"]['data'] = $supplierReceipt;
            $data['supplierReceiptInfo']['data']['principalImage'] = $supplierReceipt->supplier->images;
        }
        if (isset($request['products']['active'])) {
            // Récupérer les produits du fournisseur avec leurs attributs de variation et types d'attributs
            $filters = HelperFunctions::filterColumns($request['products']['active'], ['title', 'addresse', 'phone', 'products']);
            $supplierProducts = SupplierReceipt::with(['productVariationAttributes.product', 'productVariationAttributes.variationAttribute.childVariationAttributes.attribute.typeAttribute'])->find($id);
            // Mapper les données des produits pour les formater correctement
            $productDatas = $supplierProducts->productVariationAttributes->map(function ($productVariationAttribute) {
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
            });
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
            $supplierProducts = ProductVariationAttribute::with(['product', 'variationAttribute.childVariationAttributes.attribute.typeAttribute'])->whereDoesntHave('supplierReceipts', function ($query) use ($supplierReceipt) {
                $query->where('supplier_receipt_id', $supplierReceipt->id);
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
            $request['warehouses']['active']['whereIn'][0] = ['table' => 'suppliers', 'column' => 'supplier_id', 'value' => $supplierReceipt->supplier_id];
            $request['warehouses']['active']['where'] = ["column" => 'warehouse_type_id', "value" => 1];
            $data['warehouses']['active'] = FilterController::searchs(new Request($request['warehouses']['active']), $model, ['id', 'title'], true);
        }
        if (isset($request['warehouses']['inactive'])) {
            $model = 'App\\Models\\Warehouse';
            //permet de récupérer la liste des regions inactive filtrés
            $request['warehouses']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['warehouses']['inactive']['whereNotIn'][0] = ['table' => 'suppliers', 'column' => 'supplier_id', 'value' => $supplierReceipt->supplier_id];
            $request['warehouses']['inactive']['where'] = ["column" => 'warehouse_type_id', "value" => 1];
            $data['warehouses']['inactive'] = FilterController::searchs(new Request($request['warehouses']['inactive']), $model, ['id', 'title'], true);
        }



        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }
    public function validateReceipt($isExist, $pvaData, $supplierReceipt)
    {
        $supplierOrders = $supplierReceipt->supplier->supplierOrders->where('statut', 1)->pluck('id')->toArray();
        $quantity = $isExist->quantity;
        //récuperer toutes les commandes du fournisseur
        $sops = SupplierOrderPva::where(['product_variation_attribute_id' => $pvaData['id'], 'sop_type_id' => 1, 'statut' => 1])->whereIn('supplier_order_id', $supplierOrders)->get();
        //boucle pour réglé la quantité par la variable $quantity
        foreach ($sops as $key => $sop) {
            $receiptQty = 0;
            $receipts = SupplierOrderPva::where(['product_variation_attribute_id' => $sop->product_variation_attribute_id, 'supplier_order_id' => $sop->supplier_order_id, 'sop_type_id' => $sop->sop_type_id])->whereNot('supplier_receipt_id', null)->get();
            foreach ($receipts as  $receipt) {
                $receiptQty += $receipt->quantity;
            }
            if ($quantity > 0) {
                //si la quantité egale al quantité commandé
                if (($sop->quantity - $receiptQty) == $quantity) {
                    $supplierOrderProduct = SupplierOrderPva::find($sop->id);
                    $supplierOrderProduct->update([
                        "statut" => 2
                    ]);
                    $isExist->update([
                        'supplier_order_id' => $supplierOrderProduct->supplier_order_id,
                        "statut" => 2
                    ]);
                    $quantity = 0;
                    //si la quantité commandé supérieur de la quantité recu
                } elseif (($sop->quantity - $receiptQty) > $quantity) {
                    $supplierOrderProduct = SupplierOrderPva::find($sop->id);
                    $isExist->update([
                        'supplier_order_id' => $supplierOrderProduct->supplier_order_id,
                        "statut" => 2
                    ]);
                    $quantity = 0;
                } elseif (($sop->quantity - $receiptQty) < $quantity) {
                    $supplierOrderProduct = SupplierOrderPva::find($sop->id);
                    $supplierOrderProduct->update([
                        "statut" => 2
                    ]);
                    SupplierOrderPva::create([
                        'supplier_order_id' => $supplierOrderProduct->supplier_order_id,
                        'product_variation_attribute_id' => $supplierOrderProduct->product_variation_attribute_id,
                        'supplier_receipt_id' => $isExist->supplier_receipt_id,
                        'price' => $supplierOrderProduct->price,
                        'sop_type_id' => $supplierOrderProduct->sop_type_id,
                        'quantity' => ($sop->quantity - $receiptQty),
                        'account_user_id' => getAccountUser()->id,
                        "statut" => 2,
                    ]);
                    $quantity -= ($sop->quantity - $receiptQty);
                    $isExist->update([
                        'supplier_order_id' => $supplierOrderProduct->supplier_order_id,
                        "quantity" => $quantity
                    ]);
                }
            }
        }
        //si y des quantité plus que les quantité commande en ajoute une commande pour les quantité de plus
        if ($quantity > 0) {
            $supplierOrder = SupplierOrder::create([
                'code' => 'AN' . DefaultCodeController::getAccountCode('SupplierOrder', getAccountUser()->account_id),
                'supplier_id' => $supplierReceipt->supplier_id,
                'account_user_id' => getAccountUser()->id,
                'warehouse_id' => $supplierReceipt->warehouse_id,
                'statut' => 3,
            ]);
            $isExist->update([
                'supplier_order_id' => $supplierOrder->id,
                "quantity" => $quantity,
                "statut" => 2,
                "sop_type_id" => 2,
            ]);
        }
        // WarehouseController::receipt($supplierReceipt->warehouse_id,$pvaData);
    }
    public function update(Request $requests)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => [ // Validate title field
                'required', // Title is required
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $account_id = getAccountUser()->account_id;
                    $accountUsers = AccountUser::where(['account_id' => $account_id, 'statut' => 1])->get()->pluck('id')->toArray();
                    $titleModel = SupplierReceipt::where(['id' => $value])->whereIn('account_user_id', $accountUsers)->first();
                    if (!$titleModel) {
                        $fail("not exist");
                    } elseif ($titleModel->statut == 2) {
                        $fail("not athorized");
                    }
                },
            ],
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
            '*.validate' => 'boolean',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $supplierReceipts = collect($requests->except('_method'))->map(function ($request) {
            if ($request['validate'])
                $request['statut'] = 2;
            $supplierReceipt_only = collect($request)->only('id', 'shipping_date', 'warehouse_id', 'supplier_id', 'statut', 'statut');
            $supplierReceipt = SupplierReceipt::find($supplierReceipt_only['id']);
            $supplierReceipt->update($supplierReceipt_only->all());
            if (isset($request['productVariationAttributes'])) {
                //récupérer les ids produits de la commandes 
                $supplierReceiptProducts = SupplierOrderPva::where('supplier_order_id', $request['id'])->get();
                $supplierReceiptProductIds = collect($request['productVariationAttributes'])->pluck('id')->toArray();
                //récupérer les enregistrements a supprimée
                $sopToDeletes = $supplierReceiptProducts->map(function ($supporderproduct) use ($supplierReceiptProductIds) {
                    if (!in_array($supporderproduct->product_variation_attribute_id, $supplierReceiptProductIds))
                        return $supporderproduct->id;
                })->filter();
                //supprimer les produits manquant
                $sopToDeletes->map(function ($sopToDelete) {
                    $supplierReceiptProduct = SupplierOrderPva::find($sopToDelete);
                    $supplierReceiptProduct->delete();
                });
                foreach ($request['productVariationAttributes'] as $pvaData) {
                    $isExist = SupplierOrderPva::where(['product_variation_attribute_id' => $pvaData['id'], 'supplier_receipt_id' => $supplierReceipt->id, 'statut' => 1])->first();
                    if ($isExist) {
                        $isExist->update([
                            'quantity' => (isset($pvaData['quantity'])) ? $pvaData['quantity'] : $isExist->quantity,
                            'price' => (isset($pvaData['price'])) ? $pvaData['price'] : $isExist->price,
                        ]);
                        if ($request['validate'] == 1) {
                            $this->validateReceipt($isExist, $pvaData, $supplierReceipt);
                        }
                    } else {
                        //verifier si le produit exist dans les les produits du fournisseur
                        $pva = SupplierPva::where(['supplier_id' => $supplierReceipt->supplier_id, 'product_variation_attribute_id' => $pvaData['id'], 'statut' => 1])->first();
                        if ($pva) {
                            $newSop = SupplierOrderPva::create([
                                'product_variation_attribute_id' => $pva->product_variation_attribute_id,
                                'supplier_receipt_id' => $supplierReceipt->id,
                                'quantity' => $pvaData["quantity"],
                                'price' => (isset($pvaData['price'])) ? $pvaData['price'] : $pva->price,
                                'account_user_id' => getAccountUser()->id,
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);
                            if ($request['validate'] == 1) {
                                $this->validateReceipt($newSop, $pvaData, $supplierReceipt);
                            }
                        }
                    }
                }
            }

            $supplierReceipt = SupplierReceipt::find($supplierReceipt->id);
            return $supplierReceipt;
        });
        return response()->json([
            'statut' => 1,
            'data' => $supplierReceipts,
        ]);
    }

    // public function update(Request $request, $id){

    //     $account_user = User::find(Auth::user()->id)->account_user->first();
    //     $account = User::find(Auth::user()->id)->accounts->first();
    //     $accounts_users = account::find($account_user->account_id)->account_user->pluck('id')->toArray();
    //     $products= account::with(['products'=>function($query) use($id, $request){
    //         $query->with(['product_variationAttribute' => function($query) use($id){
    //             //  $query->where('product_variationAttribute.statut', 1);
    //                 $query->with(['supplier_order_product_variationAttribute' => function($query) use($id){
    //                     $query->where('supplier_order_product_variationAttribute.supplier_receipt_id', $id);
    //                 } ]);
    //         }, 'product_supplier' => function($query) use($request){
    //                 $query->where('product_supplier.supplier_id', $request->supplier_id);
    //         }]);
    //     }])->where('id',$account->id)->first()->products;
    //     $product_variationAttributes = collect($products)->map(function($product){
    //         if(count($product->product_supplier) >=1 )
    //             return $product->product_variationAttribute;
    //     })->collapse();
    //     // dd($product_variationAttributes->toArray());
    //     $supplier_orders= account_user::with(['supplier_orders'=>function($query) use($request){
    //         $query->where('supplier_orders.supplier_id', $request->supplier_id)
    //             ->with(['supplier_order_product_variationAttribute' => function($query){
    //             $query->where('supplier_order_product_variationAttribute.quantity', '>', 0);
    //         }]);
    //     }])->whereIn('id', $accounts_users)->first()->supplier_orders;

    //     $supplier_product_variationAttribute = collect($supplier_orders)->map(function($supplier_order){
    //         return $supplier_order->supplier_order_product_variationAttribute;
    //     })->collapse()->sortby('created_at');
    //     // dd($supplier_product_variationAttribute);
    //     Validator::extend('even', function ($attribute, $value)use($product_variationAttributes, $supplier_product_variationAttribute) {
    //         if($product_variationAttributes->contains('id',$value['product_variationAttribute_id'])){
    //             if($supplier_product_variationAttribute->where('product_variationattribute_id',$value['product_variationAttribute_id'])->sum('quantity') - $value['quantity'] >= 0)
    //                 return true;
    //         }
    //         return false;
    //     });
    //     $validator = Validator::make(collect($request->all())->put('id',$id)->all(), [
    //         'id' => 'exists:supplier_receipts,id,account_user_id,'.$account_user->id,
    //     ]);
    //     if($validator->fails()){
    //         return response()->json([
    //             'Validation Error id', $validator->errors()
    //         ]);       
    //     };
    //     $supplier_receipt = supplier_receipt::find($id);
    //     if($supplier_receipt->statut == 1){
    //         return response()->json([
    //             'statut' => 0,
    //             'data'=> 'déja validé, vous n\'avez pas le droit'
    //         ]);
    //     } 

    //     if(isset($request->statut)){
    //         if($request->statut == 2){
    //             supplier_receipt::find($id)->update(['statut' => 1]);
    //             supplier_order_product_variationAttribute::where(['supplier_receipt_id'=>$id, 'statut' => 2])->update('statut', 1);
    //             return response()->json([
    //                 'statut' => 1,
    //                 'data'=> 'bien validé'
    //             ]);
    //     }}
    //     $validator = Validator::make($request->all(), [
    //         'supplier_id' => 'exists:suppliers,id,account_id,'.$account_user->id,
    //         'shipping_date' => 'date',
    //         'product_variationAttribute.*' => 'even',
    //         'product_variationAttribute.*.quantity' => 'required|gt:0',
    //         'statut' => 'required|in:0,1',
    //     ],$messages = [
    //         'even' => 'this supplier_id:'.$request->supplier_id.' donsn\'t have this product_variationAttribute : :input ',
    //     ]);
    //     if($validator->fails()){
    //         return response()->json([
    //             'Validation Error invoice', $validator->errors()
    //         ]);       
    //     };

    //     $supplier_receipt->update($request->only('supplier_id', 'shipping_date'));
    //     $supplier_receipt_updated = supplier_receipt::find($id);
    //     $request_product_variationAttribute = $request->product_variationAttribute;
    //     $products_sizes_updated = $product_variationAttributes->map(function($element) use($id, $request_product_variationAttribute,$request, $account_user, $supplier_product_variationAttribute){
    //         //verifier si le product_variationAttribute a déja une supplier_receipt
    //         if(count($element->supplier_order_product_variationAttribute) >0){
    //             if(collect($request_product_variationAttribute)->contains('product_variationAttribute_id', $element->id)){
    //                 $element_request = collect($request_product_variationAttribute)->firstWhere('product_variationAttribute_id', $element->id);
    //                 //verifier si la validation 0 ou 1
    //                 if($request->statut == 0){
    //                     // si la vaildation est 0 on va pas dimunier la quantité du stock on va seulement changer la qunatité de la ligne supplier_order_product_variationAttribute qui est déja fait
    //                     $product_variationAttribute_updated = supplier_order_product_variationAttribute::find($element->supplier_order_product_variationAttribute->first()->id)
    //                         ->update(['statut' => 2, 'quantity' => $element_request['quantity']]);
    //                         return collect($request_product_variationAttribute)->where('product_variationAttribute_id', $element->id)->first() ;

    //                 }else{
    //                     $quantity_rest = $element_request['quantity'];
    //                     // si la validation est 1 on va changer la qunatité du stock 
    //                     foreach (collect($supplier_product_variationAttribute)->where('product_variationattribute_id',$element->id) as $item) {
    //                         if ($quantity_rest - $item->quantity >= 0) {
    //                             supplier_order_product_variationAttribute::find($item->id)->update(['quantity'=> 0]);
    //                             $quantity_rest -= $item->quantity;
    //                         }else if($quantity_rest - $item->quantity < 0){
    //                             supplier_order_product_variationAttribute::find($item->id)->update(['quantity'=>  $item->quantity -  $quantity_rest]);
    //                             $quantity_rest -= $item->quantity;
    //                         }
    //                         if ($quantity_rest <= 0)
    //                             break;
    //                     }
    //                     $product_variationAttribute_updated = supplier_order_product_variationAttribute::find($element->supplier_order_product_variationAttribute->first()->id)
    //                         ->update(['statut' => 1, 'quantity' => $element_request['quantity']]);
    //                     supplier_receipt::find($id)->update(['statut' => 1]);
    //                     return $product_variationAttribute_updated;
    //                 }
    //             }else{
    //                 $product_variationAttribute_updated = supplier_order_product_variationAttribute::find($element->supplier_order_product_variationAttribute->first()->id)
    //                     ->update(['statut' => 0]); 
    //                     return collect($request_product_variationAttribute)->firstWhere('product_variationAttribute_id', $element->id) ;
    //             }
    //         }else{
    //             if(collect($request_product_variationAttribute)->contains('product_variationAttribute_id', $element->id)){
    //                 $product_price = product_supplier::where(['product_id' => $element->product_id, 'supplier_id' => $request->supplier_id])
    //                                 ->first()->price;
    //                 $product_variationAttribute_receipt_only = collect(collect($request_product_variationAttribute)->firstWhere('product_variationAttribute_id', $element->id))->only('product_variationAttribute_id','quantity')
    //                     ->put('user_id', $account_user->user_id)
    //                     ->put('supplier_receipt_id', $id)
    //                     ->put('price', $product_price)
    //                     ->put('statut', $request->statut == 0 ? 2 : 1)
    //                     ->all();
    //                 $product_variationAttribute_order = supplier_order_product_variationAttribute::create($product_variationAttribute_receipt_only);    
    //                 if($request->statut == 1){
    //                     $element_request = collect($request_product_variationAttribute)->firstWhere('product_variationAttribute_id', $element->id);
    //                     $quantity_rest = $element_request['quantity'];
    //                     foreach (collect($supplier_product_variationAttribute)->where('product_variationattribute_id',$element->id) as $item) {
    //                         if ($quantity_rest - $item->quantity >= 0) {
    //                             supplier_order_product_variationAttribute::find($item->id)->update(['quantity'=> 0]);
    //                             $quantity_rest -= $item->quantity;
    //                         }else if($quantity_rest - $item->quantity < 0){
    //                             supplier_order_product_variationAttribute::find($item->id)->update(['quantity'=>  $item->quantity -  $quantity_rest]);
    //                             $quantity_rest -= $item->quantity;
    //                         }
    //                         if ($quantity_rest <= 0)
    //                             break;
    //                     }
    //                     supplier_receipt::find($id)->update(['statut' => 1]);
    //                     return $product_variationAttribute_order; 
    //                 }
    //                 return $product_variationAttribute_order; 
    //             }
    //         }
    //     })->filter()->values()->toArray();

    // return response()->json([
    //         'statut' => 1,
    //         'porducts_attributes_update' => $products_sizes_updated
    //     ]);
    // }

    public function destroy($id)
    {
        $supplierReceipt = SupplierReceipt::find($id);
        $supplierReceipt->delete();
        return response()->json([
            'statut' => 1,
            'data' => $supplierReceipt,
        ]);
    }
}
