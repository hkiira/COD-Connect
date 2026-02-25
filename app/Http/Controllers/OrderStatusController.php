<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\OrderStatus;
use Illuminate\Support\Facades\Validator;

class OrderStatusController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated=[];
        $model = 'App\\Models\\OrderStatus';
        $datas = FilterController::searchs(new Request($request),$model,['id','title'], true,$associated);
        return $datas;
    }
    public function create(Request $request)
    {
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            '*.title' => [
                'required',
                'max:255',
                function ($attribute, $value, $fail){ 
                    RestoreController::renameRemovedRecords('OrderStatus', 'title', $value);
                    $titleModel = OrderStatus::where('title',$value)->first();
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
        $orderStatuses = collect($request->all())->map(function ($orderStatus) {
            $orderStatus_only=collect($orderStatus)->only('title','statut');
            $orderStatus = OrderStatus::create($orderStatus_only->all());
            return $orderStatus;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $orderStatuses,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $orderStatus = OrderStatus::find($id);
        if(!$orderStatus)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' =>$orderStatus
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:order_statuses,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('OrderStatus', 'title', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $titleModel = OrderStatus::where('title',$value)->first();
                    $idModel = OrderStatus::where('id', $id)->first(); // Find model by ID
                    
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
        $orderStatuss = collect($request->all())->map(function ($orderStatus){
            $orderStatus_all=collect($orderStatus)->all();
            $orderStatus = OrderStatus::find($orderStatus_all['id']);
            $orderStatus->update($orderStatus_all);
            return $orderStatus;
            
        });

        return response()->json([
            'statut' => 1,
            'data' => $orderStatuss,
        ]);
    }

    public function destroy($id)
    {
        $orderStatus = OrderStatus::find($id);
        $orderStatus->delete();
        return response()->json([
            'statut' => 1,
            'data' => $orderStatus,
        ]);
    }
}
