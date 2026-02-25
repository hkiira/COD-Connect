<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Role;
use App\Models\DefaultCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{

    public function index(Request $request)
    {

        $model = 'App\\Models\\Account';
        $accounts = FilterController::searchs($request, $model, ['id', 'name'], true);


        return response()->json([
            'statut' => 1,
            'accounts' => $accounts,
        ]);
    }

    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        if (isset($request['roles']['inactive'])) {
            $model = 'App\\Models\\Role';
            $request['roles']['inactive']['where'] = ['column' => 'role_type_id', 'value' => 1];
            //permet de récupérer la liste des regions inactive filtrés
            $data['roles']['inactive'] = FilterController::searchs(new Request($request['roles']['inactive']), $model, ['id', 'name'], true);
        }
        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }

    public function store(Request $requests)
    {

        $validator = Validator::make($requests->except('_method'), [
            '*.name' => 'required|max:255',
            '*.user.name' => 'required|max:255',
            '*.user.firstname' => 'required|max:255',
            '*.user.lastname' => 'required|max:255',
            '*.user.email' => 'required|email|unique:users,email',
            '*.user.password' => 'required|min:8|confirmed',
            '*.user.password_confirmation' => 'required',
            '*.user.phones.*.title' => [
                'string',
                function ($attribute, $value, $fail) {
                    $phoneableType = "App\Models\User";
                    $account = getAccountUser()->account_id;
                    $phone = \App\Models\Phone::where(['title' => $value, 'account_id' => $account])->first();
                    if ($phone) {
                        $isUnique = \App\Models\Phoneable::where('phone_id', $phone->id)
                            ->where('phoneable_type', $phoneableType)
                            ->first();
                        if ($isUnique) {
                            $fail("exist.");
                        }
                    }
                },
            ],
            '*.user.phones.*.phoneTypes.*' => 'required|exists:phone_types,id',
            '*.user.addresses.*.title' => 'max:255',
            '*.user.addresses.*.city_id' => 'exists:cities,id|max:255',
            '*.phones.*.title' => [
                'string',
                function ($attribute, $value, $fail) {
                    $phoneableType = "App\Models\Account";
                    $account = getAccountUser()->account_id;
                    $phone = \App\Models\Phone::where(['title' => $value, 'account_id' => $account])->first();
                    if ($phone) {
                        $isUnique = \App\Models\Phoneable::where('phone_id', $phone->id)
                            ->where('phoneable_type', $phoneableType)
                            ->first();
                        if ($isUnique) {
                            $fail("exist.");
                        }
                    }
                },
            ],
            '*.phones.*.phoneTypes.*' => 'required|exists:phone_types,id',
            '*.addresses.*.title' => 'max:255',
            '*.addresses.*.city_id' => 'exists:cities,id|max:255',
            '*.roles.*' => 'required|exists:roles,id|max:255',
            '*.image' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $accounts = collect($requests->except('_method'))->map(function ($request) {
            $request['code'] = DefaultCodeController::getCode('Account');
            $request['statut'] = 1;
            $account_only = collect($request)->only('name', 'code', 'statut');
            $account = Account::create($account_only->all());

            if (isset($request['roles'])) {
                foreach ($request['roles'] as $roleId) {
                    $role = Role::find($roleId);
                    $account->assignRole($role);
                }
            }

            if (isset($request['user'])) {
                $request['user']['roles'] = [2];
                $request_user = new Request([$request['user']]);
                $user = UserController::store($request_user, $local = 1, $account);
                $request_warehouse = new Request([["title" => "Dépôt Principale", "users" => $account->accountUsers()->pluck('id')->toArray()]]);
                $warehouse = WarehouseController::store($request_warehouse, $local = 1, $account);
            }

            if (isset($request['phones'])) {
                $request_phone = new Request($request['phones']);
                $phone = PhoneController::store($request_phone, $local = 1, $account);
            }

            if (isset($request['addresses'])) {
                $request_address = new Request($request['addresses']);
                $address = AddressController::store($request_address, $local = 1, $account);
            }

            if (isset($request['image'])) {
                $imageData = [
                    'title' => $account->name,
                    'type' => 'account',
                    'image' => $request['image']
                ];
                $account_image = ImageController::store(new Request([$imageData]), $account);
            }

            $account = Account::with('images', 'users', 'roles', 'phones', 'addresses')->find($account->id);
            return $account;
        });
        return response()->json([
            'statut' => 1,
            'data' => $accounts,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit($id)
    {
        $account = account::find($id);
        if (!$account || $account->id != getAccountUser()->account_id)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'account' => $account,

        ]);
    }


    public function update(Request $requests)
    {
        $phoneableType = "App\Models\User";
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => [
                function ($attribute, $value, $fail) use ($phoneableType, $requests) {
                    $account = getAccountUser()->account_id;
                    if ($attribute != $account) {
                        $fail("Not exist 401");
                    }
                },
            ],
            '*.name' => 'required|max:255',
            '*.phones.*.title' => [
                'string',
                function ($attribute, $value, $fail) use ($phoneableType, $requests) {
                    $index = str_replace(['*', '.title'], '', $attribute);
                    $phoneable_id = $requests->input("{$index}.id"); // Get ID from request
                    $account = getAccountUser()->account_id;
                    $phone = \App\Models\Phone::where(['title' => $value, 'account_id' => $account])->first();
                    if ($phone) {
                        $isUnique = \App\Models\Phoneable::where('phone_id', $phone->id)
                            ->where('phoneable_type', $phoneableType)
                            ->where('phoneable_id', $phoneable_id)
                            ->first();
                        if ($isUnique) {
                            $fail("A phone '$value' number already taken.");
                        }
                    }
                },
            ],
            '*.phones.*.phone_type_id' => 'exists:phone_types,id|max:255',
            '*.addresses.*.title' => 'max:255',
            '*.addresses.*.city_id' => 'exists:cities,id|max:255',
            '*.image' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $accounts = collect($requests->except("_method"))->map(function ($request) {
            $account_only = collect($request)->only('id', 'name', 'statut');
            $account = Account::find($account_only['id']);
            $account->update($account_only->all());
            if (isset($request['phones'])) {
                $request_phone = new Request($request['phones']);
                $phone = PhoneController::store($request_phone, $local = 1, $account);
            }

            if (isset($request['addresses'])) {
                $request_address = new Request($request['addresses']);
                $address = AddressController::store($request_address, $local = 1, $account);
            }

            if (isset($request['image'])) {
                $imageData = [
                    'title' => $account->title,
                    'type' => 'account',
                    'image' => $request['image']
                ];
                $brand_image = ImageController::store(new Request([$imageData]), $account);
            }

            $account = Account::with('images', 'phones', 'addresses')->find($account->id);
            return $account;
        });
        return response()->json([
            'statut' => 1,
            'data' => $accounts,
        ]);
    }


    public function destroy($id)
    {
        $account_b =  account::find($id);
        $account = account::find($id)->delete();
        return response()->json([
            'statut' => 1,
            'account' => $account_b,
        ]);
    }
}
