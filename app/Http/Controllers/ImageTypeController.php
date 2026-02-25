<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ImageType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ImageTypeController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated=[];
        $model = 'App\\Models\\ImageType';
        $datas = FilterController::searchs(new Request($request),$model,['id','title'], true,$associated);
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
                function ($attribute, $value, $fail){ // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('ImageType', 'title', $value);
                    $titleModel = ImageType::where('title',$value)->first();
                    if ($titleModel) {
                        $fail("exist"); 
                    }
                },
            ],
            '*.folder' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail){ // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('ImageType', 'folder', $value);
                    $titleModel = ImageType::where('folder',$value)->first();
                    if ($titleModel) {
                        $fail("exist"); 
                    }
                },
            ],

            '*.statut' => 'required',
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $imageTypes = collect($request->all())->map(function ($imageType) {
            $imageType_only=collect($imageType)->only('title','folder','statut');
            $imageType = ImageType::create($imageType_only->all());
            return $imageType;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $imageTypes,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $imageType = ImageType::find($id);
        if(!$imageType)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' =>$imageType
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:image_types,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('ImageType', 'title', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $titleModel = ImageType::where('title',$value)->first();
                    $idModel = ImageType::where('id', $id)->first(); // Find model by ID
                    
                    // Check if a country with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.folder' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('ImageType', 'folder', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.folder'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $titleModel = ImageType::where('folder',$value)->first();
                    $idModel = ImageType::where('id', $id)->first(); // Find model by ID
                    
                    // Check if a country with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $imageTypes = collect($request->all())->map(function ($imageType){
            $imageType_all=collect($imageType)->all();
            $imageType = ImageType::find($imageType_all['id']);
            $imageType->update($imageType_all);
            return $imageType;
            
        });

        return response()->json([
            'statut' => 1,
            'data' => $imageTypes,
        ]);
    }

    public function destroy($id)
    {
        $imageType = ImageType::find($id);
        $imageType->delete();
        return response()->json([
            'statut' => 1,
            'data' => $imageType,
        ]);
    }
}
