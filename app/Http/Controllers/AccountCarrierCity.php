<?php
/*
namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\city;
use App\Models\User;
use App\Models\account;
use App\Models\account_carrier;
use App\Models\account_city;
use App\Models\account_carrier_city;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AccountCarrierCity extends Controller
{

    public function index(Request $request)
    {
        $account = User::find(Auth::user()->id)->accounts->first();
        $carriers = account::find($account->id)->carriers()->paginate(20);
        return response()->json([
            'statut ' => 1,
            'data' => $carriers,
        ]);
    }

    public function create(Request $request)
    {
        //
    }


    public function edit($id)
    {
        $account = User::find(Auth::user()->id)->accounts->first();
        $account_carrier = account_carrier::find($id)->first();
        if ($account->id != $account_carrier->account_id) {
            return response()->json([
                'statut ' => 0,
                'data' => 'not found',
            ]);
        }
        $account_carrier_citys = account_carrier::with('account_city', 'account_carrier_city')->get();
        foreach ($account_carrier_citys as $account_carrier_city) {
            if (count($account_carrier_city->account_city) > 0)
                $account_carrier_city->city = account_city::find($account_carrier_city->account_city->first()->id)->cities->first();
        }
        return response()->json([
            'statut' => 1,
            'data' => $account_carrier_citys,

        ]);
    }


    public function update(Request $request, $id, $local = 0)
    {
        if ($local == 0) {
            $validator = Validator::make($request->all(), [
                'price' => 'required',
                'return' => 'required',
                'delivery_time' => 'required',
                'statut' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'Validation Error', $validator->errors()
                ]);
            };
        }
        $account = User::find(Auth::user()->id)->accounts->first();
        $account_carrier_city = account_carrier_city::find($id);
        if ($account->id != $account_carrier_city->account_carrier->first()->account_id) {
            return response()->json([
                'statut ' => 0,
                'data' => 'not found',
            ]);
        }
        $account_carrier_city->update($request->all());
        $account_carrier_city_updated = account_carrier_city::find($id);

        if ($local == 1)
            return $account_carrier_city_updated;

        return response()->json([
            'statut' => 1,
            'data' => $account_carrier_city_updated,
        ]);
    }

    public function update_carrier_cities(Request $request)
    {
        // dd($request->all());
        $validator = Validator::make($request->all(), [
            '*.id' => 'required',
            '*.price' => 'required',
            '*.return' => 'required',
            '*.delivery_time' => 'required',
            '*.statut' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'Validation Error', $validator->errors()
            ]);
        };
        $account_carrier_citys_updated = collect($request->all())->map(function ($account_carrier_city) {
            $account_city_updated = $this->update(new Request($account_carrier_city), $account_carrier_city['id'], $local = 1);
            return $account_city_updated;
        });
        return response()->json([
            'statut' => 1,
            'data' => $account_carrier_citys_updated
        ]);
    }

    public function destroy($id)
    {
    }
}
*/