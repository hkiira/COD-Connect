<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\MouvementType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class MouvementTypeController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated=[];
        $model = 'App\\Models\\MouvementType';
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
                    RestoreController::renameRemovedRecords('MouvementType', 'title', $value);
                    $titleModel = MouvementType::where('title',$value)->first();
                    if ($titleModel) {
                        $fail("exist"); 
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
        $mouvementTypes = collect($request->all())->map(function ($mouvementType) {
            $mouvementType_only=collect($mouvementType)->only('title');
            $mouvementType = MouvementType::create($mouvementType_only->all());
            return $mouvementType;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $mouvementTypes,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $mouvementType = MouvementType::find($id);
        if(!$mouvementType)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' =>$mouvementType
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
                    RestoreController::renameRemovedRecords('MouvementType', 'title', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $account_id=getAccountUser()->account_id;
                    $account_users='App\\Models\\AccountUser'::where('account_id',$account_id)->get()->pluck('id')->toArray();
                    $titleModel = MouvementType::where('title',$value)->whereIn('account_user_id',$account_users)->first();
                    $idModel = MouvementType::where('id', $id)->whereIn('account_user_id',$account_users)->first(); // Find model by ID
                    
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
        $mouvementTypes = collect($request->all())->map(function ($mouvementType){
            $mouvementType_all=collect($mouvementType)->all();
            $mouvementType = MouvementType::find($mouvementType_all['id']);
            $mouvementType->update($mouvementType_all);
            return $mouvementType;
            
        });

        return response()->json([
            'statut' => 1,
            'data' => $mouvementTypes,
        ]);
    }

    public function destroy($id)
    {
        $mouvementType = MouvementType::find($id);
        $mouvementType->delete();
        return response()->json([
            'statut' => 1,
            'data' => $mouvementType,
        ]);
    }
}
