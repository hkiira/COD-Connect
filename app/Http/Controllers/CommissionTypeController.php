<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\CommissionType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CommissionTypeController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated=[];
        $model = 'App\\Models\\CommissionType';
        $datas = FilterController::searchs(new Request($request),$model,['id','title'], true,$associated);
        return $datas;
    }
    public function create(Request $request)
    {
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            '*.code' => 'required|max:255',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail){ // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('CommissionType', 'title', $value);
                    $titleModel = CommissionType::where('title',$value)->first();
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
        $commissionTypes = collect($request->all())->map(function ($commissionType) {
            $commissionType_only=collect($commissionType)->only('code','title','statut','description');
            $commissionType = CommissionType::create($commissionType_only->all());
            return $commissionType;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $commissionTypes,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $commissionType = CommissionType::find($id);
        if(!$commissionType)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' =>$commissionType
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:types_attributes,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('CommissionType', 'title', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $account_id=getAccountUser()->account_id;
                    $account_users='App\\Models\\AccountUser'::where('account_id',$account_id)->get()->pluck('id')->toArray();
                    $titleModel = CommissionType::where('title',$value)->whereIn('account_user_id',$account_users)->first();
                    $idModel = CommissionType::where('id', $id)->whereIn('account_user_id',$account_users)->first(); // Find model by ID
                    
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
        $commissionTypes = collect($request->all())->map(function ($commissionType){
            $commissionType_all=collect($commissionType)->all();
            $commissionType = CommissionType::find($commissionType_all['id']);
            $commissionType->update($commissionType_all);
            return $commissionType;
            
        });

        return response()->json([
            'statut' => 1,
            'data' => $commissionTypes,
        ]);
    }

    public function destroy($id)
    {
        $commissionType = CommissionType::find($id);
        $commissionType->delete();
        return response()->json([
            'statut' => 1,
            'data' => $commissionType,
        ]);
    }
}
