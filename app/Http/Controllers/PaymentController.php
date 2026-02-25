<?php

namespace App\Http\Controllers;

use App\Models\AccountCompensation;
use Illuminate\Http\Request;
use App\Models\AccountUser;
use App\Models\Compensation;
use App\Models\Compensationable;
use App\Models\Payment;
use App\Models\Paymentable;
use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf as FacadePdf;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    public static function index(Request $request)
    {
        $searchIds = [];
        $request = collect($request->query())->toArray();
        if (isset($request['users']) && array_filter($request['users'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['users'] as $productId) {
                if (AccountUser::find($productId))
                    $searchIds = array_merge($searchIds, AccountUser::find($productId)->payments->pluck('id')->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        $model = 'App\\Models\\Payment';
        $request['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
        $request['where'] = ['column' => 'payment_id', 'value' => null];
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'code'], false, [])->map(function ($payment) {
            $paymentData = $payment->only('id', 'code', 'created_at', 'date_debut', 'date_end', 'statut');
            $amount = 0;
            $payment->paymentables->map(function ($paymentable) use (&$amount) {
                $amount += $paymentable->commission;
            });
            $paymentData['amount'] = $amount;
            $paymentData['user'] = $payment->accountUser->user->only('id', 'firstname', 'lastname');
            $paymentData['user']['images'] = $payment->accountUser->user->images;
            $paymentData['users_action'] = $payment->childPayments->map(function ($child) {
                $childData = $child->only('created_at', 'statut');
                $childData['user'] = $child->accountUser->user->only('id', 'firstname', 'lastname');
                $childData['user']['images'] = $child->accountUser->user->images;
                return $childData;
            });
            return $paymentData;
        });
        $filters = HelperFunctions::filterColumns($request, []);
        return HelperFunctions::getPagination(collect($datas), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
    }

    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        if (isset($request['payments']['inactive'])) {
            $paymentables = Paymentable::with('compensationable.accountCompensation.defaultCompensations')
                ->where("account_user_id", $request['payments']['inactive']['user_id'])
                ->whereNull('payment_id')
                ->where('created_at', '>', $request['payments']['inactive']['date_debut'])
                ->where('updated_at', '<', $request['payments']['inactive']['date_end'])
                ->get()->map(function ($paymentable) {
                    $data['id'] = $paymentable->id;
                    $data['created_at'] = $paymentable->created_at;
                    $data['orderStatus']['order'] = ['id' => $paymentable->accountUserOrderStatus->order->id, 'code' => $paymentable->accountUserOrderStatus->order->code, 'statut' => $paymentable->accountUserOrderStatus->order->order_status_id];
                    $data['orderStatus']['order_status'] = ['id' => $paymentable->accountUserOrderStatus->orderStatus->id, 'title' => $paymentable->accountUserOrderStatus->orderStatus->title];
                    $data['accountCompensation'] = $paymentable->compensationable->accountCompensation->defaultCompensations->first()->only('start_date', 'end_date', 'created_at', 'statut');
                    $data['accountCompensation']['id'] = $paymentable->compensationable->accountCompensation->id;
                    $data['accountCompensation']['title'] = $paymentable->compensationable->accountCompensation->title;
                    $data['accountCompensation']['compensation']['id'] = $paymentable->compensationable->accountCompensation->compensation->id;
                    $data['accountCompensation']['compensation']['title'] = $paymentable->compensationable->accountCompensation->compensation->title;
                    $data['accountCompensation']['commissions'] = $paymentable->compensationable->accountCompensation->defaultCompensations->map(function ($default) {
                        $defaultData = $default->only('amount', 'commission');
                        $defaultData['comparisonOperator'] = $default->comparisonOperator->only('id', 'symbol', 'title');
                        return $defaultData;
                    });
                    return $data;
                });
            $filters = HelperFunctions::filterColumns($request['payments']['inactive'], []);
            $data['payments']['inactive'] =  HelperFunctions::getPagination(collect($paymentables), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }
    public function getSalaryId($accountUserId)
    {
        $salaryId = 0;
        $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->get()->pluck('id')->toArray();
        $compensations = Compensation::where(['compensation_type_id' => 1])->get()->pluck('id')->toArray();
        $accountCompensations = AccountCompensation::whereIn('compensation_id', $compensations)->whereIn('account_user_id', $accountUsers)->get();
        $accountUser = AccountUser::find($accountUserId);
        $activeSalaryUser = Compensationable::where(['compensationable_id' => $accountUserId, 'compensationable_type' => 'App\Models\AccountUser', 'statut' => 1])->whereIn('account_compensation_id', $accountCompensations->pluck('id')->toArray())->first();
        if ($activeSalaryUser) {
            $salaryId = $activeSalaryUser->id;
        }
        $activeSalaryRole = Compensationable::where(['compensationable_type' => 'App\Models\Role', 'statut' => 1])->whereIn('compensationable_id', $accountUser->roles->pluck('id'))->whereIn('account_compensation_id', $accountCompensations->pluck('id')->toArray())->first();
        if ($activeSalaryRole) {
            $salaryId = $activeSalaryRole->id;
        }

        foreach ($accountCompensations as $accountCompensation) {
            if ($accountCompensation->compensation_id == 19) {
                $salaryId = $accountCompensation->defaultSalary->first()->id;
                break;
            } elseif ($accountCompensation->compensation_id == 23) {
                $salaryId = $accountCompensation->defaultSalary->first()->id;
                break;
            }
        }
        $paymentable = Paymentable::create([
            "account_user_id" => $accountUser->id,
            "paymentable_id" => $accountUser->id,
            "paymentable_type" => 'App\Models\AccountUser',
            "statut" => 0,
            "compensationable_id" => $salaryId,
        ]);
        return $paymentable->id;
    }
    public function store(Request $requests)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.statut' => 'required|int',
            '*.date_debut' => 'required|date',
            '*.date_end' => 'required|date',
            '*.payment_method_id' => 'required|exists:payment_methods,id',
            '*.payments.*'  => [
                'required', 'int',
                function ($attribute, $value, $fail) {
                    $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->get()->pluck('id')->toArray();
                    $paymentable = Paymentable::where('id', $value)->whereNull('payment_id')->whereIn('account_user_id', $accountUsers)->first();
                    if (!$paymentable) {
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
        $payments = collect($requests->except('_method'))->map(function ($request) {
            $request['account_user_id'] = $request['user_id'];
            $request['code'] = DefaultCodeController::getAccountCode('Payment', getAccountUser()->account_id);
            $request['payments'][] = $this->getSalaryId($request['account_user_id']);
            $payment_only = collect($request)->only('code', 'date_debut', 'date_end', 'payment_method_id', 'description', 'statut', 'account_user_id');
            $payment = Payment::create($payment_only->all());
            $createdBy = Payment::create([
                'payment_id' => $payment->id,
                'date_debut' => $payment->date_debut,
                'date_end' => $payment->date_end,
                'code' => $payment->code . "CR",
                'account_user_id' => getAccountUser()->id,
                'statut' => $payment->statut,
            ]);

            if (isset($request['payments'])) {
                foreach ($request['payments'] as $key => $paymentId) {
                    $paymentable = Paymentable::find($paymentId);
                    $paymentable->update([
                        'payment_id' => $payment->id,
                        'statut' => $payment->statut,
                    ]);
                }
            }
            $this->calculatePayment($payment);
            $payment = Payment::with('paymentables')->find($payment->id);
            return $payment;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $payments,
        ]);
    }

    public function print($id)
    {
        // Fetch the offer data from the database
        $offer = Payment::findOrFail($id);

        // Load the PDF view and pass the offer data
        $pdf = FacadePdf::loadView('pdf.offer', compact('offer'));

        // Stream the PDF to the browser
        return $pdf->stream('offer.pdf');
    }

    public function show($id)
    {
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $payment = Payment::find($id);
        if (!$payment)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        $data = [];
        $paymentData = $payment->toArray();
        $user = $payment->accountUser->user->only('firstname', 'lastname');
        $user['id'] = $payment->accountUser->id;
        $paymentData['payment_method'] = $payment->paymentMethod->only('id', 'title');
        $paymentData['user'] = $user;
        if (isset($request['paymentInfo'])) {
            $data['paymentInfo'] = [
                "statut" => 1,
                "data" => $paymentData
            ];
        }
        if (isset($request['payments']['active'])) {
            $paymentables = Paymentable::with('compensationable.accountCompensation.defaultCompensations')
                ->where("account_user_id", $payment->account_user_id)
                ->where('payment_id', $payment->id)
                ->get()->map(function ($paymentable) {
                    $data['id'] = $paymentable->id;
                    $data['created_at'] = $paymentable->created_at;
                    $data['orderStatus']['order'] = ['id' => $paymentable->accountUserOrderStatus->order->id, 'code' => $paymentable->accountUserOrderStatus->order->code, 'statut' => $paymentable->accountUserOrderStatus->order->order_status_id];
                    $data['orderStatus']['order_status'] = ['id' => $paymentable->accountUserOrderStatus->orderStatus->id, 'title' => $paymentable->accountUserOrderStatus->orderStatus->title];
                    $data['accountCompensation']['id'] = $paymentable->compensationable->accountCompensation->id;
                    $data['accountCompensation']['title'] = $paymentable->compensationable->accountCompensation->title;
                    $data['accountCompensation']['compensation']['id'] = $paymentable->compensationable->accountCompensation->compensation->id;
                    $data['accountCompensation']['compensation']['title'] = $paymentable->compensationable->accountCompensation->compensation->title;
                    $data['accountCompensation']['commissions'] = $paymentable->compensationable->accountCompensation->defaultCompensations->map(function ($default) {
                        $defaultData = $default->only('amount', 'commission');
                        $defaultData['comparisonOperator'] = ($default->comparisonOperator) ? $default->comparisonOperator->only('id', 'symbol', 'title') : null;
                        return $defaultData;
                    });
                    return $data;
                });
            $filters = HelperFunctions::filterColumns($request['payments']['active'], []);
            $data['payments']['active'] =  HelperFunctions::getPagination(collect($paymentables), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['payments']['inactive'])) {
            $paymentables = Paymentable::with('compensationable.accountCompensation.defaultCompensations')
                ->where("account_user_id", $payment->account_user_id)
                ->whereNull('payment_id')
                ->where('created_at', '>', $payment->date_debut)
                ->where('updated_at', '<', $payment->date_end)
                ->get()->map(function ($paymentable) {
                    $data['id'] = $paymentable->id;
                    $data['created_at'] = $paymentable->created_at;
                    $data['orderStatus']['order'] = ['id' => $paymentable->accountUserOrderStatus->order->id, 'code' => $paymentable->accountUserOrderStatus->order->code, 'statut' => $paymentable->accountUserOrderStatus->order->order_status_id];
                    $data['orderStatus']['order_status'] = ['id' => $paymentable->accountUserOrderStatus->orderStatus->id, 'title' => $paymentable->accountUserOrderStatus->orderStatus->title];
                    $data['accountCompensation']['id'] = $paymentable->compensationable->accountCompensation->id;
                    $data['accountCompensation']['title'] = $paymentable->compensationable->accountCompensation->title;
                    $data['accountCompensation']['compensation']['id'] = $paymentable->compensationable->accountCompensation->compensation->id;
                    $data['accountCompensation']['compensation']['title'] = $paymentable->compensationable->accountCompensation->compensation->title;
                    $data['accountCompensation']['commissions'] = $paymentable->compensationable->accountCompensation->defaultCompensations->map(function ($default) {
                        $defaultData = $default->only('amount', 'commission');
                        $defaultData['comparisonOperator'] = ($default->comparisonOperator) ? $default->comparisonOperator->only('id', 'symbol', 'title') : null;
                        return $defaultData;
                    });
                    return $data;
                });
            $filters = HelperFunctions::filterColumns($request['payments']['inactive'], []);
            $data['payments']['inactive'] =  HelperFunctions::getPagination(collect($paymentables), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }

    public function update(Request $requests, $id)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'exists:payments,id|max:255',
            '*.statut' => 'required|int',
            '*.paymentstoActive.*' => [
                'required', 'int',
                function ($attribute, $value, $fail) {
                    $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->get()->pluck('id')->toArray();
                    $paymentable = Paymentable::where('id', $value)->whereNull('payment_id')->whereIn('account_user_id', $accountUsers)->first();
                    if (!$paymentable) {
                        $fail("not exist");
                    }
                },
            ],
            '*.paymentstoInactive.*' => [
                'required', 'int',
                function ($attribute, $value, $fail) {
                    $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->get()->pluck('id')->toArray();
                    $paymentable = Paymentable::where('id', $value)->whereIn('account_user_id', $accountUsers)->first();
                    if (!$paymentable) {
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
        $payments = collect($requests->except('_method'))->map(function ($request) {
            $payment_only = collect($request)->only('id', 'description', 'statut');
            $payment = Payment::find($request['id']);
            $payment->update($payment_only->all());
            $updatedBy = Payment::create([
                'payment_id' => $payment->id,
                'date_debut' => $payment->date_debut,
                'date_end' => $payment->date_end,
                'code' => $payment->code . "UP",
                'account_user_id' => getAccountUser()->id,
                'statut' => $payment->statut,
            ]);

            if (isset($request['paymentstoActive'])) {
                foreach ($request['paymentstoActive'] as $paymentId) {
                    $paymentable = Paymentable::find($paymentId);
                    $paymentable->update([
                        'payment_id' => $payment->id,
                        'statut' => $payment->statut,
                    ]);
                }
            }

            if (isset($request['paymentstoInactive'])) {
                foreach ($request['paymentstoInactive'] as $paymentId) {
                    $paymentable = Paymentable::find($paymentId);
                    $paymentable->update([
                        'payment_id' => null,
                        'commission' => null,
                        'statut' => 0,
                    ]);
                }
            }

            $this->calculatePayment($payment);
            if ($payment->statut == 2)
                $this->validatePayment($payment);
            $payment = Payment::with('paymentables')->find($payment->id);
            return $payment;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $payments,
        ]);
    }
    public function validatePayment($payment)
    {
        $amount = 0;
        foreach ($payment->paymentables as $paymentable) {
            $amount += $paymentable->commission;
        }
        $transactionData[] = [
            "transaction_id" => $payment->id,
            "transaction_type_id" => 2,
            "amount" => $amount,
            "type" => "commission"
        ];
        $transaction = TransactionController::store(new Request($transactionData));
        return $transaction;
    }
    public function calculatePayment($payment)
    {
        $compensationables = [];
        $payment->paymentables->map(function ($paymentable) use (&$compensationables) {
            $compensationables[$paymentable->compensationable->accountCompensation->id]["account_compensation_id"] = $paymentable->compensationable->accountCompensation->id;
            $compensationables[$paymentable->compensationable->accountCompensation->id]["compensationable_id"] = $paymentable->compensationable->id;
            $compensationables[$paymentable->compensationable->accountCompensation->id]["ids"][] = $paymentable->id;
        });
        return collect($compensationables)->values()->map(function ($compensationable) {
            $accountCompensation = AccountCompensation::find($compensationable['account_compensation_id']);
            if ($accountCompensation->compensation_id == 1 || $accountCompensation->compensation_id == 4) {
            } elseif ($accountCompensation->compensation_id == 3 || $accountCompensation->compensation_id == 2) {
                $nbrOfOrder = count($compensationable['ids']);
                if ($accountCompensation->compensation_goal_id == 1) {
                    return "Montant total des commandes";
                } else {
                    $commissionValidated = null;
                    foreach ($accountCompensation->defaultCompensations->sortby('amount') as $defaultCompensation) {
                        if (eval("return \$nbrOfOrder {$defaultCompensation->comparisonOperator->symbol} \$defaultCompensation->amount;")) {
                            $commissionValidated = $defaultCompensation->commission;
                            break;
                        }
                    }
                    $paymentableCommissions = Paymentable::whereIn('id', $compensationable['ids'])->get();
                    $paymentableCommissions->map(function ($paymentableCommission) use ($commissionValidated) {
                        $paymentableCommission->update(['commission' => $commissionValidated]);
                    });
                }
            } elseif ($accountCompensation->compensation_id == 4) {
            } elseif ($accountCompensation->compensation_id == 6) {
            } elseif ($accountCompensation->compensation_id == 7) {
            } elseif ($accountCompensation->compensation_id == 11) {
            } elseif ($accountCompensation->compensation_id == 12) {
            } elseif ($accountCompensation->compensation_id == 13) {
            } elseif ($accountCompensation->compensation_id == 14) {
            } elseif ($accountCompensation->compensation_id == 15) {
            } elseif ($accountCompensation->compensation_id == 16) {
            } elseif ($accountCompensation->compensation_id == 17) {
            } elseif ($accountCompensation->compensation_id == 18) {
            } elseif ($accountCompensation->compensation_id == 19) {
            } elseif ($accountCompensation->compensation_id == 20 || $accountCompensation->compensation_id == 21 || $accountCompensation->compensation_id == 22 || $accountCompensation->compensation_id == 23 || $accountCompensation->compensation_id == 24) {
                $commissionValidated = Compensationable::find($compensationable['compensationable_id'])->amount;
                $paymentableCommissions = Paymentable::whereIn('id', $compensationable['ids'])->get();
                $paymentableCommissions->map(function ($paymentableCommission) use ($commissionValidated) {
                    $paymentableCommission->update(['commission' => $commissionValidated]);
                });
            }
        });
    }

    public function destroy($id)
    {
        $Offer = Payment::find($id);
        $Offer->delete();
        return response()->json([
            'statut' => 1,
            'data' => $Offer,
        ]);
    }
}
