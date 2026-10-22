<?php


namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use App\Models\AccountUser;
use App\Models\CashDrawerSession;
use App\Models\Warehouse;
use App\Models\Transaction;
use App\Models\Pickup;
use App\Models\Carrier;
use App\Models\Collector;
use Illuminate\Support\Facades\Validator;
use ReflectionClass;

class TransactionController extends Controller
{
    public function index(Request $request)
    {

        $searchIds = [];
        $request = collect($request->query())->toArray();
        if (isset($request['carriers']) && array_filter($request['carriers'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['carriers'] as $carrier) {
                if (Carrier::find($carrier))
                    $searchIds = array_merge($searchIds, Carrier::find($carrier)->pickups->pluck('id')->unique()->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        if (isset($request['users']) && array_filter($request['users'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['users'] as $carrier) {
                if (Carrier::find($carrier))
                    $searchIds = array_merge($searchIds, Carrier::find($carrier)->pickups->pluck('id')->unique()->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        if (isset($request['suppliers']) && array_filter($request['suppliers'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['suppliers'] as $warehouseId) {
                if (Warehouse::find($warehouseId))
                    $searchIds = array_merge($searchIds, Warehouse::find($warehouseId)->pickups->pluck('id')->unique()->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }

        if (isset($request['cash_drawer_session_id'])) {
            $request['wheres'][] = ['column' => 'cash_drawer_session_id', 'value' => $request['cash_drawer_session_id']];
        }
        if (isset($request['direction']) && $request['direction'] !== 'all') {
            $request['wheres'][] = ['column' => 'direction', 'value' => $request['direction']];
        }
        if (isset($request['cash_drawer_id'])) {
            $request['wheres'][] = ['column' => 'cash_drawer_id', 'value' => $request['cash_drawer_id']];
        }
        if (isset($request['supplier_id'])) {
            $request['wheres'][] = ['column' => 'supplier_id', 'value' => $request['supplier_id']];
        }
        if (isset($request['carrier_id'])) {
            $request['wheres'][] = ['column' => 'carrier_id', 'value' => $request['carrier_id']];
        }

        $associated = [];
        $model = 'App\\Models\\Transaction';
        $request['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'code', 'title'], true, $associated);
        $datas['data'] = $datas['data']->map(function ($transaction) {
            $transactionData = $transaction->only('id', 'code', 'title', 'notes', 'amount', 'direction', 'category', 'supplier_id', 'carrier_id', 'statut', 'transaction_type', 'transaction_id', 'cash_drawer_session_id', 'cash_drawer_id', 'created_at');
            $transactionData['user'] = $transaction->accountUser ? [
                "id" => $transaction->accountUser->id,
                "firstname" => $transaction->accountUser->user->firstname ?? '',
                "lastname" => $transaction->accountUser->user->lastname ?? '',
                "images" => $transaction->accountUser->user->images ?? null,
            ] : null;

            $transactionData['supplier'] = $transaction->supplier ? [
                "id" => $transaction->supplier->id,
                "title" => $transaction->supplier->title,
                "code" => $transaction->supplier->code,
            ] : null;

            $transactionData['carrier'] = $transaction->carrier ? [
                "id" => $transaction->carrier->id,
                "title" => $transaction->carrier->title,
                "code" => $transaction->carrier->code,
            ] : null;

            if ($transactionData['transaction_type'] && class_exists($transactionData['transaction_type'])) {
                try {
                    $reflection = new ReflectionClass($transactionData['transaction_type']);
                    $transactionData['model'] = $reflection->getShortName();
                    $targetRecord = $transactionData['transaction_type']::find($transactionData['transaction_id']);
                    $transactionData['about'] = $targetRecord ? ($targetRecord->code ?? null) : null;
                } catch (\Throwable $e) {
                    $transactionData['model'] = null;
                    $transactionData['about'] = null;
                }
            } else {
                $transactionData['model'] = null;
                $transactionData['about'] = null;
            }

            $transactionData['transaction_type'] = $transaction->transactionType ? $transaction->transactionType->title : null;
            return $transactionData;
        });
        return  $datas;
    }

    public function create(Request $request)
    {

        return response()->json([
            'statut' => 1,
            'data' => [],
        ]);
    }

    public static function store(Request $requests)
    {
        $transactions = collect($requests->except('_method'))->map(function ($request) {
            $request["account_user_id"] = getAccountUser()->id;
            $account_id = getAccountUser()->account_id;
            $request['code'] = DefaultCodeController::getAccountCode('Transaction', $account_id) ?: ('TRX' . strtoupper(uniqid()));
            $request['statut'] = $request['statut'] ?? 1;

            if (isset($request['cash_drawer_session_id'])) {
                $session = CashDrawerSession::find($request['cash_drawer_session_id']);
                if (!$session || !$session->isOpen()) {
                    return ['error' => 'Aucune session de caisse ouverte'];
                }
                $request['cash_drawer_id'] = $session->cash_drawer_id;
            }

            switch ($request['type'] ?? null) {
                case 'shipment':
                    $request['transaction_type'] = "App\Models\Shipment";
                    break;
                case 'carrier':
                    $request['transaction_type'] = "App\Models\Carrier";
                    break;
                case 'commission':
                    $request['transaction_type'] = "App\Models\Payment";
                    break;
                case 'expense':
                    $request['transaction_type'] = "App\Models\Expense";
                    break;
                case 'supplier':
                    $request['transaction_type'] = "App\Models\Supplier";
                    break;
                default:
                    $request['transaction_type'] = $request['transaction_type'] ?? (isset($request['cash_drawer_session_id']) ? "App\\Models\\CashDrawerSession" : "App\\Models\\Shipment");
                    break;
            }
            if (!isset($request['transaction_id'])) {
                if (isset($request['supplier_id'])) {
                    $request['transaction_id'] = $request['supplier_id'];
                } elseif (isset($request['carrier_id'])) {
                    $request['transaction_id'] = $request['carrier_id'];
                } elseif (isset($request['cash_drawer_session_id'])) {
                    $request['transaction_id'] = $request['cash_drawer_session_id'];
                }
            }
            $transaction_only = collect($request)->only('code', 'account_user_id', 'cash_drawer_id', 'cash_drawer_session_id', 'transaction_type', 'transaction_id', 'supplier_id', 'carrier_id', 'category', 'amount', 'direction', 'title', 'notes', 'transaction_type_id', 'statut');
            $transaction = Transaction::create($transaction_only->all());
            return $transaction;
        });
        return response()->json([
            'statut' => 1,
            'data' => $transactions,
        ]);
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $data = [];

        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }


    public function update(Request $requests)
    {

        return response()->json([
            'statut' => 1,
            'data' => [],
        ]);
    }


    public function destroy($id)
    {
        return response()->json([
            'statut' => 1,
            'data' => [],
        ]);
    }
}
