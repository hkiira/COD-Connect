<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PermissionType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PermissionTypeController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated=[];
        $model = 'App\\Models\\PermissionType';
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
                    RestoreController::renameRemovedRecords('PermissionType', 'title', $value);
                    $titleModel = PermissionType::where('title',$value)->first();
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
        $PermissionTypes = collect($request->all())->map(function ($PermissionType) {
            $PermissionType_only=collect($PermissionType)->only('title','statut');
            $PermissionType = PermissionType::create($PermissionType_only->all());
            return $PermissionType;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $PermissionTypes,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $PermissionType = PermissionType::find($id);
        if(!$PermissionType)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' =>$PermissionType
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
                    RestoreController::renameRemovedRecords('PermissionType', 'title', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $titleModel = PermissionType::where('title',$value)->first();
                    $idModel = PermissionType::where('id', $id)->first(); // Find model by ID
                    
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
        $PermissionTypes = collect($request->all())->map(function ($PermissionType){
            $PermissionType_all=collect($PermissionType)->all();
            $PermissionType = PermissionType::find($PermissionType_all['id']);
            $PermissionType->update($PermissionType_all);
            return $PermissionType;
            
        });

        return response()->json([
            'statut' => 1,
            'data' => $PermissionTypes,
        ]);
    }

    public function destroy($id)
    {
        $PermissionType = PermissionType::find($id);
        $PermissionType->delete();
        return response()->json([
            'statut' => 1,
            'data' => $PermissionType,
        ]);
    }
}
