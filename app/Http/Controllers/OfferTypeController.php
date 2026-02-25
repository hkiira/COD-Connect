<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\OfferType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class OfferTypeController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated=[];
        $model = 'App\\Models\\OfferType';
        $datas = FilterController::searchs(new Request($request),$model,['id','title'], true,$associated);
        return $datas;
    }
    public function create(Request $request)
    {
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            '*.code' => 'required|string',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail){ // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('OfferType', 'title', $value);
                    $titleModel = OfferType::where('title',$value)->first();
                    if ($titleModel) {
                        $fail("exist"); 
                    }
                },
            ],
            '*.statut' => 'int',
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $offerTypes = collect($request->all())->map(function ($offerType) {
            $offerType_only=collect($offerType)->only('code','title','description','statut');
            $offerType = OfferType::create($offerType_only->all());
            return $offerType;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $offerTypes,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $offerType = OfferType::find($id);
        if(!$offerType)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' =>['offerTypeInfo'=>['statut'=>1,'data'=>$offerType]]
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:offer_types,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('OfferType', 'title', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $titleModel = OfferType::where('title',$value)->first();
                    $idModel = OfferType::where('id', $id)->first(); // Find model by ID
                    
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
        $offerTypes = collect($request->all())->map(function ($offerType){
            $offerType_all=collect($offerType)->all();
            $offerType = OfferType::find($offerType_all['id']);
            $offerType->update($offerType_all);
            return $offerType;
        });

        return response()->json([
            'statut' => 1,
            'data' => $offerTypes,
        ]);
    }

    public function destroy($id)
    {
        $offerType = OfferType::find($id);
        $offerType->delete();
        return response()->json([
            'statut' => 1,
            'data' => $offerType,
        ]);
    }
}
