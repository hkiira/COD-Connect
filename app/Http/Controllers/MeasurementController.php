<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\Measurement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class MeasurementController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated=[];
        $model = 'App\\Models\\Measurement';
        $datas = FilterController::searchs(new Request($request),$model,['id','title'], true,$associated);
        return $datas;
    }
    public function create(Request $request)
    {
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            '*.code' => 'required|unique:measurements,code',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail){ // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('Measurement', 'title', $value);
                    $titleModel = Measurement::where('title',$value)->first();
                    if ($titleModel) {
                        $fail("exist"); 
                    }
                },
            ],
            '*.measurement_id' => 'exists:measurements,id',
            '*.statut' => 'required',
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $measurements = collect($request->all())->map(function ($measurement) {
            $measurement_only=collect($measurement)->only('code','title','statut','measurement_id');
            $measurement = Measurement::create($measurement_only->all());
            return $measurement;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $measurements,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $measurement = Measurement::find($id);
        if(!$measurement)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' =>$measurement
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:measurements,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('Measurement', 'title', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $titleModel = Measurement::where('title',$value)->first();
                    $idModel = Measurement::where('id', $id)->first(); // Find model by ID
                    
                    // Check if a country with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.measurement_id' => 'exists:measurements,id',
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $measurements = collect($request->all())->map(function ($measurement){
            $measurement_all=collect($measurement)->all();
            $measurement = Measurement::find($measurement_all['id']);
            $measurement->update($measurement_all);
            return $measurement;
            
        });

        return response()->json([
            'statut' => 1,
            'data' => $measurements,
        ]);
    }

    public function destroy($id)
    {
        $measurement = Measurement::find($id);
        $measurement->delete();
        return response()->json([
            'statut' => 1,
            'data' => $measurement,
        ]);
    }
}
