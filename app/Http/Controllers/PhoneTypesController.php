<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\PhoneTypes;
use App\Models\Image;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PhoneTypesController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated[]=[
            'model'=>'App\\Models\\Image',
            'title'=>'images',
            'search'=>false
        ];
        $model = 'App\\Models\\PhoneTypes';
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
                    RestoreController::renameRemovedRecords('PhoneTypes', 'title', $value);
                    $account_id=getAccountUser()->account_id;
                    $titleModel = PhoneTypes::where('title',$value)->where('account_id',$account_id)->first();
                    if ($titleModel) {
                        $fail("exist"); 
                    }
                },
            ],
            '*.principalImage' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail){ // Custom validation rule
                    // Call the function to rename removed records
                    $principalImage = Image::where('id', $value)->first();
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage==null) {
                        $fail("not exist"); 
                    }elseif($principalImage->account_id!==getAccountUser()->account_id){
                        $fail("not exist"); 
                    }
                },
            ],
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.description' => 'max:255',
            '*.statut' => 'required',
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $PhoneTypess = collect($request->all())->map(function ($PhoneTypes) {
            $data=$PhoneTypes;
            $PhoneTypes["account_id"]=getAccountUser()->account_id;
            $PhoneTypes['code']=DefaultCodeController::getAccountCode('PhoneTypes',$PhoneTypes["account_id"]);
            $PhoneTypes_only=collect($PhoneTypes)->only('code','title','statut','account_id');
            $PhoneTypes = PhoneTypes::create($PhoneTypes_only->all());
            
            if(isset($data['principalImage'])){
                $PhoneTypes->images()->detach($PhoneTypes->images->pluck('id')->toArray());
                $image=Image::find($data['principalImage']);
                $PhoneTypes->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            }elseif(isset($data['newPrincipalImage'])){
                $images[]["image"]=$data['newPrincipalImage'];
                $imageData=[
                        'title'=>$PhoneTypes->title,
                        'type'=>'phoneType',
                        'image_type_id'=>14,
                        'images'=>$images
                    ];
                ImageController::store( new Request([$imageData]),$PhoneTypes);
            }
            return $PhoneTypes;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $PhoneTypess,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $PhoneTypes = PhoneTypes::find($id);
        if(!$PhoneTypes)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' =>['typePhoneInfo'=>['statut'=>1,'data'=>$PhoneTypes]]
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->except('_method'), [
            '*.id' => 'required|exists:phone_types,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('PhoneTypes', 'title', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $account_id=getAccountUser()->account_id;
                    $titleModel = PhoneTypes::where('title',$value)->where('account_id',$account_id)->first();
                    $idModel = PhoneTypes::where('id', $id)->where('account_id',$account_id)->first(); // Find model by ID
                    
                    // Check if a country with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.principalImage' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail){ // Custom validation rule
                    // Call the function to rename removed records
                    $principalImage = Image::where('id', $value)->first();
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage==null) {
                        $fail("not exist"); 
                    }elseif($principalImage->account_id!==getAccountUser()->account_id){
                        $fail("not exist"); 
                    }
                },
            ],
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $PhoneTypess = collect($request->except('_method'))->map(function ($PhoneTypes){
            $PhoneTypes_all=collect($PhoneTypes)->all();
            $PhoneTypes = PhoneTypes::find($PhoneTypes_all['id']);
            
            $PhoneTypes->update($PhoneTypes_all);
            if(isset($PhoneTypes_all['principalImage'])){
                $PhoneTypes->images()->detach($PhoneTypes->images->pluck('id')->toArray());
                $image=Image::find($PhoneTypes_all['principalImage']);
                $PhoneTypes->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            }elseif(isset($PhoneTypes_all['newPrincipalImage'])){
                $images[]["image"]=$PhoneTypes_all['newPrincipalImage'];
                $imageData=[
                        'title'=>$PhoneTypes->title,
                        'type'=>'phoneType',
                        'image_type_id'=>14,
                        'images'=>$images
                    ];
                ImageController::store( new Request([$imageData]),$PhoneTypes);
            }
            return $PhoneTypes;
            
        });

        return response()->json([
            'statut' => 1,
            'data' => $PhoneTypess,
        ]);
    }

    public function destroy($id)
    {
        $PhoneTypes = PhoneTypes::find($id);
        $PhoneTypes->delete();
        return response()->json([
            'statut' => 1,
            'data' => $PhoneTypes,
        ]);
    }
}
