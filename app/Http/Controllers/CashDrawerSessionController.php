<?php

namespace App\Http\Controllers;

use App\Models\CashDrawer;
use App\Models\CashDrawerSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class CashDrawerSessionController extends Controller
{
    public function index(Request $request)
    {
        $request = collect($request->query())->toArray();

        $accountUser = getAccountUser();
        $isCashier = $accountUser->hasRole('caissier') && !$accountUser->hasAnyRole(['admin', 'administrateur', 'super-admin', 'manager', 'comptable', 'Comptable']);

        $model = 'App\\Models\\CashDrawerSession';

        $query = CashDrawerSession::query();

        if (isset($request['cash_drawer_id'])) {
            $query->where('cash_drawer_id', $request['cash_drawer_id']);
        }

        $query->whereHas('cashDrawer', function ($q) use ($accountUser) {
            $q->where('account_id', $accountUser->account_id);
        });

        if ($isCashier) {
            $query->where('account_user_id', $accountUser->id);
        }

        if (isset($request['date_from'])) {
            $query->where('opened_at', '>=', $request['date_from']);
        }
        if (isset($request['date_to'])) {
            $query->where('opened_at', '<=', $request['date_to']);
        }
        if (isset($request['statut'])) {
            $query->where('statut', $request['statut']);
        }

        $sessions = $query->with(['accountUser.user', 'cashDrawer'])
            ->orderByDesc('opened_at')
            ->paginate($request['per_page'] ?? 10);

        $sessions->getCollection()->transform(function ($session) {
            $data = $session->only('id', 'code', 'opening_balance', 'closing_balance', 'expected_balance', 'difference', 'opened_at', 'closed_at', 'notes', 'statut');
            $data['user'] = [
                'id' => $session->accountUser->id,
                'firstname' => $session->accountUser->user->firstname,
                'lastname' => $session->accountUser->user->lastname,
                'images' => $session->accountUser->user->images,
            ];
            $data['cash_drawer'] = $session->cashDrawer->only('id', 'code', 'title');
            $totalIn = (float) $session->transactions()->where('direction', 'in')->sum('amount');
            $totalOut = (float) $session->transactions()->where('direction', 'out')->sum('amount');
            $data['total_in'] = $totalIn;
            $data['total_out'] = $totalOut;
            if ($session->isOpen()) {
                $data['expected_balance'] = (float) $session->opening_balance + $totalIn - $totalOut;
            }
            return $data;
        });

        return response()->json([
            'statut' => 1,
            'data' => $sessions,
        ]);
    }

    public function store(Request $requests)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.cash_drawer_id' => 'required|exists:cash_drawers,id',
            '*.opening_balance' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }

        $sessions = collect($requests->except('_method'))->map(function ($request) {
            $cashDrawer = CashDrawer::find($request['cash_drawer_id']);

            if (!$cashDrawer || $cashDrawer->account_id !== getAccountUser()->account_id) {
                return ['error' => 'Cash drawer not found'];
            }

            $existingOpen = CashDrawerSession::where('cash_drawer_id', $request['cash_drawer_id'])
                ->where('statut', 1)
                ->first();

            if ($existingOpen) {
                return ['error' => 'Une session est déjà ouverte sur cette caisse', 'session_id' => $existingOpen->id];
            }

            $session = CashDrawerSession::create([
                'code' => DefaultCodeController::getAccountCode('CashDrawerSession', getAccountUser()->account_id) ?: ('SESS' . strtoupper(uniqid())),
                'cash_drawer_id' => $request['cash_drawer_id'],
                'account_user_id' => getAccountUser()->id,
                'opening_balance' => $request['opening_balance'],
                'opened_at' => Carbon::now(),
                'notes' => $request['notes'] ?? null,
                'statut' => 1,
            ]);

            return $session;
        });

        return response()->json([
            'statut' => 1,
            'data' => $sessions,
        ]);
    }

    public function show($id)
    {
        $session = CashDrawerSession::with(['accountUser.user', 'cashDrawer', 'transactions' => function ($q) {
            $q->orderByDesc('created_at');
        }, 'transactions.accountUser.user', 'transactions.transactionType'])->find($id);

        if (!$session) {
            return response()->json([
                'statut' => 0,
                'data' => 'not exist',
            ]);
        }

        $data = $session->only('id', 'code', 'opening_balance', 'closing_balance', 'expected_balance', 'difference', 'opened_at', 'closed_at', 'notes', 'statut');

        $data['user'] = [
            'id' => $session->accountUser->id,
            'firstname' => $session->accountUser->user->firstname,
            'lastname' => $session->accountUser->user->lastname,
        ];
        $data['cash_drawer'] = $session->cashDrawer->only('id', 'code', 'title');

        $totalIn = $session->transactions->where('direction', 'in')->sum('amount');
        $totalOut = $session->transactions->where('direction', 'out')->sum('amount');

        $data['summary'] = [
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'net' => $totalIn - $totalOut,
            'expected_balance' => $session->opening_balance + $totalIn - $totalOut,
            'transaction_count' => $session->transactions->count(),
        ];

        $data['transactions'] = $session->transactions->map(function ($transaction) {
            return [
                'id' => $transaction->id,
                'code' => $transaction->code,
                'title' => $transaction->title,
                'amount' => $transaction->amount,
                'direction' => $transaction->direction,
                'transaction_type' => $transaction->transactionType ? $transaction->transactionType->title : null,
                'created_at' => $transaction->created_at,
                'user' => [
                    'id' => $transaction->accountUser->id,
                    'firstname' => $transaction->accountUser->user->firstname,
                    'lastname' => $transaction->accountUser->user->lastname,
                ],
            ];
        });

        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }

    public function update(Request $requests, $id)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'required|exists:cash_drawer_sessions,id',
            '*.closing_balance' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }

        $sessions = collect($requests->except('_method'))->map(function ($request) {
            $session = CashDrawerSession::find($request['id']);

            if (!$session || !$session->isOpen()) {
                return ['error' => 'Session not found or already closed'];
            }

            $expectedBalance = $session->calculateExpectedBalance();
            $closingBalance = $request['closing_balance'];
            $difference = $closingBalance - $expectedBalance;

            $session->update([
                'closing_balance' => $closingBalance,
                'expected_balance' => $expectedBalance,
                'difference' => $difference,
                'closed_at' => Carbon::now(),
                'notes' => $request['notes'] ?? $session->notes,
                'statut' => 2,
            ]);

            return $session;
        });

        return response()->json([
            'statut' => 1,
            'data' => $sessions,
        ]);
    }

    public function destroy($id)
    {
        $session = CashDrawerSession::find($id);

        if (!$session) {
            return response()->json([
                'statut' => 0,
                'data' => 'not exist',
            ]);
        }

        if ($session->transactions()->count() > 0) {
            return response()->json([
                'statut' => 0,
                'data' => 'Cannot delete session with transactions',
            ]);
        }

        $session->delete();

        return response()->json([
            'statut' => 1,
            'data' => $session,
        ]);
    }
}
