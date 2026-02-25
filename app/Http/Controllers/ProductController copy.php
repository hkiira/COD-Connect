<?php

namespace App\Http\Controllers;

use App\Http\Controllers\ImageController;
use Illuminate\Http\Request;
use App\Models\product;
use App\Models\account_user;
use App\Models\account_product;
use App\Models\account;
use App\Models\product_variationAttribute;
use App\Models\product_offer;
use App\Models\product_supplier;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\VariationAttributesController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\HelperFunctions;
use App\Http\Controllers\CategorieController;

class ProductSaadController extends Controller
{

    public static function index(Request $request, $local=0, $columns=['id','title', 'reference', 'image', 'suppliers','images','offers', 'depot_attributes'],$offerController = false, $paginate=false)
    {
        $account = User::find(Auth::user()->id)->accounts->first();
        $variations = VariationAttributesController::index(new Request(), 1);
        $suppliers = account::find($account->id)->suppliers->map(function($supp){
            return $supp->only('id', 'title');
        });
        $offers = account::with(['offers'=>function($query){
            $query->where('offers.offer_id',null);
        }])->find($account->id)->offers
                ->map(function($offer){
                return $offer->only('id', 'title');
            });
        $variations = VariationAttributesController::variationFilters(new Request(), 1);
        $filters = OfferController::filterColumns($request->toArray(), ['reference', 'title', 'shipping_price', 'suppliers', 'variations', 'offers']);
        $products =account::with(['products'=>function($query) use($account,$request, $filters){
                // $query->with(['images']);
            if($filters['search'] == null){
                    //filtering by columns
                    if($filters['filters']['reference'] != null){
                        $query->where('reference', 'like', "%{$filters['filters']['reference']}%" );
                    }
                    if($filters['filters']['title'] != null){
                        $query->Where('title', 'like', "%{$filters['filters']['title']}%" );
                    }
                }else{
                    $query->where('reference', 'like', "%{$filters['search']}%" )
                        ->orWhere('title', 'like', "%{$filters['search']}%" );
                }
            $query->with(['activeSuppliers' => function($query) use($account){
                $query->where('account_id', $account->id);
            }]);
            $query->with(['account_product' => function($query) use($account){
                $query->where('account_id', $account->id);
                $query->with('activeOffers');
            }]);
            $query->with(['product_variationAttribute' => function($query){
                $query->with(['product_depot'=>function($query){
                    $query->with('depots');
                    $query->with(['product_variationAttributes' => function($query){
                        $query->with(['variationAttributes' => function($query){
                            $query->with('attributes');
                        }]);
                    }]);
                }]);
            }]);
        }])
        ->find($account->id)->products->map(function($product) use($columns, $filters){
            $product->depot_attributes = $depot_attributes = $product->product_variationAttribute->map(function($pva){
                $depots=[];
                foreach($pva->product_depot->toArray() as $pro_depo){
                    $depots[$pro_depo['depots']['code']] =  $pro_depo['quantity'];
                };
                $attributes = $pva->product_depot->first()
                    ->product_variationAttributes->variationAttributes->attributes->map(function($attr){
                        return $attr->title;
                    })->toArray();
                $variationAttributes_id = $pva->product_depot->first()
                    ->product_variationAttributes->variationAttributes->id;
                return array_merge(['id' => $pva->id, 'attribute' =>implode("-", $attributes)],$depots
                        ,["variationAttributes_id"=>$variationAttributes_id]);
            });
            $product->offers = $offers =  $product->accountProducts->first()
                ->activeOffers->map(function($offer){
                        return $offer->only('id', 'title');
                    });
            $product->suppliers = $suppliers = $product->activeSuppliers->map(function($supplier){
                return $supplier->only('id', 'title');
            });

            $product->image =collect($product->images->firstWhere('pivot.statut',1))->only('id','photo', 'photo_dir');
            $product->images = $product->images->map(function($image){
                return $image->only('id','photo', 'photo_dir');
            });
            
            $variationsExisting = HelperFunctions::filterExisting($filters['filters']['variations'], $depot_attributes->pluck('variationAttributes_id'));
            $suppliersExisting = HelperFunctions::filterExisting($filters['filters']['suppliers'], $suppliers->pluck('id'));
            $offersExisting = HelperFunctions::filterExisting($filters['filters']['offers'], $offers->pluck('id'));
            // $product->id = $product->account_product->first()->id;
            if($variationsExisting  and $suppliersExisting and $offersExisting){
                return $product->only($columns);
            }
        })->filter()->values();
        $dataPagination = OfferController::getPagination($products, intval($filters['pagination']['per_page']), intval($filters['pagination']['current_page']));
        if($local == 1){
            if($paginate == false){
                return $products->toArray();
            }
            return $dataPagination;
        };
        return response()->json([
            'statut' => 1,
            'data' => $dataPagination,
            'variations' => $variations,
            'suppliers' => $suppliers,
            'offers' => $offers
        ]);
    }

    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $product = [];
        if (isset($request['suppliers']['inactive'])){
            $product['suppliers']['inactive'] = SupplierController::index(new Request($request['suppliers']['inactive']),1,['id','title'], true);
        }
        if (isset($request['offers']['inactive'])){
            $product['offers']['inactive'] = OfferController::index(new Request($request['offers']['inactive']),1,['id','title'], true);
        }
        if (isset($request['variations']['inactive'])){
            $product['variations']['inactive'] = AttributeTypesController::index(new Request($request['variations']['inactive']), 1);
        }
        if (isset($request['categories']['inactive'])){
            $product['categories']['inactive'] = CategorieController::index(new Request($request['categories']['inactive']), 1,['id','title'], true);
        }
        if (isset($request['images']['inactive'])){
            $product['images']['inactive'] = ImageController::index(new Request($request['images']['inactive']), 1, true);
        }
        return response()->json([
            'statut' => 1,
            'data' => $product,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(),[
            'reference' => 'required',//|unique:products
            'title' => 'required',//|unique:products
            'statut' => 'required',
            'offers' => 'array',
            'offers.*'=>'required|exists:offers,id',
            'suppliers' => 'array',
            'suppliers.*'=>'required|exists:suppliers,id',
            'principalImage' => 'array',
            'images' => 'array',
            'new_principalImage' => 'array',
            'newImages' => 'array',
            'principalImage.*' => 'required|exists:images,id',
            'images.*' => 'required|exists:images,id',
            'new_principalImage.*' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:20480',
            'newImages.*' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:20480',
            'category_id'=>'required|exists:categories,id',
        ]);
        

        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'Validation Errors' => $validator->errors(),
            ]);
        }
        $variations = VariationAttributesController::store(new Request($request->only('variations')), 1);
        if($variations['statut'] == 0 ){
            return response()->json([
                'statut' => 0,
                'Validation variations' =>$variations['statut'] == 1 ? null : $variations['data'] ,
            ]);
        }
        
        $account_user = User::find(Auth::user()->id)->account_user->first();
        $store_product = account_user::find($account_user->id)->products()->create($request->all());
        $account = User::find(Auth::user()->id)->accounts->first();
        $account->products()->attach($store_product->id, ['category_id' => $request->category_id]);

        $new_product = product::find($store_product->id);
        // attacher les attributes a le produit à la table product_variationAttribute
        $new_product->variationAttributes()->attach($variations['data'],['account_user_id' => $account_user->id]);
        $product_variationAttributes = product_variationAttribute::where('product_id',$new_product->id)->get();
        $depots = account::find($account_user->account_id)->depots('id')->pluck('id')->all();
        // attacher les depots a les product_variationAttribute à la table product_depot
        foreach($product_variationAttributes as $product_variationAttribute){
            product_variationAttribute::find($product_variationAttribute->id)->depots()->attach($depots);
        }

        // attacher les offres a le produit à la table product_offer
        $new_product->account_product->first()->offers()->attach($request->offers);
        $new_product->suppliers()->attach($request->suppliers);

        if(is_array($request->new_principalImage)){
            if(count($request->new_principalImage)>0){
                $new_product->new_principalImage = ImageController::store(new Request(['type'=>'product','images'=>$request->new_principalImage]), 1,$new_product, 1 );
            }
        }elseif(is_array($request->principalImage)){
            if(count($request->principalImage)>0){
                $new_product->images()->attach($request->principalImage , ['statut'=>  1 ]);
            }
        } 
        if (is_array($request->images)) {
            if( count($request->images) >0){
                $new_product->images()->attach($request->images);
            }
        }
        if (is_array($request->newImages)) {
            if( count($request->newImages) >0){
                $new_product->newImages = ImageController::store(new Request(['type'=>'product','images'=>$request->newImages]), 1,$new_product, 1 );
            }
        }


        return response()->json([
            'statut' => 1,
            'data' => $new_product,

        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $account_user = User::find(Auth::user()->id)->account_user->first();
        $account = User::find(Auth::user()->id)->accounts->first();;
        $accounts_users = account::find($account_user->account_id)->account_user->pluck('id')->toArray();
        $offer=[];
        $product = product::find($id);

        if (isset($request['productInfo'])){
            $productInfo = collect($product->only('reference', 'title', 'statut'))->toArray();
            $offer['productInfo']['data'] = array_merge($productInfo,
            [
                'category' => $product->categories->map(function($category){
                        return $category->only('id', 'title');
                    })->first(),

                'categories' => $account_user->categories->map(function($category){
                                return $category->only('id', 'title');
                            })->unique('id')->values(),
            ] );
        };
        if (isset($request['offers']['active'])){
            $request['offers']['active']['filters']['products']=[$id];
            $offer['offers']['active'] = OfferController::index(new Request($request['offers']['active']),1,['id','title'], true);
        };
        if (isset($request['suppliers']['active'])){
            $request['suppliers']['active']['filters']['products']=[$id];
            $offer['suppliers']['active'] = SupplierController::index(new Request($request['suppliers']['active']), 1,['id', 'title', 'price'], true);
        };
        if (isset($request['variations']['active'])){
            $request['variations']['active']['filters']['products']=[$id];
            $offer['variations']['active'] = VariationAttributesController::index(new Request($request['variations']['active']), 1, true, $id);
        }
        if (isset($request['images']['active'])){
            $images_active=$product->images;
            $offer['images']['active'] = [
                'principalImage' => $images_active->filter(function ($item) {
                    return $item['pivot']['statut'] == 1;
                })->map(function ($item) {
                    return $item->only('id', 'photo', 'photo_dir');
                })->values()->toArray(),
            
                'images' => $images_active->filter(function ($item) {
                    return $item['pivot']['statut'] == 2;
                })->map(function ($item) {
                    return $item->only('id', 'photo', 'photo_dir');
                })->values()->toArray(),
            ];
            
        };

        if (isset($request['offers']['inactive'])){
            $request['offers']['inactive']['filters']['products']=[$id];
            $offInaFilters = HelperFunctions::filterColumns($request['offers']['inactive'], ['products']);
            $product_offers= OfferController::index(new Request($offInaFilters),1,['id','title'], false);
            $request['offers']['inactive']['filters']['products']=[];
            $all_offers= OfferController::index(new Request($request['offers']['inactive']), 1, ['id','title'], false);
            
            $unique = HelperFunctions::getInactiveData($all_offers, $product_offers);
            $offer['offers']['inactive'] =  HelperFunctions::getPagination(collect($unique), $offInaFilters['pagination']['per_page'], $offInaFilters['pagination']['current_page']);
        };

        if (isset($request['suppliers']['inactive'])){
            $request['suppliers']['inactive']['filters']['products']=[$id];
            $suppInaFilters = HelperFunctions::filterColumns($request['suppliers']['inactive'], ['products']);
            $product_suppliers  = SupplierController::index(new Request($suppInaFilters), 1, ['id','title', 'price'], false);
            $request['suppliers']['inactive']['filters']['products']=[];
            $all_suppliers = SupplierController::index(new Request($request['suppliers']['inactive']), 1, ['id','title', 'price'], false);
            $unique = HelperFunctions::getInactiveData($all_suppliers, $product_suppliers);
            $offer['suppliers']['inactive'] =  HelperFunctions::getPagination(collect($unique), $suppInaFilters['pagination']['per_page'], $suppInaFilters['pagination']['current_page']);
        };

        if (isset($request['variations']['inactive'])){
            $request['variations']['inactive']['filters']['products']=[$id];
            $varInaFilters = HelperFunctions::filterColumns($request['variations']['inactive'], ['products']);
            $product_variations  = VariationAttributesController::index(new Request($varInaFilters), 1, false, $id);
            $request['variations']['inactive']['filters']['products']=[];
            $all_variations = VariationAttributesController::index(new Request($request['variations']['inactive']), 1, false);
            $unique = HelperFunctions::getInactiveData($all_variations, $product_variations);
            $offer['variations']['inactive'] =  HelperFunctions::getPagination(collect($unique), $varInaFilters['pagination']['per_page'], $varInaFilters['pagination']['current_page']);
        };

        if (isset($request['images']['inactive'])){
            $product_images = $product->images->map(function($image){
                return $image->only('id', 'photo', 'photo_dir','pivot');
            })->toArray();
            $all_products = ImageController::index(new Request(['filters'=> ['type'=>'product']]), 1, false);
            $unique = HelperFunctions::getInactiveData($all_products, $product_images);
            $imageInaFilters = HelperFunctions::filterColumns($request['images']['inactive'], ['products']);
            $offer['images']['inactive'] =  HelperFunctions::getPagination(collect($unique), $imageInaFilters['pagination']['per_page'], $imageInaFilters['pagination']['current_page']);
        };

        return response()->json([
            'statut' => 1,
            'data' =>$offer
        ]);
    }


    public  function update(Request $request, $id)
    {
        // tester si il n'y a pas probleme en validation et aprés enregistrer si il ya de nouvaux attributs
        $variations = VariationAttributesController::store(new Request($request->only('variations')), 1);
        $validator = Validator::make($request->input(),[
            'reference' => '',//|unique:products
            'title' => '',//|unique:products
            'statut' => '',
            'category_id'=>'exists:categories,id',
            'offers.*' => 'required|exists:offers,id',
            'suppliers.*' => 'required|exists:suppliers,id',
            'principalImage.*' => 'required|exists:images,id',
            'images.*' => 'required|exists:images,id',
            'new_principalImage.*' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:20480',
            'newImages.*' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:20480',
        ]);
        if($validator->fails() or $variations['statut'] == 0 ){
            return response()->json([
                'Validation variations' =>$variations['statut'] == 1 ? null : $variations['data'] ,
                'Validation Errors' => $validator->errors(),
            ]);
        }
        $account_user = User::find(Auth::user()->id)->account_user->first();
        $product = product::find($id);

        $account_product = account_product::where(['account_id'=>$account_user->account_id, 'product_id'=> $product->id])->first();
        // update les infos du produits
        $product->update($request->all());
        $requestArr = $request->toArray();

        //update the category_id
        if(isset($requestArr['category_id'])){
            $account_product->category_id = $request->category_id;
            $account_product->save();
        }
        //update_product_variationAttributes
        $product->variations = $this->update_product_variationAttributes($product->id, $account_user->account_id, $variations['data']);
        //update offres du produit
        $productChange = [];
        $offToActiExistt = false;
        if(isset($requestArr['offers']['toActive']) and $requestArr['offers']['toActive'] !=1){
            // update les offers du produit
            $productChange['offers']['actived'] = $this->update_product_offers($account_product->id, $request['offers']['toActive'], 1);
        } 
        if(isset($requestArr['offers']['toInactive']) and $requestArr['offers']['toInactive'] !=1){
            // update les offers du produit
            $onlyOffersNotActive =array_diff($requestArr['offers']['toInactive'], $offToActiExistt==true ? $requestArr['suppliers']['toActive']: [] );
            $productChange['offers']['inactived'] = $this->update_product_offers($account_product->id, $onlyOffersNotActive, 0);
        }   
        $supToActiExist = false;
        if(isset($requestArr['suppliers']['toActive']) and $requestArr['suppliers']['toActive'] !=1){
            // update les offers du produit
            $productChange['offers']['actived'] = $this->update_product_suppliers($id, $requestArr['suppliers']['toActive'], 1);
            $supToActiExist = true;
        } 
        if(isset($requestArr['suppliers']['toInactive']) and $requestArr['suppliers']['toInactive'] !=1){
            // update les offers du produit
            $onlySuppliersNotActive = array_diff($requestArr['suppliers']['toInactive'], $supToActiExist==true ? $requestArr['suppliers']['toActive']: [] );
            $product->suppliers['inactived'] = $this->update_product_suppliers($id, $onlySuppliersNotActive, 0);
        }       
        $prinipalImages = [];
        if(is_array($request->new_principalImage)){
            // créer une principal pour le produit
            if(count($request->new_principalImage)>0){
                $new_principalImage = ImageController::store(new Request(['type'=>'product','images'=>$request->new_principalImage]), 1,$product, 1 )
                ->map(function($newPriIma){
                    return $newPriIma->id;
                })->toArray();
                $product->new_principalImage =  ImageController::update(new Request(['images'=>$new_principalImage]),1, $product,$new_principalImage,1);

            }
        }else if(is_array($request->principalImage)){
            // update l'image principal du produit
            $prinipalImages = $request->principalImage;
            if(count($request->principalImage)>0){
                $product->principalImage =  ImageController::update(new Request(['images'=>$request->principalImage]),1, $product,$request->principalImage,1);
            }
        } 
        // créer des nouveaux images pour le produit
        $newImages = [];
        if(is_array($request->newImages)){
            if(count($request->newImages)>0){
                $newImages = ImageController::store(new Request(['type'=>'product','images'=>$request->newImages]), 1,$product,0 )
                ->map(function($newIma){
                    return $newIma->id;
                })->toArray();
            }                
        }
        if(is_array($request->images) || $request->images == 'null'){
                $imagesToUpdate = $request->images == 'null' ? [] : $request->images;
                // update les images du produit
                if($request->images == 'null' and count($newImages)==0 ){
                    $product->newImages =  ImageController::update(new Request(['images'=>$request->images]),1, $product,[],0 );
                }else if(is_array($request->images)){
                    if(count($request->images)>0 ){
                        $onlyNotInPrinipal = array_values(array_diff($imagesToUpdate, $prinipalImages));
                        $mergedImages  = array_merge($newImages, $onlyNotInPrinipal);
                        $product->newImages =  ImageController::update(new Request(['images'=>$request->images]),1, $product,$mergedImages,0 );
                    }
                }
        }
        return response()->json([
            'statut'=>1,
            'data' => $product
        ]);
    }


    public function destroy($id)
    {
        $product_b =  product::find($id);
        $product = product::find($id)->delete();
        return response()->json([
            'statut' => 'deleted successfuly',
            'product' => $product_b,
        ]);
    }


    public function store_product_suppliers($supplier_id, $suppliers){
        $product_variationAttribute = product::find($supplier_id)
                ->suppliers()->attach($suppliers);
    }

    public static function update_product_variationAttributes($product_id, $account_id, $variations)
    {
        // change variations
        $product = product::find($product_id);
        $product_variationAttributes = product::find($product_id)->product_variationAttribute;
        foreach($product_variationAttributes as $product_variationAttribute){
            // dd($product_variationAttribute->variationAttribute_id, $variations);
            if(in_array($product_variationAttribute->variationAttribute_id, $variations) == true){
                // dd($product_variationAttribute->statut, $variations);

                if($product_variationAttribute->statut==0){
                    // dd($product_variationAttribute);
                    product_variationAttribute::where('id',$product_variationAttribute->id)->update(['statut'=>1]);
                }

            }else{
                product_variationAttribute::where('id',$product_variationAttribute->id)->update(['statut'=>0]);
            }
        }
        foreach($variations as $variation){
            $exist = collect($product_variationAttributes)->contains('variationAttribute_id',$variation);
            if($exist == false ){
                // $product->attributes()->attach($variation);
                $product->variationAttributes()->attach($variation,['account_user_id' => 1]);

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
        $statut = $toActivity == 1 ? 0 : 1 ; 
        $changed = [];
        foreach($product_offers as $product_offer){
            if(in_array($product_offer->offer_id, $offers) == true){
                if( $product_offer->statut== $statut){
                    product_offer::where('id',$product_offer->id)->update(['statut'=> !$statut]);
                    array_push($changed, $product_offer->toArray());
                }

            }
        }
        if($toActivity = 1){
            foreach($offers as $offer){
                $exist = collect($product_offers)->contains('offer_id', $offer);
                if($exist == false ){
                    $account_product->offers()->attach($offer);
                    array_push($changed, product_offer::firstWhere(['offer_id' =>$offer,
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
        $statut = $toActivity == 1 ? 0 : 1 ; 
        $changed = [];
        foreach($product_suppliers as $product_supplier){
            if(in_array($product_supplier->supplier_id, $suppliers) == true){
                if($product_supplier->status == $statut || $product_supplier->price != $supplierPrice ){
                    product_supplier::where('id',$product_supplier->id)->update(['status'=> !$statut , 'price' => $supplierPrice]);
                    array_push($changed, $product_supplier->toArray());
                }
            }
        }
        if($toActivity = 1){
            foreach($suppliers as $supplier){
                $exist = collect($product_suppliers)->contains('supplier_id', $supplier);
                if($exist == false ){
                    $product->suppliers()->attach($supplier , ['price', $supplierPrice]);
                    array_push($changed, product_supplier::firstWhere(['supplier_id' =>$supplier,
                    'product_id' => $productId
                ]));
                }
            }
        }

        return $changed;
    }

}