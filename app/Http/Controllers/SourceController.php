<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Source;
use App\Models\Brand;
use App\Models\Image;
use App\Models\User;
use App\Models\Account;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class sourceController extends Controller
{
    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated = [];
        $model = 'App\\Models\\Source';
        $request['inAccount'] = ['account_id', getAccountUser()->account_id];
        //permet de récupérer la liste des regions inactive filtrés

        if (isset($request['brands']) && array_filter($request['brands'], function ($value) {
            return $value !== null;
        })) {
            $associated[] = [
                'model' => 'App\\Models\\Brand',
                'title' => 'brands',
                'search' => true,
                'column' => 'title',
                'foreignKey' => 'brand_id',
                'pivot' => ['table' => 'sources', 'column' => 'title', 'key' => 'id'],
                'select' => array_filter($request['brands'], function ($value) {
                    return $value !== null;
                }),
            ];
        } else {
            $associated[] = [
                'model' => 'App\\Models\\Brand',
                'title' => 'brands',
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
        $sources = [];
        if (isset($request['brands']['inactive'])) {
            $model = 'App\\Models\\Brand';
            $request['brands']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            //permet de récupérer la liste des regions inactive filtrés
            $sources['brands']['inactive'] = FilterController::searchs(new Request($request['brands']['inactive']), $model, ['id', 'title'], true, [['model' => 'App\\Models\\Source', 'title' => 'sources', 'search' => false], ['model' => 'App\\Models\\Images', 'title' => 'images', 'search' => true]]);
        }

        return response()->json([
            'statut' => 1,
            'data' => $sources,
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
                    HelperFunctions::uniqueStoreBelongTo('source', 'title', $value, 'account_id', $account_id, $fail);
                },
            ],
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
            '*.statut' => 'required',
            '*.brands.*' => [function ($attribute, $value, $fail) use ($requests) {
                $account_id = getAccountUser()->account_id;
                $brandExist = Brand::where(['account_id' => $account_id, 'id' => $value])->first();
                if (!$brandExist) {
                    $fail('not exist');
                }
            }],
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $sources = collect($requests->except('_method'))->map(function ($request) {
            $request["account_id"] = getAccountUser()->account_id;
            // $request['code']=DefaultCodeController::getAccountCode('Source',$request["account_id"]);
            $source_only = collect($request)->only('code', 'title', 'statut', 'account_id');
            $source = Source::create($source_only->all());
            if (isset($request['principalImage'])) {
                $image = Image::find($request['principalImage']);
                $source->images()->syncWithoutDetaching([
                    $source->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($request['newPrincipalImage'])) {
                $images[]["image"] = $request['newPrincipalImage'];
                $imageData = [
                    'title' => $source->title,
                    'type' => 'source',
                    'image_type_id' => 4,
                    'images' => $images
                ];
                ImageController::store(new Request([$imageData]), $source);
            }
            if (isset($request['brands'])) {
                foreach ($request['brands'] as $key => $sourceId) {
                    $brand = Brand::find($sourceId);
                    $brand->sources()->attach($source, [
                        'account_id' => $source->account_id,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    $brand->save();
                }
            }
            $source = Source::with('images', 'brands')->find($source->id);
            return $source;
        });

        return response()->json([
            'statut' => 1,
            'data' =>  $sources,
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
        $source = Source::with('images', 'brands')->find($id);
        if (!$source)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        if (isset($request['sourceInfo'])) {
            $info = collect($source)->except('images', 'brands')->toArray();
            $info['principalImage'] = $source->images;
            $info['brands'] = $source->brands->map(function ($brand) {
                return $brand->only('id', 'title');
            })->values();
            $data["sourceInfo"]['data'] = $info;
        }
        if (isset($request['brands']['active'])) {
            $model = 'App\\Models\\Brand';
            $request['brands']['active']['whereIn'][0] = ['table' => 'sources', 'column' => 'source_id', 'value' => $source->id];
            $request['brands']['active']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $data['brands']['active'] = FilterController::searchs(new Request($request['brands']['active']), $model, ['id', 'title'], true, [['model' => 'App\\Models\\Source', 'title' => 'sources', 'search' => false], ['model' => 'App\\Models\\Images', 'title' => 'images', 'search' => true]]);
        }

        if (isset($request['brands']['inactive'])) {
            $model = 'App\\Models\\Brand';
            $request['brands']['inactive']['whereNotIn'][0] = ['table' => 'sources', 'column' => 'source_id', 'value' => $source->id];
            $request['brands']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $data['brands']['inactive'] = FilterController::searchs(new Request($request['brands']['inactive']), $model, ['id', 'title'], true, [['model' => 'App\\Models\\Source', 'title' => 'sources', 'search' => false], ['model' => 'App\\Models\\Images', 'title' => 'images', 'search' => true]]);
        }

        if (isset($request['brands']['all'])) {
            $model = 'App\\Models\\Brand';
            $request['brands']['all']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['brands']['all']['statut'] = 1;
            $data['brands']['all'] = FilterController::searchs(new Request($request['brands']['all']), $model, ['id', 'title'], true, [['model' => 'App\\Models\\Source', 'title' => 'sources', 'search' => false], ['model' => 'App\\Models\\Images', 'title' => 'images', 'search' => true]]);
        }

        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }

    public function update(Request $requests, $id)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'required|exists:sources,id',
            '*.title' => [
                'max:255',
                function ($attribute, $value, $fail) use ($requests) {
                    $account_id = getAccountUser()->account_id;
                    $index = str_replace(['*', '.title'], '', $attribute);
                    $id = $requests->input("{$index}.id");
                    HelperFunctions::uniqueUpdateBelongTo('source', 'title', $value, 'account_id', $account_id, $id, $fail);
                },
            ],
            '*.principalImage' => [
                'max:255',
                function ($attribute, $value, $fail) {
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage == null) {
                        $fail("not exist");
                    } elseif ($principalImage->account_id !== getAccountUser()->account_id) {
                        $fail("not exist");
                    }
                },
            ],
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.brandsToActive.*' => [function ($attribute, $value, $fail) use ($requests) {
                $account_id = getAccountUser()->account_id;
                $brandExist = Brand::where(['account_id' => $account_id, 'id' => $value])->first();
                if (!$brandExist) {
                    $fail('not exist');
                }
            }],

            '*.brandsToInactive.*' => [function ($attribute, $value, $fail) use ($requests) {
                $account_id = getAccountUser()->account_id;
                $brandExist = Brand::where(['account_id' => $account_id, 'id' => $value])->first();
                if (!$brandExist) {
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
        $sources = collect($requests->except('_method'))->map(function ($request) {
            $source_all = collect($request)->all();
            $source = Source::find($source_all['id']);
            $source->update($source_all);
            if (isset($source_all['brandsToInactive'])) {
                foreach ($source_all['brandsToInactive'] as $key => $brandId) {
                    $brand = Brand::find($brandId);
                    $brand->sources()->detach($brand);
                }
            }
            if (isset($source_all['brandsToActive'])) {
                foreach ($source_all['brandsToActive'] as $key => $brandId) {
                    $brand = Brand::find($brandId);
                    $brand->sources()->syncWithoutDetaching([
                        $source->id => [
                            'account_id' => $brand->account_id,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]
                    ]);
                }
            }
            if (isset($request['principalImage'])) {
                $image = Image::find($request['principalImage']);
                $source->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($source_all['newPrincipalImage'])) {
                $images[]["image"] = $request['newPrincipalImage'];
                $imageData = [
                    'title' => $source->title,
                    'type' => 'source',
                    'image_type_id' => 4,
                    'images' => $images
                ];
                ImageController::store(new Request([$imageData]), $source);
            }
            $source = Source::with('images', 'brands')->find($source->id);
            return $source;
        });

        return response()->json([
            'statut' => 1,
            'data' => $sources,
        ]);
    }


    public function destroy($id)
    {
        $Source = Source::find($id);
        $Source->delete();
        return response()->json([
            'statut' => 1,
            'data' => $Source,
        ]);
    }
}
