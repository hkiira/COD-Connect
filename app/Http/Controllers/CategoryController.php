<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Category;
use App\Models\Source;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\BrandSourceController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\OfferController;

class CategoryController extends Controller
{
    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated = [];
        $model = 'App\\Models\\Category';
        $request['inAccount'] = ['account_user_id', getAccountUser()->id];
        //permet de récupérer la liste des regions inactive filtrés

        if (isset($request['products']) && array_filter($request['products'], function ($value) {
            return $value !== null;
        })) {
            $associated[] = [
                'model' => 'App\\Models\\Product',
                'title' => 'products',
                'search' => true,
                'column' => 'title',
                'foreignKey' => 'product_id',
                'pivot' => ['table' => 'categories', 'column' => 'title', 'key' => 'id'],
                'select' => array_filter($request['products'], function ($value) {
                    return $value !== null;
                }),
            ];
        } else {
            $associated[] = [
                'model' => 'App\\Models\\Product',
                'title' => 'products',
                'search' => true,
            ];
        }
        $associated[] = [
            'model' => 'App\\Models\\Images',
            'title' => 'images',
            'search' => true,
        ];
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'title'], true, $associated);
        return $datas;
    }

    public function create(Request $request)
    {
        //transformer les données sous des array
        $request = collect($request->query())->toArray();
        $categories = [];
        if (isset($request['products']['inactive'])) {
            $model = 'App\\Models\\Product';
            $request['products']['inactive']['inAccount'] = ['account_user_id', getAccountUser()->id];
            //permet de récupérer la liste des regions inactive filtrés
            $categories['products']['inactive'] = FilterController::searchs(new Request($request['products']['inactive']), $model, ['id', 'title'], true, [['model' => 'App\\Models\\Category', 'title' => 'categories', 'search' => false], ['model' => 'App\\Models\\Images', 'title' => 'images', 'search' => true]]);
        }

        return response()->json([
            'statut' => 1,
            'data' => $categories,
        ]);
    }

    public function store(Request $requests)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($requests) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('category', 'title', $value);
                    $account_id = getAccountUser()->account_id;

                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $types_attribute_id = $requests->input("{$index}.types_attribute_id");
                    $account_users = 'App\\Models\\AccountUser'::where('account_id', $account_id)->get()->pluck('id')->toArray();
                    $titleModel = Category::where(['title' => $value])->whereIn('account_user_id', $account_users)->first();
                    if ($titleModel) {
                        $fail("exist");
                    }
                },
            ],
            '*.category_id' => 'exists:categories,id',
            '*.image' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $categories = collect($requests->except('_method'))->map(function ($request) {
            $request["account_user_id"] = getAccountUser()->id;
            $categorie_all = collect($request)->all();
            $categorie_only = collect($request)->only('title', 'statut', 'account_user_id', 'category_id');
            $categorie = Category::create($categorie_only->all());
            if (isset($request['image'])) {
                $imageData = [
                    'title' => $categorie->title,
                    'type' => 'category',
                    'image' => $request['image']
                ];
                $categorie_image = ImageController::store(new Request([$imageData]), $categorie);
            }
            if (isset($request['products'])) {
                foreach ($request['products'] as $key => $productId) {
                    $product = Product::find($productId);
                    $product->categories()->attach($categorie, ['account_id' => $categorie->account_id]);
                    $product->save();
                }
            }
            $categorie = Category::with('images', 'products', 'categories')->find($categorie->id);
            return $categorie;
        });

        return response()->json([
            'statut' => 1,
            'data' =>  $categories,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        $categorie = Category::find($id);
        if (!$categorie)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        if (isset($request['categoryInfo'])) {
            $info = collect($categorie->only(['title', 'statut']))->toArray();
            $data["categoryInfo"]['data'] = $info;
        }

        if (isset($request['sources']['active'])) {
            $model = 'App\\Models\\Source';
            $request['sources']['active']['whereIn'][0] = ['table' => 'categories', 'column' => 'category_id', 'value' => $categorie->id];
            $data['sources']['active'] = FilterController::searchs(new Request($request['sources']['active']), $model, ['id', 'title'], true, [['model' => 'App\\Models\\Category', 'title' => 'categories', 'search' => false], ['model' => 'App\\Models\\Images', 'title' => 'images', 'search' => true]]);
        }

        if (isset($request['sources']['inactive'])) {
            $model = 'App\\Models\\Source';
            $request['sources']['inactive']['whereNotIn'][0] = ['table' => 'categories', 'column' => 'category_id', 'value' => $categorie->id];
            $data['sources']['inactive'] = FilterController::searchs(new Request($request['sources']['inactive']), $model, ['id', 'title'], true, [['model' => 'App\\Models\\Category', 'title' => 'categories', 'search' => false], ['model' => 'App\\Models\\Images', 'title' => 'images', 'search' => true]]);
        }

        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }

    public function update(Request $requests, $id)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'required|exists:categories,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($requests) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('category', 'title', $value);

                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $requests->input("{$index}.id"); // Get ID from request
                    $account_id = getAccountUser()->account_id;
                    $account_users = 'App\\Models\\AccountUser'::where('account_id', $account_id)->get()->pluck('id')->toArray();
                    $titleModel = Category::where('title', $value)->whereIn('account_user_id', $account_users)->first();
                    $idModel = Category::where('id', $id)->whereIn('account_user_id', $account_users)->first(); // Find model by ID

                    // Check if a country with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.image' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.sourcesToActive.*' => 'exists:sources,id',
            '*.sourcesToInactive.*' => 'exists:sources,id',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $categories = collect($requests->except('_method'))->map(function ($request) {
            $categorie_all = collect($request)->all();
            $categorie = Category::find($categorie_all['id']);
            $categorie->update($categorie_all);
            if (isset($categorie_all['sourcesToActive'])) {
                foreach ($categorie_all['sourcesToActive'] as $key => $sourceId) {
                    $source = Source::find($sourceId);
                    $source->categories()->attach($categorie, ['account_id' => $categorie->account_id]);
                    $source->save();
                }
            }
            if (isset($categorie_all['sourcesToInactive'])) {
                foreach ($categorie_all['sourcesToInactive'] as $key => $sourceId) {
                    $source = Source::find($sourceId);
                    $source->categories()->detach($categorie);
                    $source->save();
                }
            }
            if (isset($categorie_all['image'])) {
                $imageData = [
                    'title' => $categorie->title,
                    'type' => 'category',
                    'image' => $categorie_all['image']
                ];
                $categorie_image = ImageController::store(new Request([$imageData]), $categorie);
            }
            $categorie = Category::with('images', 'sources')->find($categorie->id);
            return $categorie;
        });

        return response()->json([
            'statut' => 1,
            'data' => $categories,
        ]);
    }



    public function destroy($id)
    {
        $Category = Category::find($id);
        $Category->delete();
        return response()->json([
            'statut' => 1,
            'data' => $Category,
        ]);
    }
}
