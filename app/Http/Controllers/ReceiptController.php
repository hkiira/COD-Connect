<?php


namespace App\Http\Controllers;

use niklasravnsborg\LaravelPdf\Facades\Pdf as PDF;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use App\Models\Mouvement;
use App\Models\MouvementPva;
use App\Models\ProductVariationAttribute;
use App\Models\WarehousePva;
use Illuminate\Http\Request;
use App\Models\AccountUser;
use App\Models\Account;
use App\Models\SupplierReceipt;
use App\Models\Warehouse;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ReceiptController extends Controller
{
    public function index(Request $request)
    {
        $searchIds = [];
        $request = collect($request->query())->toArray();
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
        $model = 'App\\Models\\Mouvement';
        $request['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
        $request['where'] = ['column' => 'mouvement_type_id', 'value' => 5];
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'code'], false, $associated);
        $datas = $datas->map(function ($mouvement) {
            $mouvementData = $mouvement;
            $mouvementData['to_warehouse'] = $mouvement->toWarehouse;
            $mouvementData['user'] = [
                "id" => $mouvement->accountUser->user->id,
                "firstname" => $mouvement->accountUser->user->firstname,
                "lastname" => $mouvement->accountUser->user->lastname,
                "images" => $mouvement->accountUser->user->images,
            ];
            $total = 0;
            $quantity = 0;
            $mouvementData['productVariations'] = $mouvement->productVariationAttributes->map(function ($productVariationAttribute) use (&$total, &$quantity) {
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
            $mouvementData['total'] = $total;
            $mouvementData['quantity'] = $quantity;
            return $mouvementData;
        });
        $datas =  HelperFunctions::getPagination($datas, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        return $datas;
    }

    public function generatePdf($id)
    {
        $supplierReceipt = SupplierReceipt::find($id);
        $deplacement = $supplierReceipt->mouvement;
        $total = 0;
        $datas = [
            "code" => $deplacement->code,
            "user" => $deplacement->accountUser->user->firstname . ' ' . $deplacement->accountUser->user->lastname,
            "toWarehouse" => $deplacement->toWarehouse->title,
            "products" => $deplacement->activePvas->map(function ($activePva) use (&$total) {
                $total += $activePva->pivot->quantity * $activePva->pivot->price;
                $variations = $activePva->variationAttribute->childVariationAttributes->map(function ($childVa) {
                    return $childVa->attribute->title;
                });
                return [
                    "title" => $activePva->product->title . ' : ' . implode(", ", $variations->toArray()),
                    "quantity" => $activePva->pivot->quantity,
                    "price" => $activePva->pivot->price,
                ];
            })->toArray(),
            "total" => $total,
        ];
        $html = view('pdf.receipt', $datas)->render();
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
    public function inventoryPdf($id)
    {
        $supplierReceipt = SupplierReceipt::find($id);
        $deplacement = $supplierReceipt->mouvement;
        $datas = [];
        $datas['code'] = $supplierReceipt->code;
        $beforeInventory = $deplacement->inventories()->where('warehouse_id', $deplacement->to_warehouse)->get();
        $datas['toWarehouse']['title'] = $deplacement->toWarehouse->title;
        foreach ($beforeInventory->first()->inventoryPvas as $inventoryPva) {
            $variations = $inventoryPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVa) {
                return $childVa->attribute->title;
            });
            $datas['toWarehouse']['products'][$inventoryPva->product_variation_attribute_id] = [
                "title" => $inventoryPva->productVariationAttribute->product->title . ' : ' . implode(", ", $variations->toArray()),
                "before" => $inventoryPva->quantity,
            ];
        }
        foreach ($beforeInventory->last()->inventoryPvas as $inventoryPva) {
            $datas['toWarehouse']['products'][$inventoryPva->product_variation_attribute_id]['after'] = $inventoryPva->quantity;
        }
        $afterInventory = $deplacement->inventories()->where('warehouse_id', $deplacement->to_warehouse)->get();

        foreach ($deplacement->mouvementPvas as $mouvementPva) {
            $datas['toWarehouse']['products'][$mouvementPva->product_variation_attribute_id]['quantity'] = $mouvementPva->quantity;
        }
        $html = view('pdf.receiptInventory', $datas)->render();

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

    /*public function create(Request $request){
        $account_user = User::find(Auth::user()->id)->accountUsers->first();
        $accounts_users = Account::find($account_user->account_id)->accountUsers->pluck('id')->toArray();
        $validator = Validator::make($request->all(), [
            'warehouse_id' => 'required|exists:warehouses,id,account_id,'.$account_user->account_id,
        ]);
        if($validator->fails()){
            return response()->json([
                'Validation Error invoice', $validator->errors()
            ]);       
        };
        $data=[];
        $request = collect($request->query())->toArray();
        if (isset($request['products']['inactive'])){ 
            $warehousePvas=WarehousePva::where(['warehouse_id'=>$request['warehouse_id']])->get()->pluck('product_variation_attribute_id')->toArray();
            $productIds=ProductVariationAttribute::whereIn('id',$warehousePvas)->pluck('product_id')->unique()->toArray();
            $model = 'App\\Models\\Product';
            //permet de récupérer la liste des regions inactive filtrés
            $request['products']['inactive']['inAccountUser']=['account_user_id',getAccountUser()->account_id];
            $request['products']['inactive']['whereArray']=['column'=>'id','values'=>$productIds];
            $products =FilterController::searchs(new Request($request['products']['inactive']),$model,['id','title'], false,[])->map(function ($product) use($warehousePvas) {

                $productData=$product->only('id','title');
                $productData['productType']=$product->productType;
                $productData['images']=[$product->images->first()];
                $productData['productVariations']=$product->productVariationAttributes->map(function ($productVariationAttribute)use($product,$warehousePvas) {
                    if($product->product_type_id==1){
                        if(in_array($productVariationAttribute->id,$warehousePvas)){
                            $pvaData=["id"=>$productVariationAttribute->id];
                            $pvaData['variations']=$productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                                if($childVariationAttribute->attribute->typeAttribute)
                                return [
                                    "id" => $childVariationAttribute->id,
                                    "type" => $childVariationAttribute->attribute->typeAttribute->title,
                                    "value" => $childVariationAttribute->attribute->title
                                ];
                            })->filter()->values();
                            return $pvaData;
                        }
                    }
                })->filter()->values();
                return $productData;
            });
            $filters = HelperFunctions::filterColumns($request['products']['inactive'], ['id','code']);
            $data['products']['inactive'] =  HelperFunctions::getPagination($products, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }*/

    public static function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'to_warehouse' => [
                'required', 'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->whereNot('warehouse_id', null)->first();
                    if (!$warehouse) {
                        $fail("not exist");
                    }
                },
            ],
            'productVariationAttributes.*.id' => [
                'required', 'numeric',
                function ($attribute, $value, $fail) use ($request) {
                    $keys = explode('.', $attribute); // Sépare la clé en segments
                    $warehouseId = $request['to_warehouse'];
                    if (!$warehouseId) {
                        $fail("Warehouse ID not found");
                    }
                },
            ],
            'productVariationAttributes.*.quantity' => 'required|numeric',
            'productVariationAttributes.*.price' => 'numeric',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $request["account_user_id"] = getAccountUser()->id;
        $account_id = getAccountUser()->account_id;
        $request['code'] = DefaultCodeController::getAccountCode('Receipt', $account_id);
        $request['mouvement_type_id'] = 5;
        $receipt_only = collect($request)->only('code', 'mouvement_type_id', 'to_warehouse', 'description', 'statut', 'account_user_id');
        $receipt = Mouvement::create($receipt_only->all());
        if (isset($request['productVariationAttributes'])) {
            if ($receipt->statut == 1) {
                $inventoryAfter[] = [
                    "mouvement_id" => $receipt->id,
                    "inventory_type_id" => 2,
                    "warehouse_id" => $receipt->to_warehouse,
                    "productVariationAttributes" => collect($request['productVariationAttributes'])->pluck('id')->toArray()
                ];
                InventoryController::store(new Request($inventoryAfter));
            }

            foreach ($request['productVariationAttributes'] as $pvaData) {
                $newSop = MouvementPva::create([
                    'product_variation_attribute_id' => $pvaData["id"],
                    'mouvement_id' => $receipt->id,
                    'quantity' => $pvaData["quantity"],
                    'price' => (isset($pvaData["price"])) ? $pvaData["price"] : 0,
                    'account_user_id' => getAccountUser()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'sop_type_id' => 1,
                ]);

                if ($receipt->statut == 1) {
                    WarehouseController::mouve($newSop, $receipt);
                }
            }
            if ($receipt->statut == 1) {
                $inventoryBefore[] = [
                    "mouvement_id" => $receipt->id,
                    "inventory_type_id" => 3,
                    "warehouse_id" => $receipt->to_warehouse,
                    "productVariationAttributes" => collect($request['productVariationAttributes'])->pluck('id')->toArray()
                ];
                InventoryController::store(new Request($inventoryBefore));
            }
        }
        return $receipt;
    }

    /*public function edit(Request $request, $id)
    { 
        $request = collect($request->query())->toArray();
        $data=[];
        $mouvement = Mouvement::find($id);
        if(!$mouvement)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        $data['mouvement']=$mouvement;
        if (isset($request['products']['active'])){ 
            // Récupérer les produits du fournisseur avec leurs attributs de variation et types d'attributs
            $filters = HelperFunctions::filterColumns($request['products']['active'], ['title', 'addresse', 'phone', 'products']);
            $mouvementPvas = Mouvement::find($id);
            $mouvementPvaDatas=[];
            $mouvementPvas->mouvementPvas->map(function ($mouvementPva)use(&$mouvementPvaDatas) {
                // Créer un tableau avec les données de base du produit
                
                if(!isset($mouvementPvaDatas[$mouvementPva->productVariationAttribute->product->id]))
                    $mouvementPvaDatas[$mouvementPva->productVariationAttribute->product->id] = [
                        "id" => $mouvementPva->productVariationAttribute->product->id,
                        "title" => $mouvementPva->productVariationAttribute->product->title,
                        "reference" => $mouvementPva->productVariationAttribute->product->reference,
                        "created_at" => $mouvementPva->created_at,
                        "principalImage" => $mouvementPva->productVariationAttribute->product->principalImage,
                        "productType" => $mouvementPva->productVariationAttribute->product->productType->only(['id','title']),
                    ];
                $mouvementPvaDatas[$mouvementPva->productVariationAttribute->product->id]['productVariations'][$mouvementPva->id]=[
                    "id"=>$mouvementPva->id,
                    "price" => $mouvementPva->price,
                    "quantity" => $mouvementPva->quantity,
                ];
                $mouvementPvaDatas[$mouvementPva->productVariationAttribute->product->id]['productVariations'][$mouvementPva->id]['variations'] = $mouvementPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                    // Vérifier si l'attribut a un type
                    if ($childVariationAttribute->attribute->typeAttribute) {
                        // Retourner les données formatées pour chaque attribut de variation
                        return [
                            "id" => $childVariationAttribute->id,
                            "type" => $childVariationAttribute->attribute->typeAttribute->title,
                            "value" => $childVariationAttribute->attribute->title
                        ];
                    }
                })->filter();
            });
            $productDatas=collect($mouvementPvaDatas)->map(function($orderDataProduct){
                $orderDataProduct["productVariations"]=collect($orderDataProduct["productVariations"])->values();
                return $orderDataProduct;
            })->values();
            $data['products']['active'] =  HelperFunctions::getPagination(collect($productDatas), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }*/
    public function update(Request $requests)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => [ // Validate title field
                'required', // Title is required
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $account_id = getAccountUser()->account_id;
                    $accountUsers = AccountUser::where(['account_id' => $account_id, 'statut' => 1])->get()->pluck('id')->toArray();
                    $titleModel = Mouvement::where(['id' => $value])->whereIn('account_user_id', $accountUsers)->first();
                    if (!$titleModel) {
                        $fail("not exist");
                    } elseif ($titleModel->statut == 1) {
                        $fail("not athorized");
                    }
                },
            ],
            '*.to_warehouse' => [
                'required', 'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->whereNot('warehouse_id', null)->first();
                    if (!$warehouse) {
                        $fail("not exist");
                    }
                },
            ],
            '*.productVariationAttributes.*.id' => [
                'required', 'numeric',
                function ($attribute, $value, $fail) use ($requests) {
                    $keys = explode('.', $attribute); // Sépare la clé en segments
                    $firstIndex = $keys[0];
                    $warehouseId = $requests[$firstIndex]['to_warehouse'];
                    if (!$warehouseId) {
                        $fail("Warehouse ID not found");
                    }
                    $warehousePva = WarehousePva::where(['product_variation_attribute_id' => $value, 'warehouse_id' => $warehouseId])->first();
                    if (!$warehousePva) {
                        $fail("Warehouse product not found");
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
        $exitslips = collect($requests->except('_method'))->map(function ($request) {
            $exitslip_only = collect($request)->only('id', 'to_warehouse', 'description', 'statut');
            $exitslip = Mouvement::find($exitslip_only['id']);
            $exitslip->update($exitslip_only->all());
            if (isset($request['productVariationAttributes'])) {
                if ($exitslip->statut == 1) {
                    $inventoryAfter[] = [
                        "mouvement_id" => $exitslip->id,
                        "inventory_type_id" => 2,
                        "warehouse_id" => $exitslip->to_warehouse,
                        "productVariationAttributes" => collect($request['productVariationAttributes'])->pluck('id')->toArray()
                    ];
                    InventoryController::store(new Request($inventoryAfter));
                }
                //récupérer les ids produits de la commandes 
                $exitslipProducts = MouvementPva::where('mouvement_id', $request['id'])->get();
                $exitslipProductIds = collect($request['productVariationAttributes'])->pluck('id')->toArray();
                //récupérer les enregistrements a supprimée
                $mouvementPvaToDeletes = $exitslipProducts->map(function ($exitslipProduct) use ($exitslipProductIds) {
                    if (!in_array($exitslipProduct->product_variation_attribute_id, $exitslipProductIds))
                        return $exitslipProduct->id;
                })->filter();
                //supprimer les produits manquant
                $mouvementPvaToDeletes->map(function ($mouvementPvaToDelete) {
                    $exitslipProduct = MouvementPva::find($mouvementPvaToDelete);
                    $exitslipProduct->delete();
                });
                foreach ($request['productVariationAttributes'] as $pvaData) {
                    $isExist = MouvementPva::where(['product_variation_attribute_id' => $pvaData['id'], 'mouvement_id' => $exitslip->id, 'statut' => 1])->first();
                    if ($isExist) {
                        $isExist->update([
                            'quantity' => (isset($pvaData['quantity'])) ? $pvaData['quantity'] : $isExist->quantity,
                            'price' => (isset($pvaData['price'])) ? $pvaData['price'] : $isExist->price,
                        ]);
                        if ($exitslip->statut == 1) {
                            WarehouseController::mouve($isExist, $exitslip);
                        }
                    } else {
                        $newSop = MouvementPva::create([
                            'product_variation_attribute_id' => $pvaData['id'],
                            'mouvement_id' => $exitslip->id,
                            'quantity' => $pvaData["quantity"],
                            'price' => (isset($pvaData['price'])) ? $pvaData['price'] : 0,
                            'account_user_id' => getAccountUser()->id,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                        if ($exitslip->statut == 1) {
                            WarehouseController::mouve($newSop, $exitslip);
                        }
                    }
                }
                if ($exitslip->statut == 1) {
                    $inventoryBefore[] = [
                        "mouvement_id" => $exitslip->id,
                        "inventory_type_id" => 3,
                        "warehouse_id" => $exitslip->to_warehouse,
                        "productVariationAttributes" => collect($request['productVariationAttributes'])->pluck('id')->toArray()
                    ];
                    InventoryController::store(new Request($inventoryBefore));
                }
            }
            $exitslip = Mouvement::find($exitslip->id);
            return $exitslip;
        });
        return response()->json([
            'statut' => 1,
            'data' => $exitslips,
        ]);
    }


    public function destroy($id)
    {
        $mouvement = Mouvement::find($id);
        $mouvement->delete();
        return response()->json([
            'statut' => 1,
            'data' => $mouvement,
        ]);
    }
}
