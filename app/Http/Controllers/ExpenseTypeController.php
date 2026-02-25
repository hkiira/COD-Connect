<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\City;
use App\Models\ExpenseType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ExpenseTypeController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated = [];
        $model = 'App\\Models\\ExpenseType';
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'title'], true, $associated);
        return $datas;
    }
    public function create(Request $request)
    {
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            '*.code' => [ // Validate code field
                'required', // code is required
                'max:255', // code should not exceed 255 characters
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('expenseType', 'code', $value);
                    $codeModel = ExpenseType::where('code', $value)->first();
                    if ($codeModel) {
                        $fail("exist");
                    }
                },
            ],
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('expenseType', 'title', $value);
                    $titleModel = ExpenseType::where('title', $value)->first();
                    if ($titleModel) {
                        $fail("exist");
                    }
                },
            ],
            '*.statut' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $expenseTypes = collect($request->all())->map(function ($expenseType) {
            $expenseType['account_user_id'] = getAccountUser()->id;
            $expenseType_only = collect($expenseType)->only('title', 'statut', 'code', 'account_user_id');
            $expenseType = ExpenseType::create($expenseType_only->all());
            return $expenseType;
        });

        return response()->json([
            'statut' => 1,
            'data' =>  $expenseTypes,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $expenseType = ExpenseType::find($id);
        if (!$expenseType)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' => $expenseType
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:expense_types,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('expenseType', 'title', $value);

                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);

                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $titleModel = ExpenseType::where('title', $value)->first(); // Find model by title
                    $idModel = ExpenseType::where('id', $id)->first(); // Find model by ID

                    // Check if a country with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.code' => [ // Validate code field
                'required', // code is required
                'max:255', // code should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('expenseType', 'code', $value);

                    // Extract index from attribute name
                    $index = str_replace(['*', '.code'], '', $attribute);

                    // Get the ID and code from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $codeModel = ExpenseType::where('code', $value)->first(); // Find model by code
                    $idModel = ExpenseType::where('id', $id)->first(); // Find model by ID

                    // Check if a country with the same code exists but with a different ID
                    if ($codeModel && $idModel && $codeModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
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
        $expenseTypes = collect($request->all())->map(function ($expenseType) {
            $expenseType_all = collect($expenseType)->all();
            $expenseType = ExpenseType::find($expenseType_all['id']);
            $expenseType->update($expenseType_all);
            return $expenseType;
        });

        return response()->json([
            'statut' => 1,
            'data' => $expenseTypes,
        ]);
    }

    public function destroy($id)
    {
        $ExpenseType = ExpenseType::find($id);
        $ExpenseType->delete();
        return response()->json([
            'statut' => 1,
            'data' => $ExpenseType,
        ]);
    }
}
