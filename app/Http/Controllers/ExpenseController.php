<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AccountUser;
use App\Models\Compensation;
use App\Models\Expense;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class ExpenseController extends Controller
{
    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $request['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
        $associated = [];
        $model = 'App\\Models\\Expense';
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'code'], true, $associated);
        return $datas;
    }

    public function create(Request $request)
    {
    }

    public function store(Request $requests)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.amount' => 'required|numeric',
            '*.statut' => 'int',
            '*.date' => 'date',
            '*.expense_type_id' => 'required|exists:expense_types,id',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }
        $expenses = collect($requests->except('_method'))->map(function ($request) {
            $request['account_user_id'] = getAccountUser()->id;
            $request['code'] = DefaultCodeController::getAccountCode('Expense', getAccountUser()->account_id);
            $expense_only = collect($request)->only('code', 'date', 'description', 'statut', 'expense_type_id', 'amount', 'account_user_id');
            $expense = Expense::create($expense_only->all());
            return $expense;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $expenses,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $expense = Expense::find($id);
        if (!$expense)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        return response()->json([
            'statut' => 0,
            'data' => $expense
        ]);
    }

    public function update(Request $requests, $id)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'required|exists:expenses,id',
            '*.amount' => 'required|numeric',
            '*.statut' => 'int',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }
        $expenses = collect($requests->except('_method'))->map(function ($request) {
            $request['account_user_id'] = getAccountUser()->id;
            $expense_only = collect($request)->only('statut');
            $expense = Expense::find($request['id']);
            $expense->update($expense_only->all());
            return $expense;
        });

        return response()->json([
            'statut' => 1,
            'data' => $expenses,
        ]);
    }



    public function destroy($id)
    {
        $expense = Expense::find($id);
        $expense->delete();
        return response()->json([
            'statut' => 1,
            'data' => $expense,
        ]);
    }
}
