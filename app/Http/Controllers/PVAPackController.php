<?php

namespace App\Http\Controllers;

use App\Models\PvaPack;
use Illuminate\Http\Request;
use App\Models\ProductVariationAttribute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PVAPackController extends Controller
{

    public static function index(Request $request, $local = 0, $paginate = false, $productVar = null)
    {
        $filters = HelperFunctions::filterColumns($request->toArray(), ['reference', 'title', 'shipping_price', 'attributes', 'products', 'offers']);
        $account = getAccountUser()->account_id;
        $variationAttributes = ProductVariationAttribute::where('account_id', $account)->get();
        $dataPagination = HelperFunctions::getPagination($variationAttributes, $filters['pagination']['per_page'], $filters['pagination']['current_page']);

        if ($local == 1) {
            if ($paginate == true) {
                return $dataPagination;
            } else {
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


    public static function store(Request $request, $local = 0)
    {
        $account = getAccountUser()->account_id;
        if ($local == 0) {
            $validator = Validator::make($request->all(), [
                'id' => 'required|exists:product_variation_attribute,id',
                'productVariationAttributes.*.quantity' => 'required|numeric',
                'productVariationAttributes.*.id' => [
                    'required',
                    function ($attribute, $value, $fail)  use ($account) {
                        $pvaExist = ProductVariationAttribute::where('account_id', $account)->first();
                        if (!$pvaExist) {
                            $fail('not exist');
                        }
                    },
                ],
            ]);

            if ($validator->fails()) {
                return [
                    'statut' => 0,
                    'data' => $validator->errors()
                ];
            }
        }
        $pvaPack = PvaPack::create([
            "account_id" => $account,
            "product_variation_attribute_id" => $request->id
        ]);

        $childPvas = collect($request['productVariationAttributes'])->map(function ($pvaData) use ($local, $pvaPack) {
            $account = getAccountUser()->account_id;
            $pvaChild = PvaPack::create([
                'product_variation_attribute_id' => $pvaData['id'],
                'quantity' => $pvaData['quantity'],
                'pva_pack_id' => $pvaPack->id,
                'account_id' => $account,
            ]);
            return $pvaChild;
        });
        return $childPvas;
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

    function generateCases($attributes)
    {
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
    public static function variationFilters(Request $request, $local = 0, $filters = 0)
    {
        $account = getAccountUser()->account_id;
        $variationAttributes = VariationAttribute::where(['attribute_id' => null, 'variation_attribute_id' => null])
            ->where('account_id', $account)
            ->with('attributes')->get()
            ->map(function ($variation) {
                $attributes = $variation->attributes->map(function ($attr) {
                    return $attr->title;
                })->toArray();
                return ['id' => $variation->id, "title" => implode("-", $attributes)];
            });
        if ($local == 1) return $variationAttributes;
        return response()->json([
            'statut' => 1,
            'data' => $variationAttributes
        ]);
    }
}
