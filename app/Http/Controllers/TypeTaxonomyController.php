<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\TypeTaxonomy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class TypeTaxonomyController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated=[];
        $model = 'App\\Models\\TypeTaxonomy';
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
                    RestoreController::renameRemovedRecords('typeTaxonomy', 'title', $value);
                    $titleModel = TypeTaxonomy::where('title',$value)->first();
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
        $typeTaxonomys = collect($request->all())->map(function ($typeTaxonomy) {
            $typeTaxonomy["account_user_id"]=getAccountUser()->id;
            $typeTaxonomy_only=collect($typeTaxonomy)->only('title','statut','description','account_user_id');
            $typeTaxonomy = TypeTaxonomy::create($typeTaxonomy_only->all());
            return $typeTaxonomy;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $typeTaxonomys,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $typeTaxonomy = TypeTaxonomy::find($id);
        if(!$typeTaxonomy)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' =>$typeTaxonomy
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:type_taxonomies,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('TypeTaxonomy', 'title', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $titleModel = TypeTaxonomy::where('title',$value)->first();
                    $idModel = TypeTaxonomy::where('id', $id)->first(); // Find model by ID
                    
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
        $typeTaxonomys = collect($request->all())->map(function ($typeTaxonomy){
            $typeTaxonomy_all=collect($typeTaxonomy)->all();
            $typeTaxonomy = TypeTaxonomy::find($typeTaxonomy_all['id']);
            $typeTaxonomy->update($typeTaxonomy_all);
            return $typeTaxonomy;
            
        });

        return response()->json([
            'statut' => 1,
            'data' => $typeTaxonomys,
        ]);
    }

    public function destroy($id)
    {
        $typeTaxonomy = TypeTaxonomy::find($id);
        $typeTaxonomy->delete();
        return response()->json([
            'statut' => 1,
            'data' => $typeTaxonomy,
        ]);
    }
}
