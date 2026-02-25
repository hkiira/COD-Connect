<?php

namespace App\Http\Controllers;

use App\Models\AccountCompensation;
use App\Models\AccountUser;
use App\Models\Compensation;
use App\Models\CompensationType;
use App\Models\Order;
use App\Models\Paymentable;
use App\Models\Role;
use Illuminate\Http\Request;

class CompensationableController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public static function edit($id)
    {
        // Get the account users related to the current account
        $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->get()->pluck('user_id')->toArray();

        // Get the IDs of all compensations of type '2' (commissions)
        $commissions = Compensation::where('compensation_type_id', 2)->get()->pluck('id')->toArray();

        // Get all active account compensations that match the retrieved commissions and account users
        $compensations = AccountCompensation::with('baseCalculateCompensations', 'toCalculateCompensations')
            ->where('statut', 1)
            ->whereIn('compensation_id', $commissions)
            ->whereIn('account_user_id', $accountUsers)
            ->get();

        // Group the compensations by their related calculations
        $modelsGrouped = $compensations->flatMap(function ($compensation) {
            return $compensation->toCalculateCompensations->map(function ($calculated) use ($compensation) {
                // Copy calculated data and add additional information
                $calculatedData = $calculated;
                $calculatedData['compensation_id'] = $compensation->id;
                $calculatedData['compensation_type_id'] = $compensation->compensation->id;
                $calculatedData['roles'] = $compensation->baseCalculateCompensations
                    ->where('compensationable_type', "App\\Models\\Role")
                    ->pluck('compensationable_id')
                    ->toArray();
                $calculatedData['users'] = $compensation->baseCalculateCompensations
                    ->where('compensationable_type', "App\\Models\\AccountUser")
                    ->pluck('compensationable_id')
                    ->toArray();
                return $calculatedData;
            });
        });

        // Find the order by its ID
        $order = Order::find($id);

        // Process each grouped model to create or retrieve the necessary paymentables
        return $modelsGrouped->map(function ($grouped) use ($order) {
            //verify if the type of compensationable as a orderStatus and i need to add the logic for all types of compensationable
            if ($grouped->compensationable_type == "App\\Models\\OrderStatus") {
                // Retrieve account users related to the roles
                $accountUsers = Role::whereIn('id', $grouped->roles)->get()->flatMap(function ($role) {
                    return $role->accountUsers->pluck('id');
                })->merge($grouped->users)->toArray();
                // Find the order status for the given account users and order status ID
                $orderStatus = $order->accountUserOrderStatus
                    ->whereIn('account_user_id', $accountUsers)
                    ->where('order_status_id', $grouped->compensationable_id)
                    ->first();

                if ($orderStatus) {
                    // Check if a paymentable already exists for the order status
                    $havePaymentable = Paymentable::where(['paymentable_id' => $orderStatus->id])->first();

                    if (!$havePaymentable) {
                        // Create a new paymentable if it doesn't exist
                        $data = [
                            "account_user_id" => $orderStatus->account_user_id,
                            "paymentable_type" => "App\\Models\\AccountUserOrderStatus",
                            "paymentable_id" => $orderStatus->id,
                            "statut" => 0,
                            "created_at" => now(),
                            "updated_at" => now(),
                            "compensationable_id" => $grouped->id,
                        ];
                        $paymentable = Paymentable::create($data);
                        return $paymentable;
                    }
                    return $havePaymentable;
                }
            }
        })->filter()->values();

        return $modelsGrouped;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
