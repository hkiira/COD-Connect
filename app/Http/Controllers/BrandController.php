<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Brand;
use App\Models\Account;
use App\Models\Source;
use App\Models\Image;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\BrandSourceController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\OfferController;

class BrandController extends Controller
{
    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated = [];
        $model = 'App\\Models\\Brand';
        $request['inAccount'] = ['account_id', getAccountUser()->account_id];
        //permet de récupérer la liste des regions inactive filtrés

        if (isset($request['sources']) && array_filter($request['sources'], function ($value) {
            return $value !== null;
        })) {
            $associated[] = [
                'model' => 'App\\Models\\Source',
                'title' => 'sources',
                'search' => true,
                'column' => 'title',
                'foreignKey' => 'source_id',
                'pivot' => ['table' => 'brands', 'column' => 'title', 'key' => 'id'],
                'select' => array_filter($request['sources'], function ($value) {
                    return $value !== null;
                }),
            ];
        } else {
            $associated[] = [
                'model' => 'App\\Models\\Source',
                'title' => 'sources',
                'search' => true,
            ];
        }
        $associated[] = [
            'model' => 'App\\Models\\Images',
            'title' => 'images',
            'search' => true,
        ];
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'title'], true, $associated);
        return $datas;
    }

    public function create(Request $request)
    {
        //transformer les données sous des array
        $request = collect($request->query())->toArray();
        $brands = [];
        if (isset($request['sources']['inactive'])) {
            $model = 'App\\Models\\Source';
            $request['sources']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            //permet de récupérer la liste des regions inactive filtrés
            $brands['sources']['inactive'] = FilterController::searchs(new Request($request['sources']['inactive']), $model, ['id', 'title'], true, [['model' => 'App\\Models\\Brand', 'title' => 'brands', 'search' => false], ['model' => 'App\\Models\\Images', 'title' => 'images', 'search' => true]]);
        }

        return response()->json([
            'statut' => 1,
            'data' => $brands,
        ]);
    }

    public static function store(Request $requests)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.title' => [
                'required',
                'max:255',
                function ($attribute, $value, $fail) {
                    $account_id = getAccountUser()->account_id;
                    HelperFunctions::uniqueStoreBelongTo('brand', 'title', $value, 'account_id', $account_id, $fail);
                },
            ],
            '*.principalImage' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $principalImage = Image::where('id', $value)->first();
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage == null) {
                        $fail("not exist");
                    } elseif ($principalImage->account_id !== getAccountUser()->account_id) {
                        $fail("not exist");
                    }
                },
            ],
            '*.sources.*' => [function ($attribute, $value, $fail) use ($requests) {
                $account_id = getAccountUser()->account_id;
                $brandExist = Source::where(['account_id' => $account_id, 'id' => $value])->first();
                if (!$brandExist) {
                    $fail('not exist');
                }
            }],
            '*.email' => 'max:255|email',
            '*.website' => 'max:255|url',
            '*.statut' => 'required',
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $brands = collect($requests->except('_method'))->map(function ($request) {
            $request["account_id"] = getAccountUser()->account_id;
            // $request['code']=DefaultCodeController::getAccountCode('Brand',$request["account_id"]);
            $brand_all = collect($request)->all();
            $brand_only = collect($request)->only('code', 'title', 'statut', 'account_id', 'email', 'website');
            $brand = Brand::create($brand_only->all());
            if (isset($request['principalImage'])) {
                $image = Image::find($request['principalImage']);
                $brand->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($request['newPrincipalImage'])) {
                $images[]["image"] = $request['newPrincipalImage'];
                $imageData = [
                    'title' => $brand->title,
                    'type' => 'brand',
                    'image_type_id' => 3,
                    'images' => $images
                ];
                $brand_image = ImageController::store(new Request([$imageData]), $brand);
            }
            if (isset($request['sources'])) {
                foreach ($request['sources'] as $key => $sourceId) {
                    $source = Source::find($sourceId);
                    $source->brands()->attach($brand, ['account_id' => $brand->account_id, 'created_at' => now(), 'updated_at' => now()]);
                    $source->save();
                }
            }
            $brand = Brand::with('images', 'sources')->find($brand->id);
            return $brand;
        });

        return response()->json([
            'statut' => 1,
            'data' =>  $brands,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        $brand = Brand::with('images')->find($id);
        if (!$brand)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        if (isset($request['brandInfo'])) {
            $info = collect($brand)->except('images')->toArray();
            $info['principalImage'] = $brand->images;
            $data["brandInfo"]['data'] = $info;
        }

        if (isset($request['sources']['active'])) {
            $model = 'App\\Models\\Source';
            $request['sources']['active']['whereIn'][0] = ['table' => 'brands', 'column' => 'brand_id', 'value' => $brand->id];
            $request['sources']['active']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $data['sources']['active'] = FilterController::searchs(new Request($request['sources']['active']), $model, ['id', 'title'], true, [['model' => 'App\\Models\\Brand', 'title' => 'brands', 'search' => false], ['model' => 'App\\Models\\Images', 'title' => 'images', 'search' => true]]);
        }

        if (isset($request['sources']['inactive'])) {
            $model = 'App\\Models\\Source';
            $request['sources']['inactive']['whereNotIn'][0] = ['table' => 'brands', 'column' => 'brand_id', 'value' => $brand->id];
            $request['sources']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $data['sources']['inactive'] = FilterController::searchs(new Request($request['sources']['inactive']), $model, ['id', 'title'], true, [['model' => 'App\\Models\\Brand', 'title' => 'brands', 'search' => false], ['model' => 'App\\Models\\Images', 'title' => 'images', 'search' => true]]);
        }

        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }

    public function update(Request $requests, $id)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'required|exists:brands,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('brand', 'title', $value);
                    $titleModel = Brand::where('title', $value)->first();
                    if (!$titleModel) {
                        $fail("exist");
                    }
                },
            ],
            '*.email' => 'max:255|email',
            '*.website' => 'max:255|url',
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
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.sourcesToActive.*' => [function ($attribute, $value, $fail) use ($requests) {
                $account_id = getAccountUser()->account_id;
                $sourceExist = Source::where(['account_id' => $account_id, 'id' => $value])->first();
                if (!$sourceExist) {
                    $fail('not exist');
                }
            }],

            '*.sourcesToInactive.*' => [function ($attribute, $value, $fail) use ($requests) {
                $account_id = getAccountUser()->account_id;
                $sourceExist = Source::where(['account_id' => $account_id, 'id' => $value])->first();
                if (!$sourceExist) {
                    $fail('not exist');
                }
            }],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $brands = collect($requests->except('_method'))->map(function ($request) {
            $brand_all = collect($request)->all();
            $brand = Brand::find($brand_all['id']);
            $brand->update($brand_all);
            if (isset($brand_all['sourcesToInactive'])) {
                foreach ($brand_all['sourcesToInactive'] as $key => $sourceId) {
                    $source = Source::find($sourceId);
                    $source->brands()->detach($brand);
                }
            }
            if (isset($brand_all['sourcesToActive'])) {
                foreach ($brand_all['sourcesToActive'] as $key => $sourceId) {
                    $source = Source::find($sourceId);
                    $source->brands()->syncWithoutDetaching([
                        $brand->id => [
                            'account_id' => $source->account_id,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]
                    ]);
                }
            }
            if (isset($request['principalImage'])) {
                $brand->images()->detach($brand->images->pluck('id')->toArray());
                $image = Image::find($request['principalImage']);
                $brand->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($brand_all['newPrincipalImage'])) {
                $images[]["image"] = $request['newPrincipalImage'];
                $imageData = [
                    'title' => $brand->title,
                    'type' => 'brand',
                    'image_type_id' => 3,
                    'images' => $images
                ];
                $brand_image = ImageController::store(new Request([$imageData]), $brand);
            }
            $brand = Brand::with('images', 'sources')->find($brand->id);
            return $brand;
        });

        return response()->json([
            'statut' => 1,
            'data' => $brands,
        ]);
    }



    public function destroy($id)
    {
        $Brand = Brand::find($id);
        $Brand->delete();
        return response()->json([
            'statut' => 1,
            'data' => $Brand,
        ]);
    }
}
