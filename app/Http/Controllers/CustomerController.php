<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\Image;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class CustomerController extends Controller
{
    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated = [];
        $model = 'App\\Models\\Customer';
        $request['inAccount'] = ['account_id', getAccountUser()->account_id];
        $request['whereNot'] = ['column' => 'Customer_type_id', 'value' => 1];
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'name'], true, $associated);
        return $datas;
    }

    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        if (isset($request['sectors']['inactive'])) {
            $model = 'App\\Models\\Sector';
            $data['sectors']['inactive'] = FilterController::searchs(new Request($request['sectors']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['customerTypes']['inactive'])) {
            $model = 'App\\Models\\CustomerType';
            $data['customerTypes']['inactive'] = FilterController::searchs(new Request($request['customerTypes']['inactive']), $model, ['id', 'title'], true);
        }

        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }

    public static function store(Request $requests, $local = 0)
    {
        // avoir code
        $phoneableType = "App\Models\Customers";
        $validator = Validator::make($requests->except('_method'), [
            '*.name' => 'required|max:255',
            '*.phones.*.title' => [
                'string',
                function ($attribute, $value, $fail) use ($phoneableType) {
                    $account = getAccountUser()->account_id;
                    $phone = \App\Models\Phone::where(['title' => $value, 'account_id' => $account])->first();
                    if ($phone) {
                        $isUnique = \App\Models\Phoneable::where('phone_id', $phone->id)
                            ->where('phoneable_type', $phoneableType)
                            ->first();
                        if ($isUnique) {
                            $fail("A phone '$value' number already taken.");
                        }
                    }
                },
            ],
            '*.phones.*.phoneTypes' => 'required|exists:phone_types,id|max:255',
            '*.customer_type_id' => 'exists:customer_types,id|max:255',
            '*.sector_id' => 'exists:sectors,id|max:255',
            '*.addresses.*.title' => 'max:255',
            '*.addresses.*.city_id' => 'exists:cities,id|max:255',
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.principalImage' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage && $principalImage->account_id !== getAccountUser()->account_id) {
                        $fail("not exist");
                    }
                },
            ],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'Validation Error', $validator->errors()
            ]);
        };
        $customers = collect($requests->except('_method'))->map(function ($request) {
            $request["account_id"] = getAccountUser()->account_id;
            $request['code'] = DefaultCodeController::getAccountCode('Customer', $request["account_id"]);
            $customer_only = collect($request)->only('code', 'name', 'sector_id', 'latitude', 'longtitude', 'ice', 'comment', 'facebook', 'note', 'customer_type_id', 'statut', 'account_id');
            $customer = Customer::create($customer_only->all());
            if (isset($request['phones'])) {
                $request_phone = new Request($request['phones']);
                $phone = PhoneController::store($request_phone, $local = 1, $customer);
            }

            if (isset($request['addresses'])) {
                $request_address = new Request($request['addresses']);
                $address = AddressController::store($request_address, $local = 1, $customer);
            }

            if (isset($request['principalImage'])) {
                $image = Image::find($request['principalImage']);
                $image->images()->syncWithoutDetaching([
                    $customer->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($request['newPrincipalImage'])) {
                $images[]["image"] = $request['newPrincipalImage'];
                $imageData = [
                    'title' => $customer->name,
                    'type' => 'customer',
                    'image_type_id' => 11,
                    'images' => $images
                ];
                ImageController::store(new Request([$imageData]), $customer);
            }
            $customer = Customer::with(['images', 'phones', 'addresses'])->find($customer->id);
            
            return $customer;
        });
        if ($local == 1)
            return $customers;
        return response()->json([
            'statut' => 1,
            'data' =>  $customers,
        ]);
    }


    public function show($id)
    {
        $customer = Customer::with(['phones.phoneTypes', 'addresses.city', 'images'])->find($id);
        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'not exist'], 404);
        }
        return response()->json(['statut' => 1, 'data' => $customer]);
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $customer = Customer::with(['phones.phoneTypes', 'addresses.city', 'images'])->find($id);
        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'not exist'], 404);
        }
        return response()->json([
            'statut' => 1,
            'data' => $customer,
        ]);
    }

    public static function update(Request $requests, $id, $isOrder = 0)
    {
        $phoneableType = "App\Models\Customers";
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'required|exists:customers,id',
            '*.name' => 'required|max:255',
            '*.phones.*.title' => [
                'string',
                function ($attribute, $value, $fail) use ($phoneableType) {
                    $account = getAccountUser()->account_id;
                    $phone = \App\Models\Phone::where(['title' => $value, 'account_id' => $account])->first();
                    if ($phone) {
                        $isUnique = \App\Models\Phoneable::where('phone_id', $phone->id)
                            ->where('phoneable_type', $phoneableType)
                            ->first();
                        if ($isUnique) {
                            $fail("A phone '$value' number already taken.");
                        }
                    }
                },
            ],
            '*.phones.*.phoneTypes' => 'exists:phone_types,id|max:255',
            '*.customer_type_id' => 'exists:customer_types,id|max:255',
            '*.sector_id' => 'exists:sectors,id|max:255',
            '*.addresses.*.title' => 'max:255',
            '*.addresses.*.city_id' => 'exists:cities,id|max:255',
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.principalImage' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage && $principalImage->account_id !== getAccountUser()->account_id) {
                        $fail("not exist");
                    }
                },
            ],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'Validation Error', $validator->errors()
            ]);
        };
        $customers = collect($requests->except('_method'))->map(function ($request) use ($isOrder) {
            $customer_only = collect($request)->only('name', 'sector_id', 'latitude', 'longtitude', 'ice', 'comment', 'facebook', 'note', 'customer_type_id', 'statut');
            $customer = Customer::find($request['id']);
            $customer->update($customer_only->toArray());
            $phoneOrder = null;
            $addressOrder = null;
            if (isset($request['phones'])) {
                $request_phone = new Request($request['phones']);
                $phoneOrder = PhoneController::update($request_phone, $customer->id, $local = 1, $customer);
            }

            if (isset($request['addresses'])) {
                $request_address = new Request($request['addresses']);
                $addressOrder = AddressController::update($request_address, $customer->id, $local = 1, $customer);
            }
            if (isset($request['principalImage'])) {
                $image = Image::find($request['principalImage']);
                $image->images()->syncWithoutDetaching([
                    $customer->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($request['newPrincipalImage'])) {
                $images[]["image"] = $request['newPrincipalImage'];
                $imageData = [
                    'title' => $customer->name,
                    'type' => 'customer',
                    'image_type_id' => 11,
                    'images' => $images
                ];
                ImageController::store(new Request([$imageData]), $customer);
            }
            $customer = Customer::with(['images', 'phones', 'addresses'])->find($customer->id);
            if ($isOrder == 1)
                return ['phones' => $phoneOrder, 'addresses' => $addressOrder, 'customer' => $customer];
            return $customer;
        });
        if ($isOrder == 1)
            return $customers;
        return response()->json([
            'statut' => 1,
            'data' => $customers,
        ]);
    }



    public function destroy($id)
    {
        $customer = Customer::find($id);
        $customer->delete();
        return response()->json([
            'statut' => 1,
            'data' => $customer,
        ]);
    }
}
