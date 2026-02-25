<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\Image;
use Illuminate\Http\Request;
use App\Models\ProductVariationAttribute;
use App\Models\PvaPack;
use App\Models\VariationAttribute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PVAController extends Controller
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
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:product_variation_attribute,id',
            'productVariationAttributes.*.quantity' => 'required|numeric',
            'productVariationAttributes.*.id' => [
                'required',
                function ($attribute, $value, $fail) {
                    $pvaExist = ProductVariationAttribute::where('account_id', getAccountUser()->account_id)->first();
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
        $pvaPack = PvaPack::create([
            "account_id" => getAccountUser()->account_id,
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

    public function update(Request $requests)
    {
        $account = getAccountUser()->account_id;
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'required|exists:product_variation_attribute,id',
            '*.images.*' => [
                'string',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $image = Image::where(['id' => $value, 'account_id' => $account])->first();
                    if ($image) {
                        $isUnique = \App\Models\Imageable::where('image_id', $image->id)
                            ->where('imageable_type', "App\Models\ProductVariationAttribute")
                            ->first();
                        if ($isUnique) {
                            $fail("exist");
                        }
                    } else {
                        $fail("not exist");
                    }
                },
            ],
            '*.principalImage' => [
                'string',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $image = Image::where(['id' => $value, 'account_id' => $account])->first();
                    if ($image) {
                        $isUnique = \App\Models\Imageable::where('image_id', $image->id)
                            ->where('imageable_type', "App\Models\ProductVariationAttribute")
                            ->first();
                        if ($isUnique) {
                            $fail("exist");
                        }
                    } else {
                        $fail("not exist");
                    }
                },
            ],
            '*.newImages.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }
        $pvas = collect($requests->except('_method'))->map(function ($request) {
            $pva = ProductVariationAttribute::find($request['id']);
            $images = [];
            if (isset($request['newImages'])) {
                foreach ($request['newImages'] as $key => $newImage) {
                    $images[]["image"] = $newImage;
                }
            }
            if (isset($request['newPrincipalImage'])) {
                $images[] = ["image" => $request['newPrincipalImage'], "as_principal" => true];
            }
            $imageData = [
                'title' => $pva->code,
                'type' => 'product_variation_attribute',
                'image_type_id' => 2,
                'images' => $images
            ];
            if ($imageData) {
                $image = ImageController::store(new Request([$imageData]), $pva, false);
            }
            if (isset($request['newPrincipalImage']) && isset($request['principalImage'])) {
                $image = Image::find($request['principalImage'])->first();
                $pva->images()->attach($image->id, ['created_at' => now(), 'updated_at' => now(), 'statut' => 2]);
            } elseif (isset($request['principalImage'])) {
                $image = Image::find($request['principalImage'])->first();
                $pva->images()->attach($image->id, ['created_at' => now(), 'updated_at' => now(), 'statut' => 1]);
            }
            if (isset($request['images'])) {
                foreach ($request['images'] as $imageInfo) {
                    $image = Image::find($imageInfo)->first();
                    $pva->images()->attach($image->id, ['created_at' => now(), 'updated_at' => now(), 'statut' => 2]);
                }
            }
            $pva = ProductVariationAttribute::with('images', 'principalImage')->find($pva->id);
            return $pva;
        });
        return $pvas;
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
