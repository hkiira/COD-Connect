<?php


namespace App\Http\Controllers;

use App\Models\Mouvement;
use App\Models\MouvementPva;
use App\Models\WarehousePva;
use Illuminate\Http\Request;
use App\Models\AccountUser;
use App\Models\Account;
use App\Models\Warehouse;
use App\Models\User;
use App\Models\Shipment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ReturnController extends Controller
{
    public function index(Request $request)
    {
        $account_user = User::find(Auth::user()->id)->accountUsers->first();
        $accounts_users = Account::find($account_user->account_id)->accountUsers->pluck('id')->toArray();

        $return = AccountUser::with(['returns' => function ($query) use ($request) {
            $query->with('toWarehouse');
        }])->whereIn('id', $accounts_users)->first();


        return response()->json([
            'statut' => 1,
            'data' => $return->returns
        ]);
    }

    public function create(Request $request)
    {
        $account_user = User::find(Auth::user()->id)->accountUsers->first();
        $accounts_users = Account::find($account_user->account_id)->accountUsers->pluck('id')->toArray();
        $validator = Validator::make($request->all(), [
            'warehouse_id' => 'required|exists:warehouses,id,account_id,' . $account_user->account_id,
        ]);
        if ($validator->fails()) {
            return response()->json([
                'Validation Error invoice', $validator->errors()
            ]);
        };

        $mouvements = AccountUser::with(['returns' => function ($query) use ($request) {
            $query->where('warehouse_id', $request->supplier_id)
                ->with(['mouvementProducts' => function ($query) {
                    $query->where('quantity', '>', 0)
                        ->with(['productVariationAttribute' => function ($query) {
                            $query->with('product.images', 'variationAttribute.childVariationAttributes.attribute');
                        }]);
                }]);
        }])->whereIn('id', $accounts_users)->first()->returns;
        $mouvement_product_variationAttribute = collect($mouvements)->map(function ($supplier_order) {
            return $supplier_order->supplierOrderProduct;
        })->collapse()->sortby('created_at')->unique('product_variation_attribute_id')->values()->toArray();
        return response()->json([
            'statut' => 1,
            'data' => $mouvement_product_variationAttribute
        ]);
    }
    public function generatePdf($id)
    {
        $shipment = Shipment::find($id);
        $deplacement = $shipment->childShipments()->where('shipment_type_id', 2)->first()->mouvement;
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
        $html = view('pdf.return', $datas)->render();
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
        $shipment = Shipment::find($id);
        $deplacement = $shipment->childShipments()->where('shipment_type_id', 2)->first()->mouvement;
        $datas = [];
        $datas['code'] = $shipment->code;
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
        $html = view('pdf.returnInventory', $datas)->render();

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
    public static function store(Request $requests, $local = 0)
    {
        $validator = Validator::make($requests->except('_method'), [
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
        $returns = collect($requests->except('_method'))->map(function ($request) {
            $request["account_user_id"] = getAccountUser()->id;
            $account_id = getAccountUser()->account_id;
            $request['code'] = DefaultCodeController::getAccountCode('return', $account_id);
            $request['mouvement_type_id'] = 1;
            $return_only = collect($request)->only('code', 'mouvement_type_id', 'to_warehouse', 'description', 'statut', 'account_user_id');
            $return = Mouvement::create($return_only->all());
            if (isset($request['productVariationAttributes'])) {
                if ($return->statut == 1) {
                    $inventoryAfter[] = [
                        "mouvement_id" => $return->id,
                        "inventory_type_id" => 2,
                        "warehouse_id" => $return->to_warehouse,
                        "productVariationAttributes" => collect($request['productVariationAttributes'])->pluck('id')->toArray()
                    ];
                    InventoryController::store(new Request($inventoryAfter));
                }
                foreach ($request['productVariationAttributes'] as $pvaData) {
                    $newSop = MouvementPva::create([
                        'product_variation_attribute_id' => $pvaData['id'],
                        'mouvement_id' => $return->id,
                        'quantity' => $pvaData["quantity"],
                        'price' => (isset($pvaData['price'])) ? $pvaData['price'] : 0,
                        'account_user_id' => getAccountUser()->id,
                        'created_at' => now(),
                        'sop_type_id' => 1,
                        'updated_at' => now()
                    ]);
                    if ($return->statut == 1) {
                        WarehouseController::mouve($newSop, $return);
                    }
                    $orders = [];
                    foreach ($pvaData['orders'] as $order) {
                        $orders[$order]['created_at'] = now();
                        $orders[$order]['updated_at'] = now();
                    }
                    $newSop->orderPvas()->syncWithoutDetaching($orders);
                }
                if ($return->statut == 1) {
                    $inventoryBefore[] = [
                        "mouvement_id" => $return->id,
                        "inventory_type_id" => 3,
                        "warehouse_id" => $return->to_warehouse,
                        "productVariationAttributes" => collect($request['productVariationAttributes'])->pluck('id')->toArray()
                    ];
                    InventoryController::store(new Request($inventoryBefore));
                }
            }
            return $return;
        });
        if ($local == 1)
            return $returns[0];
        return response()->json([
            'statut' => 1,
            'data' => $returns,
        ]);
    }

    public function edit($id)
    {
        $account_user = User::find(Auth::user()->id)->accountUsers->first();
        $accounts_users = account::find($account_user->account_id)->accountUsers->pluck('id')->toArray();
        $validator = Validator::make(['id' => $id], [
            'id' => 'exists:mouvements,id,account_user_id,' . $account_user->id,
        ]);
        if ($validator->fails()) {
            return response()->json([
                'Validation Error invoice', $validator->errors()
            ]);
        };
        $supplier_order = Mouvement::find($id);
        if ($supplier_order->statut == 1) {
            return response()->json([
                'statut' => 0,
                'data' => 'déja validé, vous n\'avez pas le droit'
            ]);
        }
        $products = AccountUser::with(['returns' => function ($query) use ($id) {
            $query->with(['mouvementProducts' => function ($query) use ($id) {
                $query->with(['productVariationAttribute.product.principalImage', 'productVariationAttribute.variationAttribute.childVariationAttributes']);
            }])->simplePaginate(10, ['*'], 'page', 1);
        }])->whereIn('id', $accounts_users)->first();

        return response()->json([
            'statut' => 1,
            'supplier receipt' => $products
        ]);
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
                    $titleModel = Mouvement::where(['id' => $value])->whereIn('account_user_id', $accountUsers)->first();
                    if (!$titleModel) {
                        $fail("not exist");
                    } elseif ($titleModel->statut == 2) {
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
            '*.validate' => 'boolean',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $returns = collect($requests->except('_method'))->map(function ($request) {
            if (isset($request['validate']) && $request['validate'] == 1)
                $request['statut'] = 2;
            $return_only = collect($request)->only('id', 'to_warehouse', 'description', 'statut');
            $return = Mouvement::find($return_only['id']);
            $return->update($return_only->all());
            if (isset($request['productVariationAttributes'])) {
                //récupérer les ids produits de la commandes 
                $returnProducts = MouvementPva::where('mouvement_id', $request['id'])->get();
                $returnProductIds = collect($request['productVariationAttributes'])->pluck('id')->toArray();
                //récupérer les enregistrements a supprimée
                $mouvementPvaToDeletes = $returnProducts->map(function ($returnProduct) use ($returnProductIds) {
                    if (!in_array($returnProduct->product_variation_attribute_id, $returnProductIds))
                        return $returnProduct->id;
                })->filter();
                //supprimer les produits manquant
                $mouvementPvaToDeletes->map(function ($mouvementPvaToDelete) {
                    $returnProduct = MouvementPva::find($mouvementPvaToDelete);
                    $returnProduct->delete();
                });
                foreach ($request['productVariationAttributes'] as $pvaData) {
                    $isExist = MouvementPva::where(['product_variation_attribute_id' => $pvaData['id'], 'mouvement_id' => $return->id, 'statut' => 1])->first();
                    if ($isExist) {
                        $isExist->update([
                            'quantity' => (isset($pvaData['quantity'])) ? $pvaData['quantity'] : $isExist->quantity,
                            'price' => (isset($pvaData['price'])) ? $pvaData['price'] : $isExist->price,
                        ]);
                        if (isset($request['validate']) && $request['validate'] == 1) {
                            WarehouseController::mouve($isExist, $return);
                        }
                    } else {

                        $newSop = MouvementPva::create([
                            'product_variation_attribute_id' => $pvaData['id'],
                            'mouvement_id' => $return->id,
                            'quantity' => $pvaData["quantity"],
                            'price' => (isset($pvaData['price'])) ? $pvaData['price'] : 0,
                            'account_user_id' => getAccountUser()->id,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                        if (isset($request['validate']) && $request['validate'] == 1) {
                            WarehouseController::mouve($newSop, $return);
                        }
                    }
                }
            }
            $return = Mouvement::find($return->id);
            return $return;
        });
        return response()->json([
            'statut' => 1,
            'data' => $returns,
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
