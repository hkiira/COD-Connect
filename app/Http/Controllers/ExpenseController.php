<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AccountUser;
use App\Models\CashDrawerSession;
use App\Models\Compensation;
use App\Models\Expense;
use App\Models\Transaction;
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

            if (isset($request['cash_drawer_session_id'])) {
                $session = CashDrawerSession::find($request['cash_drawer_session_id']);
                if ($session && $session->isOpen()) {
                    Transaction::create([
                        'code' => DefaultCodeController::getAccountCode('Transaction', getAccountUser()->account_id) ?: ('TRX' . strtoupper(uniqid())),
                        'cash_drawer_id' => $session->cash_drawer_id,
                        'cash_drawer_session_id' => $session->id,
                        'transaction_id' => $expense->id,
                        'title' => $expense->description,
                        'amount' => $expense->amount,
                        'direction' => 'out',
                        'transaction_type' => 'App\\Models\\Expense',
                        'transaction_type_id' => 2,
                        'account_user_id' => getAccountUser()->id,
                        'statut' => 1,
                    ]);
                }
            }

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
            'statut' => 1,
            'data' => $expense
        ]);
    }

    public function update(Request $requests, $id)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'required|exists:expenses,id',
            '*.amount' => 'numeric',
            '*.statut' => 'int',
            '*.date' => 'date',
            '*.expense_type_id' => 'exists:expense_types,id',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }
        $expenses = collect($requests->except('_method'))->map(function ($request) {
            $request['account_user_id'] = getAccountUser()->id;
            $expense_only = collect($request)->only('amount', 'description', 'date', 'expense_type_id', 'statut');
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
