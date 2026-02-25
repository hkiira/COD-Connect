<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\City;
use App\Models\WarehouseType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class WarehouseTypeController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated=[];
        $model = 'App\\Models\\WarehouseType';
        $datas = FilterController::searchs(new Request($request),$model,['id','title'], true,$associated);
        return $datas;
    }
    public function create(Request $request)
    {
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            '*.code' => [ // Validate code field
                'required', // code is required
                'max:255', // code should not exceed 255 characters
                function ($attribute, $value, $fail){ // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('warehouseType', 'code', $value);
                    $codeModel = WarehouseType::where('code', $value)->first();
                    if ($codeModel) {
                        $fail("exist"); 
                    }
                },
            ],
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail){ // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('warehouseType', 'title', $value);
                    $titleModel = WarehouseType::where('title', $value)->first();
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
        $warehouseTypes = collect($request->all())->map(function ($warehouseType) {
            $warehouseType_only=collect($warehouseType)->only('title','statut','code');
            $warehouseType = WarehouseType::create($warehouseType_only->all());
            return $warehouseType;
        });

        return response()->json([
            'statut' => 1,
            'data' =>  $warehouseTypes,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $warehouseType = WarehouseType::find($id);
        if(!$warehouseType)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' =>$warehouseType
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:warehouse_types,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('warehouseType', 'title', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $titleModel = WarehouseType::where('title', $value)->first(); // Find model by title
                    $idModel = WarehouseType::where('id', $id)->first(); // Find model by ID
                    
                    // Check if a country with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.code' => [ // Validate code field
                'required', // code is required
                'max:255', // code should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('warehouseType', 'code', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.code'], '', $attribute);
                    
                    // Get the ID and code from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $codeModel = WarehouseType::where('code', $value)->first(); // Find model by code
                    $idModel = WarehouseType::where('id', $id)->first(); // Find model by ID
                    
                    // Check if a country with the same code exists but with a different ID
                    if ($codeModel && $idModel && $codeModel->id !== $idModel->id) {
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
        $warehouseTypes = collect($request->all())->map(function ($warehouseType){
            $warehouseType_all=collect($warehouseType)->all();
            $warehouseType = WarehouseType::find($warehouseType_all['id']);
            $warehouseType->update($warehouseType_all);
            return $warehouseType;
            
        });

        return response()->json([
            'statut' => 1,
            'data' => $warehouseTypes,
        ]);
    }

    public function destroy($id)
    {
        $WarehouseType = WarehouseType::find($id);
        $WarehouseType->delete();
        return response()->json([
            'statut' => 1,
            'data' => $WarehouseType,
        ]);
    }
}
