<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Address;
use App\Models\User;
use App\Models\Account;
use App\Models\AccountCity;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class AddressController extends Controller
{

    public function index(Request $request)
    {
        $account = getAccountUser()->account_id;
        $addresses = Address::with("cities")->where('account_id', $account)->get();

        return response()->json([
            'statut' => 1,
            'addresses' => $addresses,
        ]);
    }

    public function create(Request $request, $local = 0)
    {
    }

    public static function store(Request $requests, $local = 0, $model = null)
    {
        $account = User::find(Auth::user()->id)->accounts->first();
        if ($local == 0) {
            $validator = Validator::make($requests->except('_method'), [
                '*.title' => 'required',
                '*.city_id' => 'exists:cities,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'Validation Error', $validator->errors()
                ]);
            };
        }
        $addresses = collect($requests->except('_method'))->map(function ($request) use ($model, $local) {
            $account = getAccountUser()->account_id;
            $addressData = new Request($request);
            $address = Address::create([
                'title' => $addressData->title,
                'city_id' => $addressData->city_id,
                'statut' => $addressData->statut,
                'account_id' => $account,
            ]);
            if ($local == 1)
                $model->addresses()->attach($address->id, ['created_at' => now(), 'updated_at' => now(), 'statut' => 1]);
            return $address;
            return $address;
        });

        return response()->json([
            'statut' => 1,
            'address' => $addresses,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit($id)
    {
        $account = User::find(Auth::user()->id)->accounts->first();
        $cities = account::find($account->id)->cities;
        $address = address::find($id);
        if (!$address) {
            return response()->json([
                'statut' => 1,
                'data' => 'not found'
            ]);
        }
        $account_city = AccountCity::find($address->account_city_id);
        if ($account_city->account_id != $account->id) {
            return response()->json([
                'statut' => 1,
                'data' => 'not found'
            ]);
        }
        return response()->json([
            'statut' => 1,
            'address' => $address,
            'cities' => $cities
        ]);
    }


    public static function update(Request $requests, $id, $local = 0, $model = null)
    {
        if ($local == 0) {
            $validator = Validator::make($requests->except('_method'), [
                '*.id' => 'exists:addresses,id',
                '*.title' => 'required',
                '*.city_id' => 'exists:cities,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'Validation Error', $validator->errors()
                ]);
            };
            $addresses = collect($requests->except('_method'))->map(function ($request) use ($local) {
                $addressData = new Request($request);
                $address = address::find($addressData->id);
                $address->update($request);
                return $address;
            });
            return response()->json([
                'statut' => 1,
                'data' => $addresses,
            ]);
        } else {
            $addresses = collect($requests->except('_method'))->map(function ($addresse) use ($model) {
                $addresse_all = collect($addresse)->all();
                $addresse = $model->addresses->where('title', $addresse_all['title'])->first();
                if ($addresse) {
                    if ($addresse->city->id != $addresse_all['city_id'])
                        $addresse->update(['city_id' => $addresse_all['city_id']]);
                    $addresse = Address::find($addresse->id);
                    return (isset($addresse_all['principal'])) ? $addresse : null;
                } else {
                    $account = getAccountUser()->account_id;
                    $addresse = Address::create([
                        'title' => $addresse_all['title'],
                        'city_id' => $addresse_all['city_id'],
                        'account_id' => $account,
                    ]);
                    $addresse->customers()->syncWithoutDetaching([
                        $model->id => ['created_at' => now(), 'updated_at' => now(), 'statut' => 1]
                    ]);
                    return (isset($addresse_all['principal'])) ? $addresse : null;
                }
            })->filter();
            return $addresses;
        }
    }


    public function destroy($id)
    {
        $Addresse = Address::find($id);
        $Addresse->delete();
        return response()->json([
            'statut' => 1,
            'data' => $Addresse,
        ]);
    }
}
