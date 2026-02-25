<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\User;
use App\Models\WarehousePva;
use Illuminate\Http\Request;
use App\Models\Warehouse;
use App\Models\ProductVariationAttribute;
use App\Models\AccountUser;
use App\Models\Supplier;
use App\Models\Image;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class WarehouseController extends Controller
{
    public static function index(Request $request)
    {
        $searchIds=[];
        $request = collect($request->query())->toArray();
        if(isset($request['suppliers']) && array_filter($request['suppliers'], function($value) { return $value !== null; })){
            foreach ($request['suppliers'] as $supplierId) {
                if(Supplier::find($supplierId))
                    $searchIds=array_merge($searchIds,Supplier::find($supplierId)->warehouses->pluck('id')->unique()->toArray());
            }
            $request['whereArray']=['column'=>'id','values'=>$searchIds];
        }
        if(isset($request['products']) && array_filter($request['products'], function($value) {return $value !== null;})){
            foreach ($request['products'] as $productId) {
                if(Product::find($productId))
                    $searchIds=array_merge($searchIds,Product::find($productId)->warehouses->pluck('id')->toArray());
            }
            $request['whereArray']=['column'=>'id','values'=>$searchIds];
        }
        if(isset($request['users']) && array_filter($request['users'], function($value) { return $value !== null; })){
            foreach ($request['users'] as $userId) {
                if(AccountUser::find($userId))
                    $searchIds=array_merge($searchIds,AccountUser::find($userId)->warehouses->pluck('id')->toArray());
            }
            $request['whereArray']=['column'=>'id','values'=>$searchIds];
        }
        

        $associated[]=[
            'model'=>'App\\Models\\Warehouse',
            'title'=>'childWarehouses',
            'search'=>true,
        ];
        $model = 'App\\Models\\Warehouse';
        $request['where']=['column'=>'warehouse_id','value'=>NULL];
        $request['inAccount']=['account_id',getAccountUser()->account_id];
        $filters = HelperFunctions::filterColumns($request, ['id','title']);
        $datas = FilterController::searchs(new Request($request),$model,['id','title'], false,$associated);
        $warehouses= $datas->map(function ($warehouse) {
            $data['id']=$warehouse->id;
            $data['code']=$warehouse->code;
            $data['title']=$warehouse->title;
            $data['account_id']=$warehouse->account_id;
            $data['statut']=$warehouse->statut;
            $data['created_at']=$warehouse->created_at;
            $data['updated_at']=$warehouse->updated_at;
            $productDatas = $warehouse->activePvas->map(function ($productVariationAttribute) {
                if($productVariationAttribute->product){
                // Créer un tableau avec les données de base du produit
                    $pvaData = [
                        "id" => $productVariationAttribute->id,
                        "quantity" => $productVariationAttribute->pivot->quantity,
                        "title" => $productVariationAttribute->product->title,
                        "created_at" => $productVariationAttribute->product->created_at,
                        "statut" => $productVariationAttribute->product->statut,
                        "images" => $productVariationAttribute->product->images,
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

                    return $pvaData;
                } // Retourner les données formatées du produit
            })->filter();
            $pvas=[];
            foreach ($productDatas as $key => $productData) {
                $pvas[$productData['product_id']][]=["id"=>$productData["id"],"quantity"=>$productData["quantity"],"variations"=>$productData["variations"]];
            }
            $data['suppliers']=$warehouse->suppliers;
            $data['images']=$warehouse->images;
            
            $data['users']=$warehouse->users->map(function($user){
                return $user->user;
            });
            $products=[];
            foreach ($productDatas as $key => $productData) {
                $products[$productData['product_id']]["product_id"] = $productData['product_id'];
                $products[$productData['product_id']]["title"] = $productData['title'];
                $products[$productData['product_id']]["created_at"] = $productData['created_at'];
                $products[$productData['product_id']]["statut"] = $productData['statut'];
                $products[$productData['product_id']]["images"] = $productData['images'];
                $products[$productData['product_id']]["productVariations"]=$pvas[$productData['product_id']];
            }
            $data['products']=collect($products)->values();
            return $data;
        });
        $datas =  HelperFunctions::getPagination(collect($warehouses), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        return $datas;
        
    }

    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $warehouse= []; 
        if (isset($request['products']['inactive'])){ 
            $model = 'App\\Models\\Product';
            //permet de récupérer la liste des regions inactive filtrés
            $request['products']['inactive']['where']=['column'=>'product_type_id','value'=>1];
            $request['products']['inactive']['inAccountUser']=['account_user_id',getAccountUser()->account_id];
            $filters = HelperFunctions::filterColumns($request['products']['inactive'], ['title', 'addresse', 'phone', 'products']);
            $products =FilterController::searchs(new Request($request['products']['inactive']),$model,['id','title'], false,[0=>['model'=>'App\\Models\\ProductVariationAttribute','title'=>'productVariationAttributes.variationAttribute.childVariationAttributes.attribute.typeAttribute','search'=>false]])->map(function ($product) {
                $productData=$product->only('id','title','created_at','statut');
                $productData['images']=$product->images;
                $productData['productType']=$product->productType;
                $productData['productVariations']=$product->productVariationAttributes->map(function ($productVariationAttribute) {
                    $pvaData=["id"=>$productVariationAttribute->id];
                    $pvaData['variations']=$productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                            if($childVariationAttribute->attribute->typeAttribute)
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
            $warehouse['products']['inactive'] =  HelperFunctions::getPagination($products, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['natures']['inactive'])){ 
            $model = 'App\\Models\\WarehouseNature';
            $request['natures']['inactive']['inAccount']=['account_id',getAccountUser()->account_id];
            $warehouse['natures']['inactive'] = FilterController::searchs(new Request($request['natures']['inactive']),$model,['id','title'], true);
        }
        if (isset($request['suppliers']['inactive'])){ 
            $model = 'App\\Models\\Supplier';
            $request['suppliers']['inactive']['inAccount']=['account_id',getAccountUser()->account_id];
            $warehouse['suppliers']['inactive'] = FilterController::searchs(new Request($request['suppliers']['inactive']),$model,['id','title'], true,[['model'=>'App\\Models\\Image','title'=>'images','search'=>false]]);
        }
        if (isset($request['users']['inactive'])){ 
            $account=getAccountUser()->account_id;
            $model = 'App\\Models\\User';
            $request['users']['inactive']['whereIn'][0]=['table'=>'accounts','column'=>'account_id','value'=>$account];
            $warehouse['users']['inactive'] = FilterController::searchs(new Request($request['users']['inactive']),$model,['id','firstname'], true,[['model'=>'App\\Models\\Image','title'=>'images','search'=>false]]);
        }
        
        return response()->json([
            'statut' => 1,
            'data' => $warehouse,
        ]);
    }
    
    // public static function receipt($warehouseId,$pvaData){
    //     $partition=Warehouse::where(['warehouse_id'=>$warehouseId,'warehouse_type_id'=>2])->first();
    //     $rayon=Warehouse::where(['warehouse_id'=>$partition->id,'warehouse_type_id'=>3,'warehouse_nature_id'=>1])->first();
    //     if($rayon){
    //         $variationProduct=WarehousePva::where(['product_variation_attribute_id'=>$pvaData['id'],'warehouse_id'=>$rayon->id])->first();
    //         if($variationProduct){
    //             $variationProduct->update([
    //                 'quantity'=>$variationProduct->quantity+$pvaData['quantity']
    //             ]);
    //         }else{
    //             WarehousePva::create([
    //                 'warehouse_id'=>$rayon->id,
    //                 'quantity'=>$pvaData['quantity'],
    //                 'product_variation_attribute_id'=>$pvaData['id'],
    //                 'statut'=>1
    //             ]);
    //         }
    //     }else{
    //         $rayon=Warehouse::create([
    //             'warehouse_id'=>$partition->id,
    //             'warehouse_nature_id'=>1,
    //             'warehouse_type_id'=>3,
    //             'account_id'=>$partition->account_id,
    //             'title'=>'Rayon Normale ('.$partition->title.')',
    //             'code'=>DefaultCodeController::getAccountCode('Warehouse',getAccountUser()->account_id),
    //         ]);
    //         WarehousePva::create([
    //             'warehouse_id'=>$rayon->id,
    //             'quantity'=>$pvaData['quantity'],
    //             'product_variation_attribute_id'=>$pvaData['id'],
    //             'statut'=>1
    //         ]);
    //     }
    // }

    public static function mouve($pvamouvement,$mouvement){
        $warehousedProduct=null;
        $warehousePva=null;
        if($mouvement->from_warehouse){
            $warehousePva=WarehousePva::where(['warehouse_id'=>$mouvement->from_warehouse,'product_variation_attribute_id'=>$pvamouvement->product_variation_attribute_id])->first();
            if(!$warehousePva)
                $warehousePva=WarehousePva::create([
                    'product_variation_attribute_id'=>$pvamouvement->product_variation_attribute_id,
                    'quantity'=>0,
                    'warehouse_id'=>$mouvement->from_warehouse,
                    'statut'=>1
                ]);
        }
        if($mouvement->to_warehouse){
            $warehousedProduct=WarehousePva::where(['warehouse_id'=>$mouvement->to_warehouse,'product_variation_attribute_id'=>$pvamouvement->product_variation_attribute_id])->first();
            if(!$warehousedProduct)
                $warehousedProduct=WarehousePva::create([
                    'product_variation_attribute_id'=>$pvamouvement->product_variation_attribute_id,
                    'quantity'=>0,
                    'warehouse_id'=>$mouvement->to_warehouse,
                    'statut'=>1
                ]);
        }
        if($mouvement->from_warehouse)
            $warehousePva->update(['quantity'=>($warehousePva->quantity-$pvamouvement->quantity)]);
        if($mouvement->to_warehouse)
            $warehousedProduct->update(['quantity'=>($warehousedProduct->quantity+$pvamouvement->quantity)]);
       
        return "ok";
    }

    public static function store(Request $requests,$local=0,$account=null)
    {
        if($local==0){
            $validator = Validator::make($requests->except('_method'), [
                '*.title' => [ // Validate title field
                    'required', // Title is required
                    'max:255', // Title should not exceed 255 characters
                    function ($attribute, $value, $fail)use($requests){ // Custom validation rule
                        // Call the function to rename removed records
                        RestoreController::renameRemovedRecords('warehouse', 'title', $value);
                        $account_id=getAccountUser()->account_id;
                        $titleModel = Warehouse::where(['title'=>$value])->where('account_id',$account_id)->first();
                        if ($titleModel) {
                            $fail("exist"); 
                        }
                    },
                ],
                '*.productVariations.*' => 'required|exists:product_variation_attribute,id|max:255',
                '*.users.*' => 'required|exists:account_user,id|max:255',
                '*.suppliers.*' => 'required|exists:suppliers,id|max:255',
                '*.principalImage' => [
                    'max:255', 
                    function ($attribute, $value, $fail){ 
                        $principalImage = Image::where('id', $value)->first();
                        if ($principalImage==null) {
                            $fail("not exist"); 
                        }elseif($principalImage->account_id!==getAccountUser()->account_id){
                            $fail("not exist"); 
                        }
                    },
                ],
                '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            ]);
            if($validator->fails()){
                return response()->json([
                    'statut' => 0,
                    'data' => $validator->errors(),
                ]);       
            };
        }
        $warehouses = collect($requests->except('_method'))->map(function ($request)use($local,$account) {
            $accountId=($local==1)?$account->id:getAccountUser()->account_id;
            $warehouse_all=collect($request)->all();
            $warehouse = Warehouse::create([
                'code' => DefaultCodeController::getAccountCode('Warehouse',$accountId),
                'title' => $request['title'],
                'statut' => 1,
                'warehouse_type_id' => 1,
                'account_id'=> $accountId,
                
            ]);
            $partition= Warehouse::create([
                'code'=>DefaultCodeController::getAccountCode('Partition',$accountId),
                'title' => "partition ".$request['title'],
                'statut' => 1,
                'warehouse_type_id' => 2,
                'account_id'=> $accountId,
                'warehouse_id'=> $warehouse->id,
            ]);

            
            if(isset($request['users'])){
                foreach ($request['users'] as $key => $account_user) {
                    $accountUser=AccountUser::where('id',$account_user)->first();
                    $warehouse->accountUsers()->attach($accountUser,['statut'=>1,'created_at'=>now(),'updated_at'=>now()]);
                }
            }
            if(isset($request['suppliers'])){
                foreach ($request['suppliers'] as $key => $supplierId) {
                    $supplier=Supplier::find($supplierId);
                    $warehouse->warehouses()->attach($supplier,['statut'=>1,'created_at'=>now(),'updated_at'=>now()]);
                }
            }
            if(isset($request['productVariations'])){
                foreach ($request['productVariations'] as $key => $productVariation) {
                    $productVAttribute=ProductVariationAttribute::find($productVariation);
                    $productVAttribute->warehouses()->attach($warehouse,['statut'=>1,'quantity'=>0,'created_at'=>now(),'updated_at'=>now()]);
                }
            }
            if(isset($warehouse_all['principalImage'])){
                $image=Image::find($warehouse_all['principalImage']);
                $image->images()->syncWithoutDetaching([
                    $warehouse->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            }elseif(isset($warehouse_all['newPrincipalImage'])){
                $images[]["image"]=$warehouse_all['newPrincipalImage'];
                $imageData=[
                        'title'=>$warehouse->title,
                        'type'=>'warehouse',
                        'image_type_id'=>15,
                        'images'=>$images
                    ];
                ImageController::store( new Request([$imageData]),$warehouse);
            }
            
            $warehouse = Warehouse::with('images','childWarehouses')->find($warehouse->id);

            return $warehouse;
        });
        if($local==1)
            return $warehouses; 
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
        $data=[];
        $warehouse = Warehouse::find($id);
        if(!$warehouse)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        if (isset($request['warehouseInfo'])){
            $data["warehouseInfo"]['data']=collect($warehouse)->except('account_users');
            $data['warehouseInfo']['data']['principalImage']=$warehouse->images;
        }
        //récupérer les produits active et innactive
        if (isset($request['products']['active'])){ 
            // Récupérer les produits du fournisseur avec leurs attributs de variation et types d'attributs
            $filters = HelperFunctions::filterColumns($request['products']['active'], ['title', 'addresse', 'phone', 'products']);
            $warehouseProducts = Warehouse::with(['activePvas.product','activePvas.variationAttribute.childVariationAttributes.attribute.typeAttribute'])->find($id);
            // Mapper les données des produits pour les formater correctement
            $productDatas = $warehouseProducts->activePvas->map(function ($productVariationAttribute) {
                // Créer un tableau avec les données de base du produit
                $pvaData = [
                    "id" => $productVariationAttribute->id,
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
            $pvas=[];
            foreach ($productDatas as $productData) {
                $pvas[$productData['product_id']][]=["id"=>$productData["id"],"quantity"=>$productData["quantity"],"variations"=>$productData["variations"]];
            }
            $products=[];
            foreach ($productDatas as $productData) {
                $products[$productData['product_id']]["id"] = $productData['product_id'];
                $products[$productData['product_id']]["title"] = $productData['title'];
                $products[$productData['product_id']]["created_at"] = $productData['created_at'];
                $products[$productData['product_id']]["statut"] = $productData['statut'];
                $products[$productData['product_id']]["images"] = $productData['images'];
                $products[$productData['product_id']]["productType"] = $productData['productType'];
                $products[$productData['product_id']]["productVariations"]=$pvas[$productData['product_id']];
            }
            $data['products']['active'] =  HelperFunctions::getPagination(collect($products), $filters['pagination']['per_page'], $filters['pagination']['current_page']);

        }
        if (isset($request['products']['inactive'])){ 
             // Récupérer les produits du fournisseur avec leurs attributs de variation et types d'attributs
             $filters = HelperFunctions::filterColumns($request['products']['inactive'], ['title', 'addresse', 'phone', 'products']);
             $warehouseProducts = ProductVariationAttribute::with(['product', 'variationAttribute.childVariationAttributes.attribute.typeAttribute'])->whereDoesntHave('warehouses', function ($query) use ($warehouse) {
                $query->where('warehouse_pva.warehouse_id', $warehouse->id);
            })->get();
             // Mapper les données des produits pour les formater correctement
             $productDatas = $warehouseProducts->map(function ($productVariationAttribute) {
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
                    }// Retourner les données formatées du produit
             })->filter();
            $pvas=[];
            foreach ($productDatas as $key => $productData) {
                $pvas[$productData['product_id']][]=["id"=>$productData["id"],"variations"=>$productData["variations"]];
            }
            $products=[];
            foreach ($productDatas as $key => $productData) {
                $products[$productData['product_id']]["id"] = $productData['product_id'];
                $products[$productData['product_id']]["title"] = $productData['title'];
                $products[$productData['product_id']]["created_at"] = $productData['created_at'];
                $products[$productData['product_id']]["statut"] = $productData['statut'];
                $products[$productData['product_id']]["images"] = $productData['images'];
                $products[$productData['product_id']]["productType"] = $productData['productType'];
                $products[$productData['product_id']]["productVariations"]=$pvas[$productData['product_id']];
            }

            $data['products']['inactive'] =  HelperFunctions::getPagination(collect($products), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        //récupérer les fournisseurs active et innactive
        if (isset($request['suppliers']['active'])){ 
            $model = 'App\\Models\\Supplier';
            //permet de récupérer la liste des regions inactive filtrés
            $request['suppliers']['active']['inAccount']=['account_id',getAccountUser()->account_id];
            $request['suppliers']['active']['whereIn'][0]=['table'=>'warehouses','column'=>'warehouse_supplier.warehouse_id','value'=>$warehouse->id];
            $data['suppliers']['active'] = FilterController::searchs(new Request($request['suppliers']['active']),$model,['id','title'], true,[['model'=>'App\\Models\\Image','title'=>'images','search'=>false]]);
        }
        if (isset($request['suppliers']['inactive'])){ 
            $model = 'App\\Models\\Supplier';
            //permet de récupérer la liste des regions inactive filtrés
            $request['suppliers']['inactive']['inAccount']=['account_id',getAccountUser()->account_id];
            $request['suppliers']['inactive']['whereNotIn'][0]=['table'=>'warehouses','column'=>'warehouse_supplier.warehouse_id','value'=>$warehouse->id];
            $data['suppliers']['inactive'] = FilterController::searchs(new Request($request['suppliers']['inactive']),$model,['id','title'], true,[['model'=>'App\\Models\\Image','title'=>'images','search'=>false]]);
        }

        //récupérer les utilisateurs active et innactive
        if (isset($request['users']['inactive'])){ 
            $account=getAccountUser()->account_id;
            $model = 'App\\Models\\User';
            $accountUsers=$warehouse->accountUsers->pluck('user_id');
            $request['users']['inactive']['whereArray']=['column'=>'id','values'=>$accountUsers];
            $request['users']['inactive']['whereIn'][]=['table'=>'accounts','column'=>'account_id','value'=>$account];
            $data['users']['inactive'] = FilterController::searchs(new Request($request['users']['inactive']),$model,['id','firstname'], true,[['model'=>'App\\Models\\Image','title'=>'images','search'=>false]]);
        }
        //récupérer les utilisateurs active et innactive
        if (isset($request['users']['active'])){ 
            $account=getAccountUser()->account_id;
            $model = 'App\\Models\\User';
            $accountUsers=$warehouse->accountUsers->pluck('user_id');
            $request['users']['active']['whereNotArray']=['column'=>'id','values'=>$accountUsers->toArray()];
            $request['users']['active']['whereIn'][]=['table'=>'accounts','column'=>'account_id','value'=>$account];
            $data['users']['active'] = FilterController::searchs(new Request($request['users']['active']),$model,['id','firstname'], true,[['model'=>'App\\Models\\Image','title'=>'images','search'=>false]]);
        }
        
        return response()->json([
            'statut' => 1,
            'data' =>$data
        ]);
    }

    public function update(Request $requests)
    {
        $phoneableType="App\Models\Warehouse";
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'exists:warehouses,id|max:255',
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
                    $account_id=getAccountUser()->account_id;
                    $titleModel = Warehouse::where('title',$value)->where('account_id',$account_id)->first();
                    $idModel = Warehouse::where('id', $id)->where('account_id',$account_id)->first(); // Find model by ID
                    
                    // Check if a country with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.productVariationsToActive.*' => 'exists:product_variation_attribute,id|max:255',
            '*.usersToActive.*' => 'required|exists:account_user,id|max:255',
            '*.usersToInactive.*' => 'required|exists:account_user,id|max:255',
            '*.suppliersToActive.*' => 'required|exists:suppliers,id|max:255',
            '*.suppliersToInactive.*' => 'required|exists:suppliers,id|max:255',
            '*.productVariationsToInactive.*' => 'exists:product_variation_attribute,id|max:255',
            '*.principalImage' => [
                'max:255', 
                function ($attribute, $value, $fail){ 
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage==null) {
                        $fail("not exist"); 
                    }elseif($principalImage->account_id!==getAccountUser()->account_id){
                        $fail("not exist"); 
                    }
                },
            ],
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $warehouses = collect($requests->except("_method"))->map(function ($request) {
            $request["account_id"]=getAccountUser()->account_id;
            $warehouse_only=collect($request)->only('id','title','statut');
            $warehouse=Warehouse::find($warehouse_only['id']);
            $warehouse->update($warehouse_only->all());
            
            if(isset($request['usersToInactive'])){
                foreach ($request['usersToInactive'] as $key => $userId) {
                    $user = AccountUser::find($userId);
                    $user->warehouses()->detach($warehouse);
                }
            }
            if(isset($request['usersToActive'])){
                foreach ($request['usersToActive'] as $key => $userId) {
                    $user = AccountUser::find($userId);
                    $user->warehouses()->syncWithoutDetaching([$warehouse->id=>['created_at'=>now(),'updated_at'=>now()]]);
                }
            }
            if(isset($request['suppliersToInactive'])){
                foreach ($request['suppliersToInactive'] as $key => $supplierId) {
                    $supplier = Supplier::find($supplierId);
                    $supplier->warehouses()->detach($warehouse);
                }
            }
            if(isset($request['suppliersToActive'])){
                foreach ($request['suppliersToActive'] as $key => $supplierId) {
                    $supplier = Supplier::find($supplierId);
                    $supplier->warehouses()->syncWithoutDetaching([$warehouse->id=>['created_at'=>now(),'updated_at'=>now()]]);
                }
            }
            
            if(isset($request['productVariationstoInactive'])){
                foreach ($request['productVariationstoInactive'] as $key => $productVariationData) {
                    $productVariation = productVariationAttribute::find($productVariationData);
                    if($productVariation){
                        $productVariation->warehouses()->detach($warehouse);
                    }
                }
            }
            if(isset($request['productVariationsToActive'])){
                    foreach ($request['productVariationsToActive'] as $key => $productVariationData) {
                    $productVariation = productVariationAttribute::find($productVariationData);
                    if($productVariation){
                        $productVariation->warehouses()->syncWithoutDetaching([$warehouse->id=>['created_at'=>now(),'updated_at'=>now()]]);
                    }
                }
            }
            if(isset($request['principalImage'])){
                $image=Image::find($request['principalImage']);
                $warehouse->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            }elseif(isset($request['newPrincipalImage'])){
                $images[]["image"]=$request['newPrincipalImage'];
                $imageData=[
                    'title'=>$warehouse->title,
                    'type'=>'warehouse',
                    'image_type_id'=>15,
                    'images'=>$images
                ];
                ImageController::store( new Request([$imageData]),$warehouse);
            }
            
            $warehouse = Warehouse::with('images', 'childWarehouses','parentWarehouse')->find($warehouse->id);
            return $warehouse;
        });
        return response()->json([
            'statut' => 1,
            'data' => $warehouses,
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
