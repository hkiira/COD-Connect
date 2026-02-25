<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\CustomerType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CustomerTypeController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated=[];
        $model = 'App\\Models\\CustomerType';
        $request['inAccount']=['account_user_id',getAccountUser()->id];
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
                    RestoreController::renameRemovedRecords('CustomerType', 'title', $value);
                    $account_id=getAccountUser()->account_id;
                    $account_users='App\\Models\\AccountUser'::where('account_id',$account_id)->get()->pluck('id')->toArray();
                    $titleModel = CustomerType::where('title',$value)->whereIn('account_user_id',$account_users)->first();
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
        $customerTypes = collect($request->all())->map(function ($customerType) {
            $customerType["account_user_id"]=getAccountUser()->id;
            $customerType['code']=DefaultCodeController::getAccountCode('CustomerType',getAccountUser()->account_id);
            $customerType_only=collect($customerType)->only('code','title','statut','description','account_user_id');
            $customerType = CustomerType::create($customerType_only->all());
            return $customerType;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $customerTypes,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $customerType = CustomerType::find($id);
        if(!$customerType)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' =>$customerType
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
                    RestoreController::renameRemovedRecords('CustomerType', 'title', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $account_id=getAccountUser()->account_id;
                    $account_users='App\\Models\\AccountUser'::where('account_id',$account_id)->get()->pluck('id')->toArray();
                    $titleModel = CustomerType::where('title',$value)->whereIn('account_user_id',$account_users)->first();
                    $idModel = CustomerType::where('id', $id)->whereIn('account_user_id',$account_users)->first(); // Find model by ID
                    
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
        $customerTypes = collect($request->all())->map(function ($customerType){
            $customerType_all=collect($customerType)->all();
            $customerType = CustomerType::find($customerType_all['id']);
            $customerType->update($customerType_all);
            return $customerType;
            
        });

        return response()->json([
            'statut' => 1,
            'data' => $customerTypes,
        ]);
    }

    public function destroy($id)
    {
        $customerType = CustomerType::find($id);
        $customerType->delete();
        return response()->json([
            'statut' => 1,
            'data' => $customerType,
        ]);
    }
}
