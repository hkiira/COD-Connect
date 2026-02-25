<?php

namespace App\Http\Controllers;

use App\Models\AccountCarrierCity;
use App\Models\AccountCarrier;
use App\Models\City;
use App\Models\DefaultCarrier;
use Illuminate\Http\Request;
use App\Models\Carrier;
use App\Models\Account;
use App\Models\User;
use App\Models\Image;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class AccountCarrierController extends Controller
{

    public function index(Request $request)
    {
        $account = User::find(Auth::user()->id)->accounts->first();
        $carriers = Account::find($account->id)->carriers;

        return response()->json([
            'statut' => 1,
            'data' => $carriers,
        ]);
    }

    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        if (isset($request['cities']['inactive'])) {
            $model = 'App\\Models\\City';
            //permet de récupérer la liste des regions inactive filtrés
            $data['cities']['inactive'] = FilterController::searchs(new Request($request['cities']['inactive']), $model, ['id', 'title'], true);
        }
        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }

    // public function store(Request $requests)
    // {
    //     $phoneableType="App\Models\Carrier";
    //     $validator = Validator::make(requests->except('_method'),[
    //         '*.title' => [ // Validate title field
    //             'required', // Title is required
    //             'max:255', // Title should not exceed 255 characters
    //             function ($attribute, $value, $fail)use($requests){ // Custom validation rule
    //                 // Call the function to rename removed records
    //                 RestoreController::renameRemovedRecords('carrier', 'title', $value);
    //                 $account_id=getAccountUser()->account_id;
    //                 $titleModel = Carrier::where(['title'=>$value])->where('account_id',$account_id)->first();
    //                 if ($titleModel) {
    //                     $fail("exist"); 
    //                 }
    //             },
    //         ],
    //         '*.phones.*.title' => [
    //             'string',
    //             function ($attribute, $value, $fail) use ($phoneableType) {
    //                 $account = getAccountUser()->account_id;
    //                 $phone=\App\Models\Phone::where(['title'=>$value,'account_id'=>$account])->first();
    //                 if ($phone) {
    //                     $isUnique = \App\Models\Phoneable::where('phone_id', $phone->id)
    //                         ->where('phoneable_type', $phoneableType)
    //                         ->first();
    //                     if ($isUnique){
    //                         $fail("A phone '$value' number already taken.");
    //                     }
    //                 }

    //             },
    //         ],
    //         '*.phones.*.phone_type_id' => 'exists:phone_types,id|max:255',
    //         '*.addresses.*.title' => 'max:255',
    //         '*.addresses.*.city_id' => 'exists:cities,id|max:255',
    //         '*.email' => 'string',
    //         '*.trackinglink' => 'string',
    //         '*.autocode' => 'required|int',
    //         '*.comment' => 'string',
    //         '*.statut'=>'required',
    //         '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
    //         '*.principalImage' => [ // Validate title field
    //             'max:255', // Title should not exceed 255 characters
    //             function ($attribute, $value, $fail){ // Custom validation rule
    //                 // Call the function to rename removed records
    //                 $principalImage = Image::where('id', $value)->first();
    //                 if ($principalImage && $principalImage->account_id!==getAccountUser()->account_id) {
    //                     $fail("not exist"); 
    //                 }
    //             },
    //         ],

    //         '*.cities.*.id' => 'exists:cities,id|max:255',
    //         '*.cities.*.name' => 'string',
    //         '*.cities.*.price' => 'required|numeric',
    //         '*.cities.*.return' => 'required|numeric',
    //         '*.cities.*.delivery_time' => 'required|int',
    //     ]);
    //     if($validator->fails()){
    //         return response()->json([
    //             'statut' => 0,
    //             'data' => $validator->errors(),
    //         ]);       
    //     };
    //     $carriers = collect(requests->except('_method'))->map(function ($request) {
    //         $request["account_id"]=getAccountUser()->account_id;
    //         $request['code']=DefaultCodeController::getAccountCode('Carrier',$request["account_id"]);
    //         $carrier_only=collect($request)->only('code','title','email','trackinglink','autocode','comment','statut','account_id');
    //         $carrier = Carrier::create($carrier_only->all());
    //         AccountCarrier::create([
    //             "carrier_id"=>$carrier->id,
    //             "account_id"=>$request["account_id"],
    //             "autocode"=>$request["autocode"],
    //             "username"=>isset($request["username"])?$request['username']:null,
    //             "password"=>isset($request["password"])?$request['password']:null,
    //             "token"=>isset($request["token"])?$request['token']:null,
    //             "statut"=>1
    //         ]);
    //         if(isset($request['phones'])){
    //             $request_phone = new Request($request['phones']);
    //             PhoneController::store( $request_phone, $local=1, $carrier);
    //         }

    //         if(isset($request['addresses'])){
    //             $request_address = new Request($request['addresses']);
    //             AddressController::store( $request_address, $local=1, $carrier);
    //         }

    //         if(isset($request['cities'])){
    //             foreach ($request['cities'] as $key => $cityData) {
    //                 $city = City::find($cityData['id']);
    //                 $price=($cityData['price'])?$cityData['price']:0;
    //                 $return=($cityData['return'])?$cityData['return']:0;
    //                 $delivery_time=($cityData['delivery_time'])?$cityData['delivery_time']:0;
    //                 $title=($cityData['name'])?$cityData['name']:$city->title;
    //                 if($city){
    //                     $city->carriers()->attach($carrier,['name'=>$title,'price'=>$price,'return'=>$return,'delivery_time'=>$delivery_time,'statut'=>1,'created_at'=>now(),'updated_at'=>now()]);
    //                     $city->save();
    //                 }
    //             }
    //         }

    //         if(isset($request['principalImage'])){
    //             $image=Image::find($request['principalImage']);
    //             $image->images()->syncWithoutDetaching([
    //                 $carrier->id => [
    //                     'created_at' => now(),
    //                     'updated_at' => now()
    //                 ]
    //             ]);
    //         }elseif(isset($request['newPrincipalImage'])){
    //             $imageData=[
    //                 'title'=>$carrier->title,
    //                 'type'=>'carrier',
    //                 'image'=>$request['newPrincipalImage']
    //             ];
    //             ImageController::store( new Request([$imageData]),$carrier);
    //         }

    //         $carrier = Carrier::with('images', 'phones','addresses')->find($carrier->id);
    //         return $carrier;
    //     });
    //     return response()->json([
    //         'statut' => 1,
    //         'data' => $carriers,
    //     ]);   
    // }
    // public function show($id)
    // {
    //     //
    // }

    // public function edit($id)
    // {
    //     $account = User::find(Auth::user()->id)->accounts->first();
    //     $carrier = carrier::find($id);
    //     return response()->json([
    //         'statut' => 1,
    //         'data' => $carrier
    //     ]);
    // }


    public function update(Request $requests, $id)
    {
        $phoneableType = "App\Models\Carrier";
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'exists:carriers,id|max:255',
            '*.autocode' => 'required|int',
            '*.statut' => 'required',
            '*.citiesToInactive.*' => 'exists:cities,id|max:255',
            '*.citiesToChange.*.id' => 'exists:cities,id|max:255',
            '*.citiesToChange.*.price' => 'required|numeric',
            '*.citiesToChange.*.return' => 'required|numeric',
            '*.citiesToChange.*.delivery_time' => 'required|int',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'Validation Errors' => $validator->errors()
            ]);
        }
        $carriers = collect($requests->except("_method"))->map(function ($request) {
            $account_id = getAccountUser()->account_id;
            $accountCarrier = AccountCarrier::where(['account_id' => $account_id, 'carrier_id' => $request['id']])->first();
            $carrier_only = collect($request)->only('id', 'autocode', 'username', 'password', 'token', 'statut');
            $accountCarrier->update($carrier_only->all());


            if (isset($request['citiesToInactive'])) {
                foreach ($request['citiesToInactive'] as $cityId) {
                    $defaultcity = AccountCarrierCity::where(['city_id' => $cityId, 'account_carrier_id' => $accountCarrier->id])->first();
                    if ($defaultcity) {
                        $defaultcity->update(['statut' => 0]);
                    } else {
                        $defaultcity = DefaultCarrier::where(['city_id' => $cityId, 'carrier_id' => $accountCarrier->carrier_id])->first();
                        AccountCarrierCity::create([
                            "account_carrier_id" => $accountCarrier->id,
                            "price"    => $defaultcity->price,
                            "return" => $defaultcity->return,
                            "delivery_time" => $defaultcity->delivery_time,
                            "statut" => 0,
                            "city_id" => $defaultcity->city_id
                        ]);
                    }
                }
            }
            if (isset($request['citiesToChange'])) {
                foreach ($request['citiesToChange'] as $cityData) {
                    $defaultcity = DefaultCarrier::where(['city_id' => $cityData['id'], 'carrier_id' => $accountCarrier->carrier_id])->first();
                    $city = City::find($cityData['id']);
                    $price = ($cityData['price']) ? $cityData['price'] : $defaultcity->price;
                    $return = ($cityData['return']) ? $cityData['return'] : $defaultcity->return;
                    $delivery_time = ($cityData['delivery_time']) ? $cityData['delivery_time'] : $defaultcity->delivery_time;
                    if ($city) {
                        $city->accountCarriers()->syncWithoutDetaching([$accountCarrier->id => ['price' => $price, 'return' => $return, 'delivery_time' => $delivery_time, 'statut' => 1, 'created_at' => now(), 'updated_at' => now()]]);
                    }
                }
            }


            $carrier = Carrier::with('images', 'phones', 'addresses')->find($accountCarrier->carrier_id);
            return $carrier;
        });
        return response()->json([
            'statut' => 1,
            'data' => $carriers,
        ]);
    }


    public function destroy($id)
    {
        $carrier_b =  carrier::find($id);
        $carrier = carrier::find($id)->delete();
        return response()->json([
            'statut' => 1,
            'carrier' => $carrier_b,
        ]);
    }
}
