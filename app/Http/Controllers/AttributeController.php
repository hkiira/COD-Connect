<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\Attribute;
use App\Models\Image;
use App\Models\TypeAttribute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AttributeController extends Controller
{

    public function import(){

    }
    
    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated[]=[
            'model'=>'App\\Models\\TypeAttribute',
            'title'=>'typeAttribute',
            'search'=>true,
        ];
        $associated[]=[
            'model'=>'App\\Models\\Image',
            'title'=>'images',
            'search'=>true,
        ];
        $model = 'App\\Models\\Attribute';
        $request['inAccount']=['account_user_id',getAccountUser()->id];
        $datas = FilterController::searchs(new Request($request),$model,['id','title'], true,$associated);
        return $datas;
    }
    public function create(Request $request)
    {
    }

    public static function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail)use($request){ // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('attribute', 'title', $value);
                    $account_id=getAccountUser()->account_id;
                    
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $types_attribute_id = $request->input("{$index}.types_attribute_id");
                    $account_users='App\\Models\\AccountUser'::where('account_id',$account_id)->get()->pluck('id')->toArray();
                    $titleModel = Attribute::where(['title'=>$value,'types_attribute_id'=>$types_attribute_id])->whereIn('account_user_id',$account_users)->first();
                    if ($titleModel) {
                        $fail("exist"); 
                    }
                },
            ],
            '*.types_attribute_id' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail)use($request){ // Custom validation rule
                    $account_id=getAccountUser()->account_id;
                    $account_users='App\\Models\\AccountUser'::where('account_id',$account_id)->get()->pluck('id')->toArray();
                    $hasType = TypeAttribute::where(['id'=>$value])->whereIn('account_user_id',$account_users)->first();
                    if (!$hasType) {
                        $fail("exist"); 
                    }
                },
            ],
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.principalImage' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail){ // Custom validation rule
                    // Call the function to rename removed records
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage==null) {
                        $fail("not exist"); 
                    }elseif($principalImage->account_id!==getAccountUser()->account_id){
                        $fail("not exist"); 
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
        $attributes = collect($request->all())->map(function ($attributeData) {
            $attributeData["account_user_id"]=getAccountUser()->id;
            $attributeData['code']=DefaultCodeController::getAccountCode('Attribute',getAccountUser()->account_id);
            $attribute_only=collect($attributeData)->only('code','title','statut','types_attribute_id','account_user_id');
            $attribute = Attribute::create($attribute_only->all());
            if(isset($request['principalImage'])){
                $image=Image::find($attributeData['principalImage']);
                $attribute->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            }elseif(isset($attributeData['newPrincipalImage'])){
                $images[]["image"]=$attributeData['newPrincipalImage'];
                $imageData=[
                        'title'=>$attribute->title,
                        'type'=>'attribute',
                        'image_type_id'=>9,
                        'images'=>$images
                    ];
                $attribute_image = ImageController::store( new Request([$imageData]),$attribute);
            }
            return $attribute;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $attributes,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $attribute = Attribute::with('typeAttribute')->find($id);
        if(!$attribute)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' =>['attributeInfo'=>['statut'=>1,'data'=>$attribute]]
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->except('_method'), [
            '*.id' => 'required|exists:attributes,id',
            '*.types_attribute_id' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail)use($request){ // Custom validation rule
                    $account_id=getAccountUser()->account_id;
                    $account_users='App\\Models\\AccountUser'::where('account_id',$account_id)->get()->pluck('id')->toArray();
                    $hasType = TypeAttribute::where(['id'=>$value])->whereIn('account_user_id',$account_users)->first();
                    if (!$hasType) {
                        $fail("exist"); 
                    }
                },
            ],
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('attribute', 'title', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $account_id=getAccountUser()->account_id;
                    $account_users='App\\Models\\AccountUser'::where('account_id',$account_id)->get()->pluck('id')->toArray();
                    $titleModel = Attribute::where('title',$value)->whereIn('account_user_id',$account_users)->first();
                    $idModel = Attribute::where('id', $id)->whereIn('account_user_id',$account_users)->first(); // Find model by ID
                    
                    // Check if a attribute with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.principalImage' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail){ // Custom validation rule
                    // Call the function to rename removed records
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage==null) {
                        $fail("not exist"); 
                    }elseif($principalImage->account_id!==getAccountUser()->account_id){
                        $fail("not exist"); 
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
        $attributes = collect($request->except('_method'))->map(function ($attributeData){
            $attribute_all=collect($attributeData)->all();
            $attribute = Attribute::find($attribute_all['id']);
            $attribute->update($attribute_all);
            if(isset($attributeData['principalImage'])){
                $image=Image::find($attributeData['principalImage']);
                $attribute->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            }elseif(isset($attributeData['newPrincipalImage'])){
                $images[]["image"]=$attributeData['newPrincipalImage'];
                $imageData=[
                        'title'=>$attribute->title,
                        'type'=>'attribute',
                        'image_type_id'=>9,
                        'images'=>$images
                    ];
                $attribute_image = ImageController::store( new Request([$imageData]),$attribute);
            }
            return $attribute;
            
        });

        return response()->json([
            'statut' => 1,
            'data' => $attributes,
        ]);
    }

    public function destroy($id)
    {
        $Attribute = Attribute::find($id);
        $Attribute->delete();
        return response()->json([
            'statut' => 1,
            'data' => $Attribute,
        ]);
    }
}
