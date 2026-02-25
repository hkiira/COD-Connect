<?php

namespace App\Http\Controllers;

use App\Models\VariationAttribute;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\AccountUser;
use App\Models\OfferableVariation;
use App\Models\Account;
use App\Models\Attribute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class VariationOfferableController extends Controller
{
    public static function index(Request $request, $local=0,$paginate=false, $productVar =null)
    {
        $filters = HelperFunctions::filterColumns($request->toArray(), ['reference', 'title', 'shipping_price', 'attributes', 'products', 'offers']);
        $account = getAccountUser()->account_id;
        $variationAttributes = VariationAttribute::where(['attribute_id' => null , 'variation_attribute_id' => null])
            ->where('account_id' , $account)
            ->with(['attributes'=>function($query){
                $query->with('typeAttribute');
            },'products'])->get()
            ->map(function($variation) use($filters, $productVar){
                $variation->products = $products = $variation->products->map(function($product){
                    return $product->only('id', 'title');
                });
                if($productVar !=null){
                    $variation->productVariationAttributes = $productVariationAttributes = $variation->productVariationAttributes->map(function($product_variationAttribute) use($productVar){
                        if($product_variationAttribute->statut == 1 and $product_variationAttribute->product_id == $productVar){
                            return $product_variationAttribute->only('id', 'product_id', 'statut');
                        };
                    })->filter();                    
                }
                $variation->attributes = $attributes = $variation->attributes->map(function($attr){
                    return ['id'=>$attr->id, 'title'=>$attr->title,
                                'typeAttributeId'=>$attr->typeAttribute->id,
                                'typeAttributeTitle'=>$attr->typeAttribute->title];
                });
                $attributesExisting = HelperFunctions::filterExisting($filters['filters']['attributes'], $attributes->pluck('id'));
                $productsExisting = HelperFunctions::filterExisting($filters['filters']['products'], $products->pluck('id'));
                if($productsExisting and $attributesExisting ){
                    if($productVar != null){
                        if(count($productVariationAttributes)>0){
                            return $variation->attributes;
                        }
                    }else{
                        return $variation->attributes;
                    }
                    
                }
            })->collapse()->unique('id')->values()->sortBy('typeAttributeId');;
        $dataPagination = HelperFunctions::getPagination($variationAttributes, $filters['pagination']['per_page'], $filters['pagination']['current_page']);

        if($local == 1){
            if($paginate == true){
                return $dataPagination;
            }else{
                return $variationAttributes->toArray();
            }
        };
        return response()->json([
            'statut' => 1,
            'data' => $variationAttributes
        ]);

    }


    public function create()
    {
        //
    }


    public static function store(Request $request)
    {
        return collect($request['variations'])->map(function($data)use($request){
            $isExist=OfferableVariation::where([
                'offerable_id'=>$data,
                'account_id'=>getAccountUser()->account_id,
                'statut'=>1
            ])->get()->first();
            if($isExist){
                $offerableVariation=OfferableVariation::with("childOfferableVariations")->find($isExist->id);
            }else{
                $offerableVariation=OfferableVariation::create([
                    'account_id'=>getAccountUser()->account_id,
                    'statut'=>1,
                ]);
                OfferableVariation::create([
                    'offerable_id'=>$data,
                    'offerable_variation_id'=>$offerableVariation->id,
                    'account_id'=>getAccountUser()->account_id,
                    'statut'=>1,
                ]);
                $offerableVariation=OfferableVariation::with("childOfferableVariations")->find($offerableVariation->id);
            }
            if($request['order_pva']){
                $request['order_pva']->update([
                    'offerable_variation_id' => $offerableVariation->id,
                ]);
            }else{
                $request['pva']->orders()->syncWithoutDetaching([
                    $request['order_id'] => [
                        'offerable_variation_id' => $offerableVariation->id,
                    ]
                ]);
                return $offerableVariation;
            }
        });
        // hna f depart ghadi ndir anaha kankhdem ghair b wa7da safi 
        // donc ghadi nssifte liha l id dial offereable o n crée deux lignes wa7ed parent et wa7ed child o nretourner l parent bach nzido f order_pva
        
    }


    public function show(VariationAttributesController $variationAttributesController)
    {
        //
    }


    public function edit(VariationAttributesController $variationAttributesController)
    {
        //
    }

    public function update(Request $request, VariationAttributesController $variationAttributesController)
    {
        //
    }


    public function destroy($id)
    {
        $VariationAttribute = VariationAttribute::find($id);
        $VariationAttribute->delete();
        return response()->json([
            'statut' => 1,
            'data' => $VariationAttribute,
        ]);
    }
    
    function generateCases($attributes) {
        $cases = [[]];
        foreach ($attributes as $attributeValues) {
          $newCases = [];
          foreach ($cases as $case) {
            foreach ($attributeValues as $value) {
              $newCases[] = array_merge($case, [$value]);
            }
          }
          $cases = $newCases;
        }
        return $cases;
      }
      public static function variationFilters(Request $request, $local=0,$filters = 0)
      {
          $account = getAccountUser()->account_id;
          $variationAttributes = VariationAttribute::where(['attribute_id' => null , 'variation_attribute_id' => null])
              ->where('account_id' , $account)
              ->with('attributes')->get()
              ->map(function($variation){
                  $attributes = $variation->attributes->map(function($attr){
                      return $attr->title;
                  })->toArray();
                  return ['id'=>$variation->id, "title"=>implode("-", $attributes)];
              });
          if($local == 1) return $variationAttributes;
          return response()->json([
              'statut' => 1,
              'data' => $variationAttributes
          ]);
  
      }
}
