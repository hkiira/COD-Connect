<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\HelperFunctions;
use App\Models\Account;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\AccountUser;
use App\Models\Image;
use App\Models\Role;
use App\Models\Permission;
use App\Models\DefaultCode;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{

    public static function index(Request $request, $local = 0, $columns = ['id', 'code', 'name', 'statut', 'account_user_id', 'firstname', 'lastname', 'email', 'cin', 'created_at', 'addresses', 'phones', 'warehouses', 'permissions', 'roles', 'images'], $paginate = true)
    {

        $request = $request->toArray();
        $searchUsers = [];
        $account = getAccountUser()->account_id;
        if (isset($request['roles']) && array_filter($request['roles'], function ($value) {
            return $value !== null;
        })) {
            $roles = Role::whereIn('id', $request['roles'])->get();
            $searchUsers = array_merge($searchUsers, $roles->flatMap(function ($role) {
                return $role->accountUsers->map(function ($accountUser) {
                    return $accountUser->user->id ?? null;
                });
            })->filter()->values()->toArray());
        }
        if (isset($request['permissions']) && array_filter($request['permissions'], function ($value) {
            return $value !== null;
        })) {
            $permission = Permission::whereIn('id', $request['permissions'])->get();
            $searchUsers = array_merge($searchUsers, $permission->flatMap(function ($permission) {
                return $permission->accountUsers->map(function ($accountUser) {
                    return $accountUser->user->id ?? null;
                });
            })->filter()->values()->toArray());
        }
        if (isset($request['warehouses']) && array_filter($request['warehouses'], function ($value) {
            return $value !== null;
        })) {
            $warehouses = Warehouse::whereIn('id', $request['warehouses'])->get();
            $searchUsers = array_merge($searchUsers, $warehouses->flatMap(function ($warehouse) {
                return $warehouse->accountUsers->map(function ($accountUser) {
                    return $accountUser->user->id ?? null;
                });
            })->filter()->values()->toArray());
        }
        $filters = HelperFunctions::filterColumns($request, $columns);
        $model = 'App\\Models\\User';

        $request['whereIn'][0] = ['table' => 'accounts', 'column' => 'account_id', 'value' => $account];
        if ($searchUsers)
            $request['whereArray'] = ['column' => 'id', 'values' => $searchUsers];
        $users = FilterController::searchs(new Request($request), $model, ['id', 'code', 'name', 'statut', 'firstname', 'lastname', 'email', 'cin', 'created_at'], false, []);
        $users = $users->map(function ($user) {
            $userData = $user->only('id', 'code', 'name', 'statut', 'firstname', 'lastname', 'email', 'cin', 'created_at');
            $userData['images'] = $user->images->sortBy(['created_at', 'DESC']);
            $userData['id'] = $user->accountUsers->where('account_id', getAccountUser()->account_id)->first()->id;
            $userData['addresses'] = $user->addresses->map(function ($address) {
                $city = $address->city ? $address->city->title : "";
                $region = $address->city ? $address->city->region ? $address->city->region->title : "" : "";
                $country = $address->city ? $address->city->region ? $address->city->region->country ? $address->city->region->country->title : "" : "" : "";
                return [
                    'id' => $address->id,
                    'title' => $address->title,
                    'city' => $city,
                    'region' => $region,
                    'country' => $country
                ];
            });
            $userData['phones'] = $user->phones->map(function ($phone) {
                return [
                    'id' => $phone->id,
                    'title' => $phone->title,
                    'phoneTypes' => $phone->phoneTypes,
                ];
            });
            $userData['warehouses'] = $user->accountUsers->first()->activeWarehouses->map(function ($activeWarehouse) {
                return [
                    'id' => $activeWarehouse->id,
                    'title' => $activeWarehouse->title,
                ];
            });
            $userData['permissions'] = $user->accountUsers->first()->permissions;
            $userData['roles'] = $user->accountUsers->first()->roles;
            return $userData;
        });
        $dataPagination = HelperFunctions::getPagination($users, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        if ($local == 1) {
            if ($paginate == true) {
                return $dataPagination;
            } else {
                return $users->toArray();
            }
        }
        return $dataPagination;
    }

    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $data = [];

        if (isset($request['warehouses']['inactive'])) {
            $model = 'App\\Models\\Warehouse';
            //permet de récupérer la liste des regions inactive filtrés
            $request['warehouses']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['warehouses']['inactive']['where'] = ["column" => 'warehouse_type_id', "value" => 1];
            $data['warehouses']['inactive'] = FilterController::searchs(new Request($request['warehouses']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['roles']['inactive'])) {
            $model = 'App\\Models\\Role';
            //permet de récupérer la liste des regions inactive filtrés
            $data['roles']['inactive'] = FilterController::searchs(new Request($request['roles']['inactive']), $model, ['id', 'name'], true);
        }
        if (isset($request['permissions']['inactive'])) {
            $model = 'App\\Models\\Permission';
            //permet de récupérer la liste des regions inactive filtrés
            $data['permissions']['inactive'] = FilterController::searchs(new Request($request['permissions']['inactive']), $model, ['id', 'name'], true, [['model' => 'App\\Models\\Role', 'title' => 'roles', 'search' => false]]);
        }
        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }
    public static function store(Request $requests, $local = 0, $account = null)
    {
        if ($local == 0) {
            $phoneableType = "App\Models\User";
            $validator = Validator::make($requests->except('_method'), [
                '*.name' => 'unique:users,name|required|max:255',
                '*.firstname' => 'required|max:255',
                '*.lastname' => 'required|max:255',
                '*.email' => 'required|email|unique:users,email',
                '*.password' => 'required|min:8|confirmed',
                '*.password_confirmation' => 'required',
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
                                $fail("exist.");
                            }
                        }
                    },
                ],
                '*.phones.*.phoneTypes.*' => 'required|exists:phone_types,id',
                '*.addresses.*.title' => 'max:255',
                '*.addresses.*.city_id' => 'exists:cities,id|max:255',
                '*.warehouses.*' =>  [ // Validate title field
                    function ($attribute, $value, $fail) { // Custom validation rule
                        // Call the function to rename removed records
                        $warehouse = Warehouse::where(['id' => $value, 'warehouse_type_id' => 1])->first();
                        if ($warehouse == null) {
                            $fail("not exist");
                        } elseif ($warehouse->account_id !== getAccountUser()->account_id) {
                            $fail("not exist");
                        }
                    },
                ],
                '*.roles.*' => 'exists:roles,id|max:255',
                '*.permissions.*' => 'exists:permissions,id|max:255',
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
                    'statut' => 0,
                    'data' => $validator->errors(),
                ]);
            };
        }
        $users = collect($requests->except('_method'))->map(function ($request) use ($local, $account) {
            $accountId = ($local == 1) ? $account->id : getAccountUser()->account_id;
            $request['password'] = Hash::make($request['password']);
            $request['statut'] = 1;
            $request['code'] = DefaultCodeController::getCode('User');
            $user_only = collect($request)->only('name', 'created_at', 'updated_at', 'firstname', 'lastname', 'email', 'cin', 'birthday', 'password', 'statut', 'code');
            $user = User::create($user_only->all());
            $accountUser = AccountUser::create([
                "code" => $user->cin,
                "user_id" => $user->id,
                "account_id" => $accountId,
                "statut" => 1
            ]);
            if (isset($request['phones'])) {
                $request_phone = new Request($request['phones']);
                $phone = PhoneController::store($request_phone, $local = 1, $user);
            }

            if (isset($request['addresses'])) {
                $request_address = new Request($request['addresses']);
                $address = AddressController::store($request_address, $local = 1, $user);
            }

            if (isset($request['warehouses'])) {
                foreach ($request['warehouses'] as $key => $warehouseId) {
                    $warehouse = Warehouse::find($warehouseId);
                    $warehouse->accountUsers()->attach($accountUser, ['created_at' => now(), 'updated_at' => now()]);
                    $warehouse->save();
                }
            }

            if (isset($request['roles'])) {
                foreach ($request['roles'] as $key => $roleId) {
                    $role = Role::find($roleId); // Assuming you have the role's ID
                    $accountUser->assignRole($role);
                }
            }
            if (isset($request['permissions'])) {
                foreach ($request['permissions'] as $key => $permissionId) {
                    $permission = Permission::find($permissionId); // Assuming you have the role's ID
                    $accountUser->givePermissionTo($permission);
                }
            }


            if (isset($request['principalImage'])) {
                $image = Image::find($request['principalImage']);
                $user->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($request['newPrincipalImage'])) {
                $images[]["image"] = $request['newPrincipalImage'];
                $imageData = [
                    'title' => $user->name,
                    'type' => 'user',
                    'image_type_id' => 5,
                    'images' => $images
                ];
                ImageController::store(new Request([$imageData]), $user);
            }

            $user = User::with('images', 'phones', 'addresses')->find($user->id);
            return $user;
        });
        if ($local == 1)
            return $users;
        return response()->json([
            'statut' => 1,
            'data' => $users,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        $accountUser = AccountUser::with(['user.images', 'user.addresses.city', 'user.phones.PhoneTypes'])->find($id);

        if (!$accountUser)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        if (isset($request['userInfo'])) {
            $data["userInfo"]['data'] = $accountUser->user;
        }
        if (isset($request['warehouses']['active'])) {
            $model = 'App\\Models\\Warehouse';
            //permet de récupérer la liste des regions inactive filtrés
            $request['warehouses']['active']['whereIn'][0] = ['table' => 'accountUsers', 'column' => 'account_user_id', 'value' => $accountUser->id];
            $request['warehouses']['active']['where'] = ["column" => 'warehouse_type_id', "value" => 1];
            $data['warehouses']['active'] = FilterController::searchs(new Request($request['warehouses']['active']), $model, ['id', 'title'], true);
        }
        if (isset($request['warehouses']['inactive'])) {
            $model = 'App\\Models\\Warehouse';
            //permet de récupérer la liste des regions inactive filtrés
            $request['warehouses']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['warehouses']['inactive']['whereNotIn'][0] = ['table' => 'accountUsers', 'column' => 'account_user_id', 'value' => $accountUser->id];
            $request['warehouses']['inactive']['where'] = ["column" => 'warehouse_type_id', "value" => 1];
            $data['warehouses']['inactive'] = FilterController::searchs(new Request($request['warehouses']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['permissions']['active'])) {
            $model = 'App\\Models\\Permission';
            $request['permissions']['active']['whereArray'] = ['column' => 'id', 'values' => $accountUser->permissions->pluck('id')->toArray()];
            $data['permissions']['active'] = FilterController::searchs(new Request($request['permissions']['active']), $model, ['id', 'name'], true, [['model' => 'App\\Models\\Role', 'title' => 'roles', 'search' => false]]);
        }
        if (isset($request['permissions']['inactive'])) {
            $model = 'App\\Models\\Permission';
            $request['permissions']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $accountUser->permissions->pluck('id')->toArray()];
            $data['permissions']['inactive'] = FilterController::searchs(new Request($request['permissions']['inactive']), $model, ['id', 'name'], true, [['model' => 'App\\Models\\Role', 'title' => 'roles', 'search' => false]]);
        }
        if (isset($request['roles']['active'])) {
            $model = 'App\\Models\\Role';
            $request['roles']['active']['whereArray'] = ['column' => 'id', 'values' => $accountUser->roles->pluck('id')->toArray()];
            $data['roles']['active'] = FilterController::searchs(new Request($request['roles']['active']), $model, ['id', 'name'], true, [['model' => 'App\\Models\\Permission', 'title' => 'permissions', 'search' => false]]);
        }
        if (isset($request['roles']['inactive'])) {
            $model = 'App\\Models\\Role';
            $request['roles']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $accountUser->roles->pluck('id')->toArray()];
            $data['roles']['inactive'] = FilterController::searchs(new Request($request['roles']['inactive']), $model, ['id', 'name'], true, [['model' => 'App\\Models\\Permission', 'title' => 'permissions', 'search' => false]]);
        }


        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }

    public function update(Request $requests)
    {
        $phoneableType = "App\Models\User";
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'required|exists:account_user,id',
            '*.name' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($requests) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('user', 'name', $value);
                    $index = str_replace(['*', '.name'], '', $attribute);
                    $accountUserId = $requests->input("{$index}.id");
                    $userId = AccountUser::find($accountUserId)->user_id;
                    $nameModel = User::where('name', $value)->orderBy('created_at', 'desc')->first();
                    if ($nameModel && $userId != $nameModel->id) {
                        $fail("exist");
                    }
                },
            ],
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
            '*.warehousestoActive.*' => [ // Validate title field
                'string', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $warehouse = Warehouse::where(['id' => $value, 'warehouse_type_id' => 1])->first();
                    if ($warehouse == null) {
                        $fail("not exist");
                    } elseif ($warehouse->account_id !== getAccountUser()->account_id) {
                        $fail("not exist");
                    }
                },
            ],
            '*.warehousestoInactive.*' => [ // Validate title field
                'string', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $warehouse = Warehouse::where(['id' => $value, 'warehouse_type_id' => 1])->first();
                    if ($warehouse == null) {
                        $fail("not exist");
                    } elseif ($warehouse->account_id !== getAccountUser()->account_id) {
                        $fail("not exist");
                    }
                },
            ],
            '*.rolesToActive.*.id' => 'exists:roles,id|max:255',
            '*.rolesToInactive.*.id' => 'exists:roles,id|max:255',
            '*.permissionsToActive.*' => 'exists:permissions,id|max:255',
            '*.permissionsToInactive.*' => 'exists:permissions,id|max:255',
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
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $users = collect($requests->except("_method"))->map(function ($request) {
            $request["account_id"] = getAccountUser()->account_id;
            $user_only = collect($request)->only('id', 'name', 'firstname', 'lastname', 'cin', 'birthday', 'statut');
            $accountUser = AccountUser::where(['account_id' => getAccountUser()->account_id, 'id' => $user_only['id']])->first();
            $user = User::find($accountUser->user_id);
            $user->update($user_only->all());
            if (isset($request['phones'])) {
                $request_phone = new Request($request['phones']);
                PhoneController::store($request_phone, $local = 1, $user);
            }

            if (isset($request['addresses'])) {
                $request_address = new Request($request['addresses']);
                AddressController::store($request_address, $local = 1, $user);
            }

            if (isset($request['warehousesToInactive'])) {
                foreach ($request['warehousesToInactive'] as $key => $warehouseId) {
                    $warehouse = Warehouse::find($warehouseId);
                    $warehouse->accountUsers()->detach($accountUser);
                    $warehouse->save();
                }
            }
            if (isset($request['warehousesToActive'])) {
                foreach ($request['warehousesToActive'] as $key => $warehouseId) {
                    $warehouse = Warehouse::find($warehouseId);
                    $warehouse->accountUsers()->syncWithoutDetaching([$accountUser->id => ['created_at' => now(), 'updated_at' => now()]]);
                    $warehouse->save();
                }
            }

            if (isset($request['rolesToInactive'])) {
                foreach ($request['rolesToInactive'] as $key => $roleId) {
                    $role = Role::find($roleId); // Assuming you have the role's ID
                    $accountUser->removeRole($role);
                }
            }

            if (isset($request['rolesToActive'])) {
                foreach ($request['rolesToActive'] as $key => $roleId) {
                    $role = Role::find($roleId); // Assuming you have the role's ID
                    $accountUser->assignRole($role);
                }
            }

            if (isset($request['permissionsToInactive'])) {
                foreach ($request['permissionsToInactive'] as $key => $permissionId) {
                    $permission = Permission::find($permissionId); // Assuming you have the role's ID
                    $accountUser->revokePermissionTo($permission);
                }
            }

            if (isset($request['permissionsToActive'])) {
                foreach ($request['permissionsToActive'] as $key => $permissionId) {
                    $permission = Permission::find($permissionId); // Assuming you have the role's ID
                    $accountUser->givePermissionTo($permission);
                }
            }

            if (isset($request['principalImage'])) {
                $user->images()->detach($user->images->pluck('id')->toArray());
                $image = Image::find($request['principalImage']);
                $user->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($request['newPrincipalImage'])) {
                $images[]["image"] = $request['newPrincipalImage'];
                $imageData = [
                    'title' => $user->name,
                    'type' => 'user',
                    'image_type_id' => 5,
                    'images' => $images
                ];
                ImageController::store(new Request([$imageData]), $user);
            }

            $user = User::with('images', 'phones', 'addresses')->find($user->id);
            return $user;
        });
        return response()->json([
            'statut' => 1,
            'data' => $users,
        ]);
    }

    public function destroy($id)
    {
        $user = User::find($id);
        $user->delete();
        return response()->json([
            'statut' => 1,
            'data' => $user,
        ]);
    }
}
