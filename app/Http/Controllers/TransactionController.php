<?php


namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use App\Models\AccountUser;
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
        $associated = [];
        $model = 'App\\Models\\Transaction';
        $request['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'code'], true, $associated);
        $datas['data'] = $datas['data']->map(function ($transaction) {
            $transactionData = $transaction->only('id', 'amount', 'statut', 'transaction_type', 'transaction_id', 'created_at');
            $transactionData['user'] = [
                "id" => $transaction->accountUser->id,
                "firstname" => $transaction->accountUser->user->firstname,
                "lastname" => $transaction->accountUser->user->lastname,
                "images" => $transaction->accountUser->user->images,
            ];
            $fullyQualifiedName = $transactionData['transaction_type'];
            $reflection = new ReflectionClass($fullyQualifiedName);
            $transactionData['model'] = $reflection->getShortName();
            $transactionData['about'] = ($transactionData['transaction_type']::find($transactionData['transaction_id'])) ? $transactionData['transaction_type']::find($transactionData['transaction_id'])->code : null;
            $transactionData['transaction_type'] = $transaction->transactionType->title;
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
            $request['code'] = DefaultCodeController::getAccountCode('Transaction', $account_id);
            switch ($request['type']) {
                case 'shipment':
                    $request['transaction_type'] = "App\Models\Shipment";
                    break;
                case 'carrier':
                    $request['transaction_type'] = "App\Models\Carrier";
                    break;
                case 'commission':
                    $request['transaction_type'] = "App\Models\Payment";
                    break;

                default:
                    $request['transaction_type'] = "App\Models\Shipment";
                    break;
            }
            $transaction_only = collect($request)->only('code', 'account_user_id', 'transaction_type', 'transaction_id', 'amount', 'transaction_type_id');
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
