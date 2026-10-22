<?php

namespace App\Http\Controllers;

use App\Models\CashDrawer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CashDrawerController extends Controller
{
    public function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $request['inAccount'] = ['account_id', getAccountUser()->account_id];

        $accountUser = getAccountUser();
        $isCashier = $accountUser->hasRole('caissier') && !$accountUser->hasAnyRole(['admin', 'administrateur', 'super-admin', 'manager', 'comptable', 'Comptable']);

        $model = 'App\\Models\\CashDrawer';
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'code', 'title'], true, []);

        $datas['data'] = $datas['data']->map(function ($cashDrawer) {
            $data = $cashDrawer->only('id', 'code', 'title', 'description', 'statut', 'created_at');
            $data['user'] = $cashDrawer->accountUser ? [
                'id' => $cashDrawer->accountUser->id,
                'firstname' => $cashDrawer->accountUser->user->firstname,
                'lastname' => $cashDrawer->accountUser->user->lastname,
                'images' => $cashDrawer->accountUser->user->images,
            ] : null;
            $activeSession = $cashDrawer->activeSession;
            $data['active_session'] = $activeSession ? [
                'id' => $activeSession->id,
                'code' => $activeSession->code,
                'opening_balance' => $activeSession->opening_balance,
                'opened_at' => $activeSession->opened_at,
                'expected_balance' => $activeSession->calculateExpectedBalance(),
            ] : null;
            $data['balance'] = $activeSession ? $activeSession->calculateExpectedBalance() : 0;
            return $data;
        });

        if ($isCashier) {
            $datas['data'] = $datas['data']->filter(function ($item) use ($accountUser) {
                return $item['user'] && $item['user']['id'] === $accountUser->id;
            })->values();
        }

        return $datas;
    }

    public function create(Request $request)
    {
        return response()->json([
            'statut' => 1,
            'data' => [],
        ]);
    }

    public function store(Request $requests)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.title' => 'required|string|max:255',
            '*.statut' => 'int',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }

        $cashDrawers = collect($requests->except('_method'))->map(function ($request) {
            $request['account_id'] = getAccountUser()->account_id;
            $code = DefaultCodeController::getAccountCode('CashDrawer', getAccountUser()->account_id);
            $request['code'] = $code ?: ('CSH' . strtoupper(uniqid()));

            $only = collect($request)->only('code', 'title', 'description', 'account_id', 'account_user_id', 'cash_drawer_id', 'statut');
            $cashDrawer = CashDrawer::create($only->all());

            return $cashDrawer;
        });

        return response()->json([
            'statut' => 1,
            'data' => $cashDrawers,
        ]);
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $data = [];

        $cashDrawer = CashDrawer::with(['accountUser.user', 'sessions' => function ($q) {
            $q->orderByDesc('opened_at')->limit(20);
        }])->find($id);

        if (!$cashDrawer) {
            return response()->json([
                'statut' => 0,
                'data' => 'not exist',
            ]);
        }

        $data['cashDrawerInfo'] = $cashDrawer->only('id', 'code', 'title', 'description', 'statut', 'account_user_id', 'cash_drawer_id');
        $data['cashDrawerInfo']['user'] = $cashDrawer->accountUser ? [
            'id' => $cashDrawer->accountUser->id,
            'firstname' => $cashDrawer->accountUser->user->firstname,
            'lastname' => $cashDrawer->accountUser->user->lastname,
        ] : null;

        $activeSession = $cashDrawer->activeSession;
        $data['cashDrawerInfo']['active_session'] = $activeSession ? [
            'id' => $activeSession->id,
            'code' => $activeSession->code,
            'opening_balance' => $activeSession->opening_balance,
            'opened_at' => $activeSession->opened_at,
            'expected_balance' => $activeSession->calculateExpectedBalance(),
            'user' => [
                'id' => $activeSession->accountUser->id,
                'firstname' => $activeSession->accountUser->user->firstname,
                'lastname' => $activeSession->accountUser->user->lastname,
            ],
        ] : null;

        $data['sessions'] = $cashDrawer->sessions->map(function ($session) {
            return [
                'id' => $session->id,
                'code' => $session->code,
                'opening_balance' => $session->opening_balance,
                'closing_balance' => $session->closing_balance,
                'expected_balance' => $session->expected_balance,
                'difference' => $session->difference,
                'opened_at' => $session->opened_at,
                'closed_at' => $session->closed_at,
                'statut' => $session->statut,
                'user' => [
                    'id' => $session->accountUser->id,
                    'firstname' => $session->accountUser->user->firstname,
                    'lastname' => $session->accountUser->user->lastname,
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
            '*.id' => 'required|exists:cash_drawers,id',
            '*.title' => 'string|max:255',
            '*.statut' => 'int',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }

        $cashDrawers = collect($requests->except('_method'))->map(function ($request) {
            $only = collect($request)->only('title', 'description', 'account_user_id', 'cash_drawer_id', 'statut');
            $cashDrawer = CashDrawer::find($request['id']);
            $cashDrawer->update($only->all());
            return $cashDrawer;
        });

        return response()->json([
            'statut' => 1,
            'data' => $cashDrawers,
        ]);
    }

    public function destroy($id)
    {
        $cashDrawer = CashDrawer::find($id);

        if (!$cashDrawer) {
            return response()->json([
                'statut' => 0,
                'data' => 'not exist',
            ]);
        }

        $cashDrawer->delete();

        return response()->json([
            'statut' => 1,
            'data' => $cashDrawer,
        ]);
    }
}
