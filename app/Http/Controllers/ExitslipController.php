<?php


namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf as PDF;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use App\Models\Mouvement;
use App\Models\MouvementPva;
use App\Models\ProductVariationAttribute;
use App\Models\WarehousePva;
use Illuminate\Http\Request;
use App\Models\AccountUser;
use App\Models\Account;
use App\Models\Pickup;
use App\Models\Warehouse;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ExitslipController extends Controller
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
        $request['where'] = ['column' => 'mouvement_type_id', 'value' => 6];
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'code'], false, $associated);
        $datas = $datas->map(function ($mouvement) {
            $mouvementData = $mouvement;
            $mouvementData['from_warehouse'] = $mouvement->fromWarehouse;
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
        $pickup = Pickup::find($id);
        $deplacement = $pickup->mouvement;
        $total = 0;
        $datas = [
            "code" => $deplacement->code,
            "user" => $deplacement->accountUser->user->firstname . ' ' . $deplacement->accountUser->user->lastname,
            "fromWarehouse" => $deplacement->fromWarehouse->title,
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
        $html = view('pdf.exitslip', $datas)->render();
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
        $pickup = Pickup::find($id);
        $deplacement = $pickup->mouvement;
        $datas = [];
        $datas['code'] = $pickup->code;
        if (!$deplacement) {
            return response()->json([
                'statut' => 0,
                'error' => 'No mouvement found for this pickup.'
            ], 404);
        }
        $beforeInventory = $deplacement->inventories()
            ->where('warehouse_id', $deplacement->from_warehouse)
            ->where('inventory_type_id', 2)
            ->orderByDesc('id')
            ->first();
        $afterInventory = $deplacement->inventories()
            ->where('warehouse_id', $deplacement->from_warehouse)
            ->where('inventory_type_id', 3)
            ->orderByDesc('id')
            ->first();
        $datas['fromWarehouse']['title'] = $deplacement->fromWarehouse->title;
        if ($beforeInventory) {
            foreach ($beforeInventory->inventoryPvas as $inventoryPva) {
                $variations = $inventoryPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVa) {
                    return $childVa->attribute->title;
                });
                $datas['fromWarehouse']['products'][$inventoryPva->product_variation_attribute_id] = [
                    "title" => $inventoryPva->productVariationAttribute->product->title . ' : ' . implode(", ", $variations->toArray()),
                    "before" => $inventoryPva->quantity,
                ];
            }
        }

        if ($afterInventory) {
            foreach ($afterInventory->inventoryPvas as $inventoryPva) {
                $pvaId = $inventoryPva->product_variation_attribute_id;
                if (!isset($datas['fromWarehouse']['products'][$pvaId])) {
                    $variations = $inventoryPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVa) {
                        return $childVa->attribute->title;
                    });
                    $datas['fromWarehouse']['products'][$pvaId] = [
                        "title" => $inventoryPva->productVariationAttribute->product->title . ' : ' . implode(", ", $variations->toArray()),
                        "before" => 0,
                    ];
                }
                $datas['fromWarehouse']['products'][$pvaId]['after'] = $inventoryPva->quantity;
            }
        }

        foreach ($deplacement->mouvementPvas as $mouvementPva) {
            $pvaId = $mouvementPva->product_variation_attribute_id;
            if (!isset($datas['fromWarehouse']['products'][$pvaId])) {
                $variations = $mouvementPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVa) {
                    return $childVa->attribute->title;
                });
                $datas['fromWarehouse']['products'][$pvaId] = [
                    "title" => $mouvementPva->productVariationAttribute->product->title . ' : ' . implode(", ", $variations->toArray()),
                    "before" => 0,
                    "after" => 0,
                ];
            }
            $datas['fromWarehouse']['products'][$pvaId]['quantity'] = $mouvementPva->quantity;
        }

        foreach ($datas['fromWarehouse']['products'] as $pvaId => $productData) {
            $datas['fromWarehouse']['products'][$pvaId]['before'] = $productData['before'] ?? 0;
            $datas['fromWarehouse']['products'][$pvaId]['after'] = $productData['after'] ?? 0;
            $datas['fromWarehouse']['products'][$pvaId]['quantity'] = $productData['quantity'] ?? 0;
        }
        $html = view('pdf.exitslipInventory', $datas)->render();

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
        // Step 1: Read the payload once (excluding HTTP method override).
        $payload = $request->except('_method');

        // Step 2: Validate warehouse and exitslip line constraints.
        $validator = Validator::make($payload, [
            'from_warehouse' => [
                'required',
                'int',
                function ($attribute, $value, $fail) {
                    // Ensure source warehouse belongs to the current account.
                    $account = getAccountUser()->account_id;
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->whereNot('warehouse_id', null)->first();
                    if (!$warehouse) {
                        $fail("not exist");
                    }
                },
            ],
            'productVariationAttributes.*.id' => [
                'required',
                'numeric',
                function ($attribute, $value, $fail) use ($payload) {
                    // Ensure a source warehouse is present when validating line items.
                    $warehouseId = $payload['from_warehouse'] ?? null;
                    if (!$warehouseId) {
                        $fail("Warehouse ID not found");
                    }
                },
            ],
            'productVariationAttributes.*.quantity' => 'required|numeric',
            'productVariationAttributes.*.price' => 'numeric',
        ]);

        // Step 3: Stop early on validation failure.
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }

        // Step 4: Prepare mouvement header fields.
        $payload['account_user_id'] = getAccountUser()->id;
        $account_id = getAccountUser()->account_id;
        $payload['code'] = DefaultCodeController::getAccountCode('Exitslip', $account_id);
        $payload['mouvement_type_id'] = 6;

        // Step 5: Create exitslip header record.
        $exitslipData = collect($payload)->only('code', 'mouvement_type_id', 'from_warehouse', 'to_warehouse', 'description', 'statut', 'account_user_id');
        $exitslip = Mouvement::create($exitslipData->all());

        // Step 6: If there are no lines, return header directly.
        if (!isset($payload['productVariationAttributes'])) {
            return $exitslip;
        }

        // Step 7: Snapshot inventory before stock movement when exitslip is active.
        if ($exitslip->statut == 1) {
            $inventoryAfter = [[
                "mouvement_id" => $exitslip->id,
                "inventory_type_id" => 2,
                "warehouse_id" => $exitslip->from_warehouse,
                "productVariationAttributes" => collect($payload['productVariationAttributes'])->pluck('id')->toArray(),
            ]];
            InventoryController::store(new Request($inventoryAfter));
        }

        // Step 8: Create each mouvement line, apply stock movement, and link order lines.
        foreach ($payload['productVariationAttributes'] as $pvaData) {
            $newSop = MouvementPva::create([
                'product_variation_attribute_id' => $pvaData['id'],
                'mouvement_id' => $exitslip->id,
                'quantity' => $pvaData['quantity'],
                'price' => isset($pvaData['price']) ? $pvaData['price'] : 0,
                'account_user_id' => getAccountUser()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Move stock immediately for active exitslips.
            if ($exitslip->statut == 1) {
                WarehouseController::mouve($newSop, $exitslip);
            }

            // Link created mouvement line with related order pivot rows when provided.
            $orders = [];
            if (isset($pvaData['orders']) && is_array($pvaData['orders'])) {
                foreach ($pvaData['orders'] as $order) {
                    $orders[$order]['created_at'] = now();
                    $orders[$order]['updated_at'] = now();
                }
            }
            if (!empty($orders)) {
                $newSop->orderPvas()->syncWithoutDetaching($orders);
            }
        }

        // Step 9: Snapshot inventory after stock movement when exitslip is active.
        if ($exitslip->statut == 1) {
            $inventoryBefore = [[
                "mouvement_id" => $exitslip->id,
                "inventory_type_id" => 3,
                "warehouse_id" => $exitslip->from_warehouse,
                "productVariationAttributes" => collect($payload['productVariationAttributes'])->pluck('id')->toArray(),
            ]];
            InventoryController::store(new Request($inventoryBefore));
        }

        // Step 10: Return newly created exitslip.
        return $exitslip;
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
        // Step 1: Read the raw batch payload (exclude HTTP method override field).
        $payloads = $requests->except('_method');

        // Step 2: Validate exitslip headers and line constraints before mutating anything.
        $validator = Validator::make($payloads, [
            '*.id' => [
                'required',
                function ($attribute, $value, $fail) {
                    // Ensure the exitslip exists and belongs to the current account scope.
                    $account_id = getAccountUser()->account_id;
                    $accountUsers = AccountUser::where(['account_id' => $account_id, 'statut' => 1])->pluck('id')->toArray();
                    $titleModel = Mouvement::where(['id' => $value])->whereIn('account_user_id', $accountUsers)->first();
                    if (!$titleModel) {
                        $fail("not exist");
                    }
                },
            ],
            '*.from_warehouse' => [
                'required',
                'int',
                function ($attribute, $value, $fail) {
                    // Ensure the source warehouse belongs to the current account.
                    $account = getAccountUser()->account_id;
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->whereNot('warehouse_id', null)->first();
                    if (!$warehouse) {
                        $fail("not exist");
                    }
                },
            ],
            '*.productVariationAttributes.*.id' => [
                'required',
                'numeric',
                function ($attribute, $value, $fail) use ($payloads) {
                    // Ensure each PVA is available in the selected source warehouse.
                    $keys = explode('.', $attribute);
                    $firstIndex = $keys[0];
                    $warehouseId = $payloads[$firstIndex]['from_warehouse'] ?? null;
                    if (!$warehouseId) {
                        $fail("Warehouse ID not found");
                    }
                    $warehousePva = WarehousePva::where([
                        'product_variation_attribute_id' => $value,
                        'warehouse_id' => $warehouseId,
                    ])->first();
                    if (!$warehousePva) {
                        $fail("Warehouse product not found");
                    }
                },
            ],
            '*.productVariationAttributes.*.quantity' => 'required|numeric',
            '*.productVariationAttributes.*.price' => 'numeric',
        ]);

        // Step 3: Return validation errors immediately if any rule failed.
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }

        // Step 4: Process each exitslip update request independently.
        $exitslips = collect($payloads)->map(function ($payload) {
            // Step 4.1: Update exitslip header fields first.
            $exitslipData = collect($payload)->only('id', 'to_warehouse', 'from_warehouse', 'description', 'statut');
            $exitslip = Mouvement::find($exitslipData['id']);

            // Step 4.2: Keep previous movement state to rollback stock safely.
            $previousStatut = $exitslip->statut;
            $previousFromWarehouse = $exitslip->from_warehouse;
            $previousToWarehouse = $exitslip->to_warehouse;

            // Step 4.3: Persist header changes.
            $exitslip->update($exitslipData->all());

            // Step 4.4: If no lines are provided, return the refreshed exitslip.
            if (!isset($payload['productVariationAttributes'])) {
                return Mouvement::find($exitslip->id);
            }

            // Step 4.5: Read current lines and incoming lines for synchronization.
            $existingLines = MouvementPva::where(['mouvement_id' => $exitslip->id, 'statut' => 1])->get();
            $incomingLines = collect($payload['productVariationAttributes']);
            $incomingPvaIds = $incomingLines->pluck('id')->toArray();

            // Step 4.6: Revert previous stock effect when the previous movement was active.
            if ($previousStatut == 1) {
                foreach ($existingLines as $existingLine) {
                    if ($previousFromWarehouse) {
                        $fromWarehousePva = WarehousePva::firstOrCreate(
                            [
                                'warehouse_id' => $previousFromWarehouse,
                                'product_variation_attribute_id' => $existingLine->product_variation_attribute_id,
                            ],
                            [
                                'quantity' => 0,
                                'statut' => 1,
                            ]
                        );
                        $fromWarehousePva->update(['quantity' => $fromWarehousePva->quantity + $existingLine->quantity]);
                    }

                    if ($previousToWarehouse) {
                        $toWarehousePva = WarehousePva::firstOrCreate(
                            [
                                'warehouse_id' => $previousToWarehouse,
                                'product_variation_attribute_id' => $existingLine->product_variation_attribute_id,
                            ],
                            [
                                'quantity' => 0,
                                'statut' => 1,
                            ]
                        );
                        $toWarehousePva->update(['quantity' => $toWarehousePva->quantity - $existingLine->quantity]);
                    }
                }
            }

            // Step 4.7: Create inventory snapshot before applying new stock movement.
            if ($exitslip->statut == 1) {
                $inventoryAfter = [[
                    "mouvement_id" => $exitslip->id,
                    "inventory_type_id" => 2,
                    "warehouse_id" => $exitslip->from_warehouse,
                    "productVariationAttributes" => $incomingPvaIds,
                ]];
                InventoryController::store(new Request($inventoryAfter));
            }

            // Step 4.8: Remove mouvement lines that no longer exist in the new payload.
            $lineIdsToDelete = $existingLines
                ->filter(function ($line) use ($incomingPvaIds) {
                    return !in_array($line->product_variation_attribute_id, $incomingPvaIds);
                })
                ->pluck('id');

            MouvementPva::whereIn('id', $lineIdsToDelete)->get()->each(function ($lineToDelete) {
                $lineToDelete->delete();
            });

            // Step 4.9: Upsert all incoming mouvement lines.
            foreach ($incomingLines as $pvaData) {
                $line = MouvementPva::where([
                    'product_variation_attribute_id' => $pvaData['id'],
                    'mouvement_id' => $exitslip->id,
                    'statut' => 1,
                ])->first();

                if ($line) {
                    $line->update([
                        'quantity' => isset($pvaData['quantity']) ? $pvaData['quantity'] : $line->quantity,
                        'price' => isset($pvaData['price']) ? $pvaData['price'] : $line->price,
                    ]);
                    continue;
                }

                MouvementPva::create([
                    'product_variation_attribute_id' => $pvaData['id'],
                    'mouvement_id' => $exitslip->id,
                    'quantity' => $pvaData['quantity'],
                    'price' => isset($pvaData['price']) ? $pvaData['price'] : 0,
                    'account_user_id' => getAccountUser()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Step 4.10: Apply stock movement from the final synced line set only once.
            if ($exitslip->statut == 1) {
                $finalLines = MouvementPva::where(['mouvement_id' => $exitslip->id, 'statut' => 1])->get();
                foreach ($finalLines as $finalLine) {
                    WarehouseController::mouve($finalLine, $exitslip);
                }
            }

            // Step 4.11: Create inventory snapshot after applying new stock movement.
            if ($exitslip->statut == 1) {
                $inventoryBefore = [[
                    "mouvement_id" => $exitslip->id,
                    "inventory_type_id" => 3,
                    "warehouse_id" => $exitslip->from_warehouse,
                    "productVariationAttributes" => $incomingPvaIds,
                ]];
                InventoryController::store(new Request($inventoryBefore));
            }

            // Step 4.12: Return the refreshed exitslip model for API response.
            return Mouvement::find($exitslip->id);
        });

        // Step 5: Return success response for the full batch.
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
