<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Image;
use App\Models\ImageType;
use App\Models\Brand;
use App\Models\Account;
use App\Models\Product;
use App\Models\Imageable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\HelperFunctions;

class ImageController extends Controller
{

    public static function index(Request $request, $local = 0, $paginate = true)
    {
        $filters = HelperFunctions::filterColumns($request->toArray(), ['types', 'price', 'imageable_id', 'brands', 'products']);
        $account = getAccountUser()->account_id;
        $all_images = Account::find($account)->hasImages;
        $images = Account::with(['hasImages' => function ($query) use ($filters) {
            if ($filters['filters']['types']) {
                $query->whereIn('type', $filters['filters']['types']);
            }
            $query->where('title', 'like', "%" . $filters['search'] . "%");
        }])
            ->find($account)->hasImages->map(function ($image) {
                return $image->only('id', 'type', 'title', 'photo', 'photo_dir');
            });

        $types = $all_images->unique('type')->values()->map(function ($type) {
            return $type->only('type');
        });
        $imageTypes = ImageType::all()->map(function ($imagetype) {
            return ['type' => $imagetype->folder];
        });
        $dataPagination = HelperFunctions::getPagination($images, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        if ($local == 1) {
            if ($paginate == false) {
                return $images->toArray();
            }
            return $dataPagination;
        };
        return response()->json([
            'statut' => 1,
            'types' => $imageTypes,
            'data' => $dataPagination
        ]);
    }

    public function create(Request $request)
    {
        //
    }

    public static function store(Request $requests, $model = null, $has_one = true)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.images' => 'required|array|min:1',
            '*.images.*.image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:204800',
            '*.images.*.as_principal' => 'nullable|boolean',
            '*.image_type_id' => 'required|exists:image_types,id',
            '*.title' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'Validation Error',
                $validator->errors()
            ]);
        };
        $images = collect($requests->except('_method'))->map(function ($request) use ($model, $has_one) {
            $imageType = ImageType::find($request['image_type_id']);
            $imageInfos = ['title' => $request['title'], 'folder' => $imageType->folder, 'type_id' => $imageType->id];
            return collect($request)->only('images')->map(function ($image) use ($model, $has_one, $imageInfos) {
                return collect($image)->map(function ($imageData) use ($model, $has_one, $imageInfos) {
                    $account = getAccountUser()->account_id;
                    $path = Storage::disk('public')->putFile('images/' . $imageInfos['folder'], $imageData['image']); //store the image
                    if (!$path || !Storage::disk('public')->exists($path)) {
                        Log::error('Image upload failed: file not written to storage', [
                            'account_id' => $account,
                            'folder' => $imageInfos['folder'] ?? null,
                            'image_type_id' => $imageInfos['type_id'] ?? null,
                        ]);
                        return null;
                    }
                    $image = Image::create([
                        'type' => $imageInfos['folder'],
                        'account_id' => $account,
                        'title' => $imageInfos['title'],
                        'image_type_id' => $imageInfos['type_id'],
                        'photo' => basename($path),
                        'photo_dir' => "/storage/" . dirname($path) . "/",
                    ]);
                    if ($model != null) {
                        if ($has_one) {
                            if (!empty($model->images)) {
                                foreach ($model->images as $key => $imagepivot) {
                                    $model->images()->detach($imagepivot['id']);
                                }
                            }
                            $model->images()->attach($image->id, ['created_at' => now(), 'updated_at' => now(), 'statut' => 2]);
                        } else {
                            if (isset($imageData['as_principal'])) {
                                foreach ($model->imageables as $key => $imagepivot) {
                                    $update = $imagepivot->find($imagepivot->id);
                                    $update->statut = 1;
                                    $update->save();
                                }
                                $model->images()->attach($image->id, ['created_at' => now(), 'updated_at' => now(), 'statut' => 2]);
                            } else {
                                $model->images()->attach($image->id, ['created_at' => now(), 'updated_at' => now(), 'statut' => 1]);
                            }
                        }
                    }
                    return $image;
                })->filter()->values();
            });
        });
        return $images;

        // $validator = Validator::make($requests->except('_method'), [
        //     '*.image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:204800',
        //     '*.as_principal' => 'boolean',
        //     '*.type' => 'required',
        //     '*.title' => 'required',
        // ]);
        // if($validator->fails()){
        //     return response()->json([
        //         'Validation Error', $validator->errors()
        //     ]);       
        // };
        // $images = collect($requests->except('_method'))->map(function($request)use($model,$has_one){
        //     $account = getAccountUser()->account_id;
        //     $data=collect($request)->all();
        //     $imageData=new Request($request);
        //     $path = Storage::disk('public')->putFile('images/'.$imageData->type, $imageData->image); //store the image
        //     $image = Image::create([
        //         'type' => $imageData->type,
        //         'account_id' => $account,
        //         'title'=> $imageData->title,
        //         'photo'=> basename($path),
        //         'photo_dir'=>"/storage/".dirname($path)."/",
        //     ]);
        //     if($model != null){
        //         if($has_one){
        //             if(!empty($model->images)){
        //                 foreach ($model->images as $key => $imagepivot) {
        //                     $model->images()->detach($imagepivot['id']);
        //                 }
        //             }
        //             $model->images()->attach($image->id , ['created_at' => now(), 'updated_at' => now(),'statut'=> 1 ]);
        //         }else{
        //             if($imageData->as_principal){
        //                 foreach ($model->imageables as $key => $imagepivot) {
        //                     $update=$imagepivot->find($imagepivot->id);
        //                     $update->statut=2;
        //                     $update->save();
        //                 }
        //                 $model->images()->attach($image->id , ['created_at' => now(), 'updated_at' => now(),'statut'=> 1 ]);
        //             }else{
        //                 $model->images()->attach($image->id , ['created_at' => now(), 'updated_at' => now(),'statut'=> 2 ]);
        //             }  
        //         }
        //     }
        //     return $image;
        // });
        // return $images;
    }

    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        //
    }


    public static function update(Request $request, $local = 0,  $table, array $images, $principal_image = 0)
    {
        if (!$local) {
            $validator = Validator::make($request->except('_method'), [
                // 'images' => 'required|image|mimes:jpeg,webp,png,jpg,gif,svg|max:20480',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'Validation Error',
                    $validator->errors()
                ]);
            };
        }
        $imageables = $principal_image == 1
            ? $table->imageables->whereIn('statut', [0, 1])
            : $table->imageables->whereIn('statut', [0, 2]);
        //the table must have  realtion name images
        foreach ($imageables as $imageable) {
            if (in_array($imageable->image_id, $images) == true) {
                if ($principal_image == 1) {
                    if ($imageable->statut != 1)
                        imageable::find($imageable->id)->update(['statut' => 1]);
                } else {
                    if ($imageable->statut != 2)
                        imageable::find($imageable->id)->update(['statut' => 2]);
                }
            } else {
                if ($imageable->statut != 0) {
                    imageable::find($imageable->id)->update(['statut' => 0]);
                }
            }
        }
        $imageables = Product::find(170)->imageables;
        // dd($table->imageables->toArray());
        foreach ($images as $image) {
            $exist = collect($imageables)->contains('image_id', $image);
            if ($exist == false) {
                if ($principal_image == 0) {
                    // dd($imageables->toArray());
                    // die();                    
                }

                $table->images()->attach($image, ['statut' => $principal_image == 0 ? 2 : 1]);
            }
        }
        if ($local == 1)
            return true;
        return response()->json([
            'statut' => 1,
            'data' => true,
        ]);
    }


    public function destroy(Request $request, $id)
    {
        $request = collect($request->query());
        $imagesDeleted = collect($request['images'])->map(function ($id) {
            $image = Image::with('imageables')->find($id);
            if ($image) {
                $delted = true;
                if ($delted) {
                    $imageablesDeleted = $image->imageables()->delete();
                    $imagedeleted = Image::find($id)->delete();
                    return $image;
                }
            }
        })->filter()->values();

        return response()->json([
            'statut' => 1,
            'data' => $imagesDeleted,
        ]);
    }
}
