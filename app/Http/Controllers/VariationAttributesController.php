<?php

namespace App\Http\Controllers;

use App\Models\VariationAttribute;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\AccountUser;
use App\Models\TypeAttribute;
use App\Models\Account;
use App\Models\Attribute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class VariationAttributesController extends Controller
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


    public static function store(Request $request, $local=0, $validation=1)
    {
        $account = getAccountUser()->account_id;
        $account_users = AccountUser::where(['account_id'=>$account,'statut'=>1])->pluck('id')->toArray();
        if($validation == 1){
            $validator = Validator::make($request->all(), [
                'variations.*' => ['required',
                    function ($attribute, $value, $fail)  use($account_users) {
                        $type_att = TypeAttribute::whereIn('account_user_id', $account_users)->find($value['attribute_type']);
                        if($type_att == null){
                            $fail('The attribute type '.$value['attribute_type'].' is invalid.');
                        }else{
                            $attributes = Attribute::where(['types_attribute_id' => $type_att->id])
                                ->whereIn('account_user_id', $account_users)
                                ->get()->pluck('id')->toArray();
                            foreach($value['elements'] as $element){
                                if(!in_array($element,$attributes)){
                                    $fail('The attribute '.$element.' is invalid.');
                                }
                            }
                        }
                    },
                ],
            ]);

            if($validator->fails()){
                if($local == 1){
                    return [
                        'statut' => 0,
                        'data' => $validator->errors()
                    ];
                }
                return response()->json([
                    'Validation Error', $validator->errors()
                ]);       
            }
            $attributes = collect($request->variations)->pluck('elements')->toArray();
        }else{
            $attributes = collect($request)->toArray();

        }
        $cases = HelperFunctions::generateCases($attributes);
        $variationAttributes =VariationAttribute::with('childVariationAttributes')
            ->where(['attribute_id' => null , 'variation_attribute_id' => null])
            ->get('id')
            ->map(function($element){
                $element->childVariationAttributes =$element->childVariationAttributes->pluck('attribute_id')->toArray();
                return $element->only('id', 'childVariationAttributes');
            })->toArray();
        $variations = array();
        foreach($cases as $case){
            $exists = false ;
            if(count($variationAttributes) > 0){
                foreach($variationAttributes as $variationAttribute){
                    $arr = $variationAttribute['childVariationAttributes'];
                    $same = empty(array_diff($case, $arr)) && empty(array_diff($arr, $case));
                    if ($same) {
                        // la variation déja existe 
                        array_push($variations, $variationAttribute['id']);
                        $exists = true;
                        break;
                    }
                }   
            }
            if(!$exists){
                // la variation n'existe existe pas
                $new_variation = VariationAttribute::create([
                        'code' => "codev",
                        'account_id' => $account,
                ]);
                foreach($case as $attribute){
                    VariationAttribute::create([
                        'code' => "codev".$new_variation->id,
                        'account_id' => $account,
                        'variation_attribute_id' => $new_variation->id,
                        'attribute_id' => $attribute
                    ]);
                }
                array_push($variations, $new_variation->id) ;
            } 
        }
        if($local == 1){
            return $variations;
        }else{
            $variationDatas=VariationAttribute::with('childVariationAttributes.attribute')->whereIn('id',$variations)->get();
            $returnVariation = $variationDatas->map(function ($variationData) {
                $data['id']=$variationData->id;
                $data['variations']= $variationData->childVariationAttributes->map(function ($childVariationAttribute) {
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
                return $data;
            })->filter(); 
            return response()->json([
                'statut' => 1, 
                'data' => $returnVariation,
            ]);
        }
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
