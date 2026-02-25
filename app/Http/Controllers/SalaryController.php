<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AccountCompensation;
use App\Models\Compensationable;
use App\Models\AccountUser;
use App\Models\Compensation;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class SalaryController extends Controller
{
    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();

        $model = 'App\\Models\\AccountCompensation';
        $request['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
        $compensations = Compensation::where('compensation_type_id', 1)->get()->pluck('id')->toArray();
        $request['whereArray'] = ['column' => 'compensation_id', 'values' => $compensations];
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'code', 'title'], false, []);
        $salariesDatas = $datas->map(function ($data) {
            $salary = $data->only('id', 'code', 'title', 'description', 'created_at', 'updated_at', 'statut');
            $salary['type'] = $data->compensation->only('id', 'code', 'title');
            $salary['user'] = $data->accountUser->user->only('id', 'firstname', 'lastname');
            $salary['roles'] = $data->activeRoles->map(function ($role) {
                return $role->only('id', 'name');
            });
            $salary['users'] = $data->activeAccountUsers->map(function ($accountUser) {
                return $accountUser->user->only('id', 'firstname', 'lastname');
            });
            $salary['amount'] = $data->defaultSalary->first()->amount;
            $salary['effective_date'] = $data->defaultSalary->first()->effective_date;
            return $salary;
        });
        $filters = HelperFunctions::filterColumns($request, ['id', 'code', 'title']);
        return  HelperFunctions::getPagination($salariesDatas, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
    }

    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        if (isset($request['users']['inactive'])) {
            $account = getAccountUser()->account_id;
            $model = 'App\\Models\\User';
            $accountUsers = AccountUser::where('account_id', $account)->pluck('user_id');
            $request['users']['inactive']['whereArray'] = ['column' => 'id', 'values' => $accountUsers];
            $request['users']['inactive']['whereIn'][] = ['table' => 'accounts', 'column' => 'account_id', 'value' => $account];
            $datas = FilterController::searchs(new Request($request['users']['inactive']), $model, ['id', 'code', 'firstname', 'lastname'], false)->map(function ($user) use ($account) {
                $user->id = $user->accountUsers()->where(['account_id' => $account, 'statut' => 1])->first()->id;
                return $user;
            });
            $filters = HelperFunctions::filterColumns($request['users']['inactive'], ['id', 'code', 'firstname', 'lastname']);
            $data['users']['inactive'] = HelperFunctions::getPagination($datas, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['roles']['inactive'])) {
            $model = 'App\\Models\\Role';
            $request['roles']['inactive']['wheres'][] = ['column' => 'statut', 'value' => 1];
            $request['roles']['inactive']['wheres'][] = ['column' => 'role_type_id', 'value' => 2];
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
            '*.amount' => 'required|numeric',
            '*.statut' => 'int',
            '*.effective_date' => 'date',
            '*.compensation_id' => 'required|exists:compensations,id',
            '*.roles.*.id' => 'exists:roles,id|max:255',
            '*.roles.*.amount' => 'numeric',
            '*.roles.*.effective_date' => 'date',
            '*.users.*.id' => 'exists:account_user,id|max:255',
            '*.users.*.amount' => 'numeric',
            '*.users.*.effective_date' => 'date',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }
        $compensations = collect($requests->except('_method'))->map(function ($request) {
            $request['account_user_id'] = getAccountUser()->id;
            $request['title'] = Compensation::find($request['compensation_id'])->title;
            $request['code'] = DefaultCodeController::getAccountCode('Salary', getAccountUser()->account_id);
            $compensation_only = collect($request)->only('code', 'title', 'description', 'statut', 'compensation_id', 'account_user_id');
            $compensation = AccountCompensation::create($compensation_only->all());
            Compensationable::create([
                'account_compensation_id' => $compensation->id,
                "account_user_id" => $request['account_user_id'],
                "amount" => $request['amount'],
                "effective_date" => Carbon::parse($request['effective_date']),
            ]);
            if (isset($request['roles'])) {
                foreach ($request['roles'] as $key => $role) {
                    $compensation->roles()->syncWithoutDetaching([$role['id'] => [
                        "amount" => $role['amount'],
                        "account_user_id" => $request['account_user_id'],
                        "effective_date" => $request['effective_date'],
                        "effective_date" => Carbon::parse($role['effective_date']),
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            if (isset($request['users'])) {
                foreach ($request['users'] as $key => $user) {
                    $compensation->accountUsers()->syncWithoutDetaching([$user['id'] => [
                        "account_user_id" => $request['account_user_id'],
                        "amount" => $user['amount'],
                        "effective_date" => Carbon::parse($user['effective_date']),
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            $compensation = AccountCompensation::find($compensation->id)->with('roles', 'accountUsers.user');

            return $compensation;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $compensations,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $salary = AccountCompensation::find($id);
        if (!$salary)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        $salaryData = $salary;
        $data = [];
        if (isset($request['salaryInfo'])) {
            $salaryData['amount'] = $salary->defaultSalary->first()->amount;
            $salaryData['effective_date'] = $salary->defaultSalary->first()->effective_date;
            $salary->compensation;
            $data["salaryInfo"]['data'] = collect($salaryData)->except('default_compensation');
        }
        $salaryRoles = $salary->activeRoles->pluck('id')->toArray();
        $salaryAccountUsers = $salary->activeAccountUsers->pluck('user_id')->toArray();
        $account = getAccountUser()->account_id;
        if (isset($request['roles']['inactive'])) {
            $model = 'App\\Models\\Role';
            $associated = [];
            $request['roles']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $salaryRoles];
            $request['roles']['inactive']['wheres'][] = ['column' => 'role_type_id', 'value' => 2];
            $data['roles']['inactive'] = FilterController::searchs(new Request($request['roles']['inactive']), $model, ['id', 'name'], true, $associated);
        }
        if (isset($request['roles']['active'])) {
            $model = 'App\\Models\\Role';
            $associated = [];
            $request['roles']['active']['whereArray'] = ['column' => 'id', 'values' => $salaryRoles];
            $datas = FilterController::searchs(new Request($request['roles']['active']), $model, ['id', 'name'], false, $associated)->map(function ($role) use ($salary) {
                $activeCompensation = $role->activeCompensations->where('id', $salary->id)->first();
                $role['effective_date'] = $activeCompensation->pivot->effective_date;
                $role['amount'] = $activeCompensation->pivot->amount;
                return $role->only("id", "name", "guard_name", "created_at", "updated_at", "deleted_at", "statut", "role_type_id", "effective_date", "amount");
            });
            $filters = HelperFunctions::filterColumns($request['roles']['active'], ['id', 'code', 'name']);
            $data['roles']['active'] =  HelperFunctions::getPagination($datas, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        if (isset($request['users']['inactive'])) {
            $model = 'App\\Models\\User';
            $associated = [];
            $request['users']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $salaryAccountUsers];
            $request['users']['inactive']['whereIn'][] = ['table' => 'accounts', 'column' => 'account_id', 'value' => $account];
            $datas = FilterController::searchs(new Request($request['users']['inactive']), $model, ['id', 'code', 'firstname', 'lastname'], false)->map(function ($user) use ($account) {
                $user->id = $user->accountUsers()->where(['account_id' => $account, 'statut' => 1])->first()->id;
                return $user->only("id", "code", "name", "firstname", "lastname", "cin", "birthday", "email", "email_verified_at", "statut", "created_at", "updated_at", "deleted_at");
            });
            $filters = HelperFunctions::filterColumns($request['users']['inactive'], ['id', 'code', 'firstname', 'lastname']);
            $data['users']['inactive'] =  HelperFunctions::getPagination($datas, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['users']['active'])) {
            $model = 'App\\Models\\User';
            $associated = [];
            $request['users']['active']['whereArray'] = ['column' => 'id', 'values' => $salaryAccountUsers];
            $request['users']['active']['whereIn'][] = ['table' => 'accounts', 'column' => 'account_id', 'value' => $account];
            $datas = FilterController::searchs(new Request($request['users']['active']), $model, ['id', 'code', 'firstname', 'lastname'], false)->map(function ($user) use ($account, $salary) {
                $user->id = $user->accountUsers()->where(['account_id' => $account, 'statut' => 1])->first()->id;
                return $user->only("id", "code", "name", "firstname", "lastname", "cin", "birthday", "email", "email_verified_at", "statut", "created_at", "updated_at", "deleted_at", "role_type_id", "effective_date", "amount");
            });
            $filters = HelperFunctions::filterColumns($request['users']['active'], ['id', 'code', 'firstname', 'lastname']);
            $data['users']['active'] =  HelperFunctions::getPagination($datas, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }

    public function update(Request $requests, $id)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'required|exists:account_compensation,id',
            '*.amount' => 'required|numeric',
            '*.statut' => 'int',
            '*.effective_date' => 'date',
            '*.rolesToActive.*.id' => 'exists:roles,id|max:255',
            '*.rolesToActive.*.amount' => 'numeric',
            '*.rolesToActive.*.effective_date' => 'date',
            '*.usersToActive.*.id' => 'exists:account_user,id|max:255',
            '*.usersToActive.*.amount' => 'numeric',
            '*.usersToActive.*.effective_date' => 'date',
            '*.rolesToInactive.*' => 'exists:roles,id|max:255',
            '*.usersToInactive.*' => 'exists:users,id|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }
        $compensations = collect($requests->except('_method'))->map(function ($request) {
            $request['account_user_id'] = getAccountUser()->id;
            $compensation_only = collect($request)->only('statut');
            $compensation = AccountCompensation::find($request['id']);
            $oldereffectiveDate = Carbon::parse($compensation->defaultSalary->first()->effective_date);
            $neweffectiveDate = Carbon::parse($request['effective_date']);
            if (($oldereffectiveDate != $neweffectiveDate) || ($compensation->defaultSalary->first()->amount != $request['amount'])) {
                $defaultCompensationable = Compensationable::find($compensation->defaultSalary->first()->id);
                $defaultCompensationable->update(['statut' => 0]);
                $newCompensationable = Compensationable::create([
                    'account_compensation_id' => $compensation->id,
                    "account_user_id" => $request['account_user_id'],
                    "amount" => $request['amount'],
                    "effective_date" => Carbon::parse($request['effective_date']),
                ]);
            }
            $compensation->update($compensation_only->all());
            if (isset($request['rolesToActive'])) {
                foreach ($request['rolesToActive'] as $roleData) {
                    $isExist = Compensationable::where(['compensationable_type' => 'App\Models\Role', "compensationable_id" => $roleData['id'], 'account_compensation_id' => $compensation->id, "statut" => 1])->first();
                    if ($isExist)
                        $isExist->update(['statut' => 0]);
                    $compensation->roles()->attach($roleData['id'], [
                        "account_user_id" => $request['account_user_id'],
                        "amount" => $roleData['amount'],
                        "effective_date" => Carbon::parse($roleData['effective_date']),
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }
            if (isset($request['rolesToInactive'])) {
                foreach ($request['rolesToInactive'] as $roleId) {
                    $isExist = Compensationable::where(['compensationable_type' => 'App\Models\Role', "compensationable_id" => $roleId, 'account_compensation_id' => $compensation->id, "statut" => 1])->first();
                    if ($isExist)
                        $isExist->update(['statut' => 0]);
                }
            }
            if (isset($request['usersToActive'])) {
                foreach ($request['usersToActive'] as $accountUserData) {
                    $isExist = Compensationable::where(['compensationable_type' => 'App\Models\AccountUser', "compensationable_id" => $accountUserData['id'], 'account_compensation_id' => $compensation->id, "statut" => 1])->first();
                    if ($isExist)
                        $isExist->update(['statut' => 0]);
                    $compensation->accountUsers()->attach($accountUserData['id'], [
                        "account_user_id" => $request['account_user_id'],
                        "amount" => $accountUserData['amount'],
                        "effective_date" => Carbon::parse($accountUserData['effective_date']),
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }
            if (isset($request['usersToInactive'])) {
                foreach ($request['usersToInactive'] as $accountUserId) {
                    $isExist = Compensationable::where(['compensationable_type' => 'App\Models\AccountUser', "compensationable_id" => $accountUserId, 'account_compensation_id' => $compensation->id, "statut" => 1])->first();
                    if ($isExist)
                        $isExist->update(['statut' => 0]);
                }
            }
            $compensation = AccountCompensation::find($compensation->id);
            return $compensation;
        });

        return response()->json([
            'statut' => 1,
            'data' => $compensations,
        ]);
    }



    public function destroy($id)
    {
        $compensation = AccountCompensation::find($id);
        $compensation->delete();
        return response()->json([
            'statut' => 1,
            'data' => $compensation,
        ]);
    }
}
