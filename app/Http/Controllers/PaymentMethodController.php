<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PaymentMethodController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated=[];
        $model = 'App\\Models\\PaymentMethod';
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
                    RestoreController::renameRemovedRecords('PaymentMethod', 'title', $value);
                    $titleModel = PaymentMethod::where('title',$value)->first();
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
        $paymentMethods = collect($request->all())->map(function ($paymentMethod) {
            $paymentMethod['code']=DefaultCodeController::getCode('PaymentMethod');
            $paymentMethod_only=collect($paymentMethod)->only('code','title','statut');
            $paymentMethod = PaymentMethod::create($paymentMethod_only->all());
            return $paymentMethod;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $paymentMethods,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $paymentMethod = PaymentMethod::find($id);
        if(!$paymentMethod)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' =>$paymentMethod
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:payment_methods,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('PaymentMethod', 'title', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $titleModel = PaymentMethod::where('title',$value)->first();
                    $idModel = PaymentMethod::where('id', $id)->first(); // Find model by ID
                    
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
        $paymentMethods = collect($request->all())->map(function ($paymentMethod){
            $paymentMethod_all=collect($paymentMethod)->all();
            $paymentMethod = PaymentMethod::find($paymentMethod_all['id']);
            $paymentMethod->update($paymentMethod_all);
            return $paymentMethod;
            
        });

        return response()->json([
            'statut' => 1,
            'data' => $paymentMethods,
        ]);
    }

    public function destroy($id)
    {
        $paymentMethod = PaymentMethod::find($id);
        $paymentMethod->delete();
        return response()->json([
            'statut' => 1,
            'data' => $paymentMethod,
        ]);
    }
}
