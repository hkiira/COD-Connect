<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\supplier_billing;
use App\Models\account_user;
use Auth;
use Validator;
use DB;
class SupplierBillingController extends Controller
{
    public function index(Request $request)
    {

        $account_user = account_user::where('user_id',Auth::user()->id)
            ->first(['account_id','user_id']);
        $supplier_billings = supplier_billing::where('account_id', $account_user->account_id)
            ->get();

        return response()->json([
            'statut' => 1,
            'supplier_billings ' => $supplier_billings,
        ]);
    }

    public function create(Request $request)
    {

    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required',
            'photo' => 'required',
            'photo_dir' => 'required',
            'statut' => 'required'
        ]);
        if($validator->fails()){
            return response()->json([
                'Validation Error', $validator->errors()
            ]);       
        };

        $account_user = account_user::where('user_id',Auth::user()->id)
            ->first(['account_id','user_id']);
            
        $supplier_billing_only = collect($request->only('title','website','email','photo','photo_dir','statut'))
            ->put('account_id',$account_user->account_id)->all();
        $supplier_billing = supplier_billing::create($supplier_billing_only);
    
        return response()->json([
            'statut' => 'product created successfuly',
            'supplier_billing' => $supplier_billing,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit($id)
    {
        $supplier_billing = supplier_billing::find($id);
        return response()->json([
            'statut' => 1,
            'supplier_billing' => $supplier_billing,
        ]);
    }


    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required',
            'photo' => 'required',
            'photo_dir' => 'required',
            'statut' => 'required'
        ]);
        if($validator->fails()){
            return response()->json([
                'Validation Error', $validator->errors()
            ]);       
        };
        $account_user = DB::table('account_users')
        ->join('users','users.id', '=', 'account_users.user_id')
        ->join('accounts','accounts.id', '=', 'account_users.account_id')
        ->where('users.id',Auth::user()->id)
        ->select('accounts.name as account_name',
                'accounts.id as account_id',
                'users.id as user_id'
        )->first();
        $supplier_billing_only = collect($request->only('title','website','email','photo','photo_dir','statut'));

        $supplier_billing = supplier_billing::find($id)->update($supplier_billing_only->all());
        $supplier_billing_updated = supplier_billing::where('id',$id)->get();

        return response()->json([
            'statut' => 1,
            'supplier_billing' => $supplier_billing_updated,
        ]);
    }


    public function destroy($id)
    {
        $supplier_billing_deleted = supplier_billing::where('id',$id)->get();
        $supplier_billing = supplier_billing::where('id',$id)->delete();
        return response()->json([
            'statut' => 1,
            'supplier_billing' => $supplier_billing_deleted,
        ]);
    }
}
