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
use App\Models\ProductVariationAttribute;
use App\Models\SupplierOrderProduct;
use App\Models\SupplierOrderPva;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class TransfertController extends Controller
{
    public function index(Request $request)
    {
        $account_user = User::find(Auth::user()->id)->accountUsers->first();
        $accounts_users = Account::find($account_user->account_id)->accountUsers->pluck('id')->toArray();

        $transfert = AccountUser::with(['transferts' => function ($query) use ($request) {
            $query->with('fromWarehouse');
            $query->with('toWarehouse');
        }])->whereIn('id', $accounts_users)->first();


        return response()->json([
            'statut' => 1,
            'data' => $transfert->transferts
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

        $mouvements = AccountUser::with(['transferts' => function ($query) use ($request) {
            $query->where('warehouse_id', $request->supplier_id)
                ->with(['mouvementProducts' => function ($query) {
                    $query->where('quantity', '>', 0)
                        ->with(['productVariationAttribute' => function ($query) {
                            $query->with('product.images', 'variationAttribute.childVariationAttributes.attribute');
                        }]);
                }]);
        }])->whereIn('id', $accounts_users)->first()->transferts;
        $mouvement_product_variationAttribute = collect($mouvements)->map(function ($supplier_order) {
            return $supplier_order->supplierOrderProduct;
        })->collapse()->sortby('created_at')->unique('product_variation_attribute_id')->values()->toArray();
        return response()->json([
            'statut' => 1,
            'data' => $mouvement_product_variationAttribute
        ]);
    }

    public function store(Request $requests)
    {
        $validator = Validator::make($requests->except('_method'), [

            '*.from_warehouse' => [
                'required', 'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->whereNot('warehouse_id', null)->first();
                    if (!$warehouse) {
                        $fail("not exist");
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
                    $warehouseId = $requests[$firstIndex]['warehouse_id'];
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
        $transferts = collect($requests->except('_method'))->map(function ($request) {
            $request["account_user_id"] = getAccountUser()->id;
            $account_id = getAccountUser()->account_id;
            $request['code'] = DefaultCodeController::getAccountCode('Transfert', $account_id);
            $request['mouvement_type_id'] = 1;
            $transfert_only = collect($request)->only('code', 'mouvement_type_id', 'from_warehouse', 'to_warehouse', 'description', 'statut', 'account_user_id');
            $transfert = Mouvement::create($transfert_only->all());
            if (isset($request['productVariationAttributes'])) {
                foreach ($request['productVariationAttributes'] as $pvaData) {
                    $lastPrice = SupplierOrderPva::where(['product_variation_attribute_id' => $pvaData['id']])->orderBy('created_at', 'desc')->first();
                    MouvementPva::create([
                        'product_variation_attribute_id' => $lastPrice->product_variation_attribute_id,
                        'mouvement_id' => $transfert->id,
                        'quantity' => $pvaData["quantity"],
                        'price' => (isset($pvaData['price'])) ? $pvaData['price'] : $lastPrice->price,
                        'account_user_id' => getAccountUser()->id,
                        'created_at' => now(),
                        'sop_type_id' => 1,
                        'updated_at' => now()
                    ]);
                }
            }
            return $transfert;
        });
        return response()->json([
            'statut' => 1,
            'data' => $transferts,
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
        $products = AccountUser::with(['transferts' => function ($query) use ($id) {
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
            '*.from_warehouse' => [
                'required', 'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->whereNot('warehouse_id', null)->first();
                    if (!$warehouse) {
                        $fail("not exist");
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
                    $warehouseId = $requests[$firstIndex]['warehouse_id'];
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
        $transferts = collect($requests->except('_method'))->map(function ($request) {
            if (isset($request['validate']) && $request['validate'] == 1)
                $request['statut'] = 2;
            $transfert_only = collect($request)->only('id', 'from_warehouse', 'to_warehouse', 'description', 'statut');
            $transfert = Mouvement::find($transfert_only['id']);
            $transfert->update($transfert_only->all());
            if (isset($request['productVariationAttributes'])) {
                //récupérer les ids produits de la commandes 
                $transfertProducts = MouvementPva::where('mouvement_id', $request['id'])->get();
                $transfertProductIds = collect($request['productVariationAttributes'])->pluck('id')->toArray();
                //récupérer les enregistrements a supprimée
                $mouvementPvaToDeletes = $transfertProducts->map(function ($transfertProduct) use ($transfertProductIds) {
                    if (!in_array($transfertProduct->product_variation_attribute_id, $transfertProductIds))
                        return $transfertProduct->id;
                })->filter();
                //supprimer les produits manquant
                $mouvementPvaToDeletes->map(function ($mouvementPvaToDelete) {
                    $transfertProduct = MouvementPva::find($mouvementPvaToDelete);
                    $transfertProduct->delete();
                });
                foreach ($request['productVariationAttributes'] as $pvaData) {
                    $isExist = MouvementPva::where(['product_variation_attribute_id' => $pvaData['id'], 'mouvement_id' => $transfert->id, 'statut' => 1])->first();
                    if ($isExist) {
                        $isExist->update([
                            'quantity' => (isset($pvaData['quantity'])) ? $pvaData['quantity'] : $isExist->quantity,
                            'price' => (isset($pvaData['price'])) ? $pvaData['price'] : $isExist->price,
                        ]);
                        if (isset($request['validate']) && $request['validate'] == 1) {
                            WarehouseController::mouve($isExist, $transfert);
                        }
                    } else {

                        $newSop = MouvementPva::create([
                            'product_variation_attribute_id' => $pvaData['id'],
                            'mouvement_id' => $transfert->id,
                            'quantity' => $pvaData["quantity"],
                            'price' => (isset($pvaData['price'])) ? $pvaData['price'] : 0,
                            'account_user_id' => getAccountUser()->id,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                        if (isset($request['validate']) && $request['validate'] == 1) {
                            WarehouseController::mouve($newSop, $transfert);
                        }
                    }
                }
            }
            $transfert = Mouvement::find($transfert->id);
            return $transfert;
        });
        return response()->json([
            'statut' => 1,
            'data' => $transferts,
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
