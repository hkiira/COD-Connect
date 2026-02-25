<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\City;
use App\Models\WarehouseNature;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class WarehouseNatureController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated=[];
        $model = 'App\\Models\\WarehouseNature';
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
                    RestoreController::renameRemovedRecords('warehouseNature', 'code', $value);
                    $account_id=getAccountUser()->account_id;
                    $codeModel = WarehouseNature::where(['code'=>$value,'account_id'=>$account_id])->first();
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
                    RestoreController::renameRemovedRecords('warehouseNature', 'title', $value);
                    $account_id=getAccountUser()->account_id;
                    $titleModel = WarehouseNature::where(['title'=>$value,'account_id'=>$account_id])->first();
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
        $warehouseNatures = collect($request->all())->map(function ($warehouseNature) {
            $warehouseNature['account_id']=getAccountUser()->account_id;
            $warehouseNature_only=collect($warehouseNature)->only('title','statut','code','account_id');
            $warehouseNature = WarehouseNature::create($warehouseNature_only->all());
            return $warehouseNature;
        });

        return response()->json([
            'statut' => 1,
            'data' =>  $warehouseNatures,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $warehouseNature = WarehouseNature::find($id);
        if(!$warehouseNature)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' =>$warehouseNature
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:warehouse_natures,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('warehouseNature', 'title', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $account_id=getAccountUser()->account_id;
                    $titleModel = WarehouseNature::where(['title'=>$value,'account_id'=>$account_id])->first(); // Find model by title
                    $idModel = WarehouseNature::where(['id'=>$id,'account_id'=>$account_id])->first(); // Find model by ID
                    
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
                    RestoreController::renameRemovedRecords('warehouseNature', 'code', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.code'], '', $attribute);
                    
                    // Get the ID and code from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $codeModel = WarehouseNature::where(['code'=>$value,'account_id'=>$account_id])->first(); // Find model by title
                    $idModel = WarehouseNature::where(['id'=>$id,'account_id'=>$account_id])->first(); // Find model by ID
                    
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
        $warehouseNatures = collect($request->all())->map(function ($warehouseNature){
            $warehouseNature_all=collect($warehouseNature)->all();
            $warehouseNature = WarehouseNature::find($warehouseNature_all['id']);
            $warehouseNature->update($warehouseNature_all);
            return $warehouseNature;
        });

        return response()->json([
            'statut' => 1,
            'data' => $warehouseNatures,
        ]);
    }

    public function destroy($id)
    {
        $WarehouseNature = WarehouseNature::find($id);
        $WarehouseNature->delete();
        return response()->json([
            'statut' => 1,
            'data' => $WarehouseNature,
        ]);
    }
}
