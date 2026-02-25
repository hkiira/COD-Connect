<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\Taxonomy;
use App\Models\AccountUser;
use App\Models\Image;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class TaxonomyController extends Controller
{

    public static function formatTaxonomy($taxonomy, $taxonomies = [])
    {
        $formattedTaxonomies = $taxonomy->toArray();
        $formattedTaxonomies['child_taxonomies'] = [];

        if ($taxonomy->childTaxonomies) {
            foreach ($taxonomy->childTaxonomies as $child) {
                $childTaxonomy = Taxonomy::with('images')->find($child->id);
                if (in_array($childTaxonomy->id, $taxonomies))
                    $childTaxonomy->checked = true;
                $formattedTaxonomies['child_taxonomies'][] = TaxonomyController::formatTaxonomy($childTaxonomy);
            }
        }

        return $formattedTaxonomies;
    }
    public static function index(Request $request)
    {
        $model = 'App\\Models\\Taxonomy';
        $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
        $categories = $model::whereNull('taxonomy_id')->with(['images', 'childTaxonomies'])->where('type_taxonomy_id', 1)->whereIn('account_user_id', $accountUsers)->get();
        $formattedCategories = [];
        foreach ($categories as $category) {
            $formattedCategories[] = TaxonomyController::formatTaxonomy($category);
        }
        $datas =  HelperFunctions::getPagination(collect($formattedCategories), $request['pagination']['per_page'], $request['pagination']['current_page']);
        return $datas;
    }
    public function create(Request $request) {}

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($taxonomy, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('Taxonomy', 'title', $value);
                    $account_id = getAccountUser()->account_id;

                    $index = str_replace(['*', '.title'], '', $taxonomy);
                    // Get the ID and title from the request
                    $type_taxonomy_id = $request->input("{$index}.type_taxonomy_id");
                    $account_users = 'App\\Models\\AccountUser'::where('account_id', $account_id)->get()->pluck('id')->toArray();
                    $titleModel = Taxonomy::where(['title' => $value, 'type_taxonomy_id' => $type_taxonomy_id])->whereIn('account_user_id', $account_users)->first();
                    if ($titleModel) {
                        $fail("exist");
                    }
                },
            ],
            '*.type_taxonomy_id' => 'required|exists:type_taxonomies,id',
            '*.taxonomy_id' => 'exists:taxonomies,id',
            '*.statut' => 'required',
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.principalImage' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage == null) {
                        $fail("not exist");
                    } elseif ($principalImage->account_id !== getAccountUser()->account_id) {
                        $fail("not exist");
                    }
                },
            ],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $taxonomys = collect($request->all())->map(function ($taxonomyData) {
            $taxonomyData["account_user_id"] = getAccountUser()->id;
            $taxonomyData['code'] = DefaultCodeController::getAccountCode('Taxonomy', getAccountUser()->account_id);
            $taxonomy_only = collect($taxonomyData)->only('code', 'title', 'description', 'statut', 'type_taxonomy_id', 'taxonomy_id', 'account_user_id');
            $taxonomy = Taxonomy::create($taxonomy_only->all());
            if (isset($taxonomyData['principalImage'])) {
                $image = Image::find($taxonomyData['principalImage']);
                $taxonomy->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($taxonomyData['newPrincipalImage'])) {
                $images[]["image"] = $taxonomyData['newPrincipalImage'];
                $imageData = [
                    'title' => $taxonomy->title,
                    'type' => 'taxonomy',
                    'image_type_id' => 13,
                    'images' => $images
                ];
                ImageController::store(new Request([$imageData]), $taxonomy);
            }
            return $taxonomy;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $taxonomys,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $taxonomy = Taxonomy::find($id);
        if (!$taxonomy)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' => $taxonomy
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->except('_method'), [
            '*.id' => 'required|exists:taxonomies,id',
            '*.type_taxonomy_id' => 'required|exists:type_taxonomies,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('Taxonomy', 'title', $value);

                    // Extract index from Taxonomy name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $account_id = getAccountUser()->account_id;
                    $account_users = 'App\\Models\\AccountUser'::where('account_id', $account_id)->get()->pluck('id')->toArray();
                    $titleModel = Taxonomy::where('title', $value)->whereIn('account_user_id', $account_users)->first();
                    $idModel = Taxonomy::where('id', $id)->whereIn('account_user_id', $account_users)->first(); // Find model by ID

                    // Check if a country with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.taxonomy_id' => 'exists:taxonomies,id',
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.principalImage' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage == null) {
                        $fail("not exist");
                    } elseif ($principalImage->account_id !== getAccountUser()->account_id) {
                        $fail("not exist");
                    }
                },
            ],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $taxonomys = collect($request->except('_method'))->map(function ($taxonomyData) {
            $taxonomy_all = collect($taxonomyData)->all();
            $taxonomy = Taxonomy::find($taxonomy_all['id']);
            $taxonomy->update($taxonomy_all);
            if (isset($taxonomyData['principalImage'])) {
                $image = Image::find($taxonomyData['principalImage']);
                $taxonomy->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($taxonomyData['newPrincipalImage'])) {
                $images[]["image"] = $taxonomyData['newPrincipalImage'];
                $imageData = [
                    'title' => $taxonomy->title,
                    'type' => 'taxonomy',
                    'image_type_id' => 13,
                    'images' => $images
                ];
                ImageController::store(new Request([$imageData]), $taxonomy);
            }
            return $taxonomy;
        });

        return response()->json([
            'statut' => 1,
            'data' => $taxonomys,
        ]);
    }

    public function destroy($id)
    {
        $taxonomy = Taxonomy::find($id);
        $taxonomy->delete();
        return response()->json([
            'statut' => 1,
            'data' => $taxonomy,
        ]);
    }
}
