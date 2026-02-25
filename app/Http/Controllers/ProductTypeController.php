<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\ProductType;
use App\Models\Image;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ProductTypeController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated = [];
        $model = 'App\\Models\\ProductType';
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'title'], true, $associated);
        return $datas;
    }
    public function create(Request $request)
    {
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('ProductType', 'title', $value);
                    $titleModel = ProductType::where('title', $value)->first();
                    if ($titleModel) {
                        $fail("exist");
                    }
                },
            ],
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.principalImage' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage == null) {
                        $fail("not exist");
                    } elseif ($principalImage->account_id !== getAccountUser()->account_id) {
                        $fail("not exist");
                    }
                },
            ],
            '*.statut' => 'int',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $productTypes = collect($request->all())->map(function ($productTypeData) {
            $productTypeData['code'] = DefaultCodeController::getCode('ProductType');
            $productType_only = collect($productTypeData)->only('code', 'title', 'statut');
            $productType = ProductType::create($productType_only->all());
            if (isset($productTypeData['principalImage'])) {
                $image = Image::find($productTypeData['principalImage']);
                $productType->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($productTypeData['newPrincipalImage'])) {
                $images[]["image"] = $productTypeData['newPrincipalImage'];
                $imageData = [
                    'title' => $productType->title,
                    'type' => 'productType',
                    'image_type_id' => 16,
                    'images' => $images
                ];
                ImageController::store(new Request([$imageData]), $productType);
            }
            return $productType;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $productTypes,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $productType = ProductType::find($id);
        if (!$productType)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' => ['productTypeInfo' => ['statut' => 1, 'data' => $productType]]
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->except('_method'), [
            '*.id' => 'required|exists:product_types,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('ProductType', 'title', $value);

                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $titleModel = ProductType::where('title', $value)->first();
                    $idModel = ProductType::where('id', $id)->first(); // Find model by ID

                    // Check if a country with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.principalImage' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage == null) {
                        $fail("not exist");
                    } elseif ($principalImage->account_id !== getAccountUser()->account_id) {
                        $fail("not exist");
                    }
                },
            ],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $productTypes = collect($request->except('_method'))->map(function ($productTypeData) {
            $productType_all = collect($productTypeData)->all();
            $productType = ProductType::find($productType_all['id']);
            $productType->update($productType_all);
            if (isset($productTypeData['principalImage'])) {
                $image = Image::find($productTypeData['principalImage']);
                $productType->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($productTypeData['newPrincipalImage'])) {
                $images[]["image"] = $productTypeData['newPrincipalImage'];
                $imageData = [
                    'title' => $productType->title,
                    'type' => 'productType',
                    'image_type_id' => 16,
                    'images' => $images
                ];
                ImageController::store(new Request([$imageData]), $productType);
            }
            return $productType;
        });

        return response()->json([
            'statut' => 1,
            'data' => $productTypes,
        ]);
    }

    public function destroy($id)
    {
        $productType = ProductType::find($id);
        $productType->delete();
        return response()->json([
            'statut' => 1,
            'data' => $productType,
        ]);
    }
}
