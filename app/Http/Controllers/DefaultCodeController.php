<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\DefaultCode;
use App\Models\AccountCode;
use App\Models\Account;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class DefaultCodeController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated = [];
        $model = 'App\\Models\\DefaultCode';
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'name'], true, $associated);
        return $datas;
    }
    public function create(Request $request) {}

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            '*.name' => 'required|unique:default_codes,name',
            '*.prefix' => 'required|unique:default_codes,prefix',
            '*.controller' => 'required|unique:default_codes,controller',
            '*.counter' => 'int',
            '*.statut' => 'int',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $defaultCodes = collect($request->all())->map(function ($defaultCode) {
            $defaultCode_only = collect($defaultCode)->only('name', 'prefix', 'controller');
            $defaultCode = DefaultCode::create($defaultCode_only->all());
            $accounts = Account::get();
            foreach ($accounts as $key => $account) {
                $account->defaultCodes()->syncWithoutDetaching([
                    $defaultCode->id => [
                        'prefixe' => $defaultCode->prefix,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            }
            return $defaultCode;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $defaultCodes,
        ]);
    }


    public function show($id)
    {
        //
    }
    public static function getCode($controller)
    {
        $defaultCode = DefaultCode::where('controller', $controller)->first();
        if ($defaultCode) {
            $defaultCode->update(['counter' => $defaultCode->counter + 1]);
            $random = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 2);
            return $defaultCode->prefix . $defaultCode->counter . "0" . $random;
        }
        return null;
    }
    public static function getAccountCode($controller, $accountId)
    {
        $defaultCode = DefaultCode::where('controller', $controller)->first();
        if ($defaultCode) {
            $accountCode = AccountCode::where(['account_id' => $accountId, 'default_code_id' => $defaultCode->id])->first();
            $random = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 2);
            if ($accountCode) {
                $accountCode->update(['counter' => ($accountCode->counter + 1)]);
                return $accountCode->prefixe . $accountCode->counter . $random;
            } else {
                $accountCode = AccountCode::create([
                    "account_id" => $accountId,
                    "default_code_id" => $defaultCode->id,
                    "prefixe" => $defaultCode->prefix,
                    "counter" => 1,
                ]);
                return $accountCode->prefixe . $accountCode->counter . $random;
            }
        }
        return null;
    }
    public function edit(Request $request, $id)
    {
        $defaultCode = DefaultCode::find($id);
        if (!$defaultCode)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' => $defaultCode
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:default_codes,id',
            '*.name' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('defaultCode', 'name', $value);

                    // Extract index from attribute name
                    $index = str_replace(['*', '.name'], '', $attribute);
                    // Get the ID and name from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $nameModel = DefaultCode::where('name', $value)->first();
                    $idModel = DefaultCode::where('id', $id)->first(); // Find model by ID

                    // Check if a country with the same title exists but with a different ID
                    if ($nameModel && $idModel && $nameModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.controller' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('defaultCode', 'controller', $value);

                    // Extract index from attribute controller
                    $index = str_replace(['*', '.controller'], '', $attribute);
                    // Get the ID and controller from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $controllerModel = DefaultCode::where('controller', $value)->first();
                    $idModel = DefaultCode::where('id', $id)->first(); // Find model by ID

                    // Check if a country with the same title exists but with a different ID
                    if ($controllerModel && $idModel && $controllerModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.prefix' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('defaultCode', 'prefix', $value);

                    // Extract index from attribute controller
                    $index = str_replace(['*', '.prefix'], '', $attribute);
                    // Get the ID and controller from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $prefixModel = DefaultCode::where('prefix', $value)->first();
                    $idModel = DefaultCode::where('id', $id)->first(); // Find model by ID

                    // Check if a country with the same title exists but with a different ID
                    if ($prefixModel && $idModel && $prefixModel->id !== $idModel->id) {
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
        $defaultCarriers = collect($request->all())->map(function ($defaultCarrier) {
            $defaultCarrier_all = collect($defaultCarrier)->all();
            $defaultCarrier = DefaultCode::find($defaultCarrier_all['id']);
            $defaultCarrier->update($defaultCarrier_all);
            return $defaultCarrier;
        });

        return response()->json([
            'statut' => 1,
            'data' => $defaultCarriers,
        ]);
    }

    public function destroy($id)
    {
        $defaultCarrier = DefaultCode::find($id);
        $defaultCarrier->delete();
        return response()->json([
            'statut' => 1,
            'data' => $defaultCarrier,
        ]);
    }
}
