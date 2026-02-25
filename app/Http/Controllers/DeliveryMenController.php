<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\accounts_carrier;
use App\Models\delivery_men;
use Validator;
use DB;
class DeliveryMenController extends Controller
{

    public function index(Request $request)
    {
        $delivery_man = DB::table('delivery_mens')
            ->join('accounts_carriers', 'accounts_carriers.id', '=', 'delivery_mens.account_carrier_id')
            ->join('carriers', 'carriers.id', '=', 'accounts_carriers.carrier_id')
            ->select('delivery_mens.name as delivery_men_name',
                    'carriers.title as carrier_name',
            )->get();

        return response()->json([
            'delivery_man '=>$delivery_man,
        ]);
    }

    public function create(Request $request)
    {
        $carriers = DB::table('accounts_carriers')
            ->join('carriers', 'carriers.id', '=', 'accounts_carriers.carrier_id')
            ->select('accounts_carriers.id',
                    'carriers.title as carrier_name',
            )->get();
        return response()->json([
            'statut' => 1,
            'cities ' => $carriers,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'account_carrier_id' => 'required',
            'photo' => 'required',
            'photo_dir' => 'required',
            'statut' => 'required',
        ]);
        if($validator->fails()){
            return response()->json([
                'Validation Error', $validator->errors()
            ]);       
        };
        $delivery_men_only = collect($request->all())->only('name','account_carrier_id','photo','photo_dir','statut')->all();
        $delivery_men = delivery_men::create($delivery_men_only);
    
        return response()->json([
            'statut' => 1,
            'delivery_men' => $delivery_men,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit($id)
    {
        $delivery_men = delivery_men::find($id);
        $carriers = DB::table('accounts_carriers')
            ->join('carriers', 'carriers.id', '=', 'accounts_carriers.carrier_id')
            ->select('accounts_carriers.id',
                    'carriers.title as carrier_name',
            )->get();
        return response()->json([
            'statut' => 1,
            'delivery_men ' => $delivery_men,
            'carriers ' => $carriers
        ]);

    }

    public function update(Request $request, $id)
    {

        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'account_carrier_id' => 'required',
            'photo' => 'required',
            'photo_dir' => 'required',
            'statut' => 'required',
        ]);
        if($validator->fails()){
            return response()->json([
                'Validation Error', $validator->errors()
            ]);       
        };
        $delivery_men_col = collect($request->all())->only('name','account_carrier_id','photo','photo_dir','statut')->all();
        $delivery_men = delivery_men::find($id)->update($delivery_men_col);
        $delivery_men_updated = delivery_men::find($id);
        return response()->json([
            'statut' => 1,
            'delivery_men' => $delivery_men_updated,
        ]);
    }


    public function destroy($id)
    {
        $delivery_men_b =  delivery_men::find($id);
        $delivery_men = delivery_men::find($id)->delete();
        return response()->json([
            'statut' => 1,
            'delivery_men' => $delivery_men_b,
        ]);
    }
}
