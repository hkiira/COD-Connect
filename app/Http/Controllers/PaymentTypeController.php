<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PaymentType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PaymentTypeController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated=[];
        $model = 'App\\Models\\PaymentType';
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
                    RestoreController::renameRemovedRecords('PaymentType', 'title', $value);
                    $titleModel = PaymentType::where('title',$value)->first();
                    if ($titleModel) {
                        $fail("exist"); 
                    }
                },
            ],
            '*.description' => 'max:255',
            '*.statut' => 'required',
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $paymentTypes = collect($request->all())->map(function ($paymentType) {
            $paymentType['code']=DefaultCodeController::getCode('PaymentType');
            $paymentType_only=collect($paymentType)->only('code','title','statut');
            $paymentType = PaymentType::create($paymentType_only->all());
            return $paymentType;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $paymentTypes,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $paymentType = PaymentType::find($id);
        if(!$paymentType)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' =>$paymentType
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:payment_types,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('PaymentType', 'title', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $titleModel = PaymentType::where('title',$value)->first();
                    $idModel = PaymentType::where('id', $id)->first(); // Find model by ID
                    
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
        $paymentTypes = collect($request->all())->map(function ($paymentType){
            $paymentType_all=collect($paymentType)->all();
            $paymentType = PaymentType::find($paymentType_all['id']);
            $paymentType->update($paymentType_all);
            return $paymentType;
            
        });

        return response()->json([
            'statut' => 1,
            'data' => $paymentTypes,
        ]);
    }

    public function destroy($id)
    {
        $paymentType = PaymentType::find($id);
        $paymentType->delete();
        return response()->json([
            'statut' => 1,
            'data' => $paymentType,
        ]);
    }
}
