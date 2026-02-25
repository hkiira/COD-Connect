<?php

namespace App\Http\Controllers;

use App\Models\AccountCompensation;
use Illuminate\Http\Request;
use App\Models\Commission;
use App\Models\Commissionable;
use App\Models\AccountUser;
use App\Models\Compensation;
use App\Models\Compensationable;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class CommissionController extends Controller
{
    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $model = 'App\\Models\\AccountCompensation';
        $request['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
        $compensations = Compensation::where('compensation_type_id', 2)->get()->pluck('id')->toArray();
        $request['whereArray'] = ['column' => 'compensation_id', 'values' => $compensations];
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'code', 'title'], false, []);
        $commissionDatas = $datas->map(function ($data) {
            $commissions = $data->only('id', 'code', 'title', 'description', 'created_at', 'updated_at', 'statut');
            $commissions['type'] = $data->compensation->only('id', 'code', 'title');
            $commissions['user'] = $data->accountUser->user->only('id', 'firstname', 'lastname');
            $commissions['roles'] = $data->roles->map(function ($role) {
                return $role->only('id', 'name');
            });
            $commissions['users'] = $data->accountUsers->map(function ($accountUser) {
                return $accountUser->user->only('id', 'firstname', 'lastname');
            });
            $commissions['products'] = $data->products->map(function ($product) {
                return $product->only('id', 'title');
            });
            $commissions['attributes'] = $data->attributes->map(function ($attribute) {
                return $attribute->only('id', 'title');
            });
            /*
            $commissions['productVariationAttributes'] = $data->productVariationAttributes->map(function ($pva) {

                return $pva->only('id', 'firstname', 'lastname');
            });*/
            $commissions['brands'] = $data->brands->map(function ($brand) {
                return $brand->only('id', 'title');
            });
            $commissions['sources'] = $data->sources->map(function ($source) {
                return $source->only('id', 'title');
            });
            /*
            $commissions['brandSources'] = $data->brandSources->map(function ($brandSource) {
                return $brandSource->only('id', 'title');
            });*/
            $commissions['statuses'] = $data->orderStatuses->map(function ($orderStatus) {
                return $orderStatus->only('id', 'title');
            });
            $commissions['warehouses'] = $data->warehouses->map(function ($warehouse) {
                return $warehouse->only('id', 'title');
            });
            $commissions['taxonomies'] = $data->taxonomies->map(function ($taxonomy) {
                return $taxonomy->only('id', 'title');
            });
            $commissions['customerTypes'] = $data->customerTypes->map(function ($customerType) {
                return $customerType->only('id', 'title');
            });
            $commissions['countries'] = $data->countries->map(function ($country) {
                return $country->only('id', 'title');
            });
            $commissions['regions'] = $data->cities->map(function ($region) {
                return $region->only('id', 'title');
            });
            $commissions['cities'] = $data->cities->map(function ($city) {
                return $city->only('id', 'title');
            });
            $commissions['sectors'] = $data->sectors->map(function ($sector) {
                return $sector->only('id', 'title');
            });
            return $commissions;
        });
        $filters = HelperFunctions::filterColumns($request, ['id', 'code', 'title']);
        return  HelperFunctions::getPagination($commissionDatas, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
    }

    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        if (isset($request['brands']['inactive'])) {
            $model = 'App\\Models\\Brand';
            $associated[] = [
                'model' => 'App\\Models\\Source',
                'title' => 'sources',
                'search' => true,
            ];
            $request['brands']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $data['brands']['inactive'] = FilterController::searchs(new Request($request['brands']['inactive']), $model, ['id', 'title'], true, $associated);
        }
        if (isset($request['sources']['inactive'])) {
            $model = 'App\\Models\\Source';
            $request['sources']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $data['sources']['inactive'] = FilterController::searchs(new Request($request['sources']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['roles']['inactive'])) {
            $model = 'App\\Models\\Role';
            $request['roles']['inactive']['wheres'][] = ['column' => 'statut', 'value' => 1];
            $request['roles']['inactive']['wheres'][] = ['column' => 'role_type_id', 'value' => 2];
            $data['roles']['inactive'] = FilterController::searchs(new Request($request['roles']['inactive']), $model, ['id', 'name'], true);
        }
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
        if (isset($request['customerTypes']['inactive'])) {
            $model = 'App\\Models\\CustomerType';
            $request['customerTypes']['inactive']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $data['customerTypes']['inactive'] = FilterController::searchs(new Request($request['customerTypes']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['customers']['inactive'])) {
            $model = 'App\\Models\\Customer';
            $request['customers']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $data['customers']['inactive'] = FilterController::searchs(new Request($request['customers']['inactive']), $model, ['id', 'name'], true);
        }
        if (isset($request['cities']['inactive'])) {
            $model = 'App\\Models\\City';
            $data['cities']['inactive'] = FilterController::searchs(new Request($request['cities']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['regions']['inactive'])) {
            $model = 'App\\Models\\Region';
            $data['regions']['inactive'] = FilterController::searchs(new Request($request['regions']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['countries']['inactive'])) {
            $model = 'App\\Models\\Country';
            $data['countries']['inactive'] = FilterController::searchs(new Request($request['countries']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['warehouses']['inactive'])) {
            $model = 'App\\Models\\Warehouse';
            $request['warehouses']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $data['warehouses']['inactive'] = FilterController::searchs(new Request($request['warehouses']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['products']['inactive'])) {
            $model = 'App\\Models\\Product';
            $request['products']['inactive']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $data['products']['inactive'] = FilterController::searchs(new Request($request['products']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['categories']['inactive'])) {
            $model = 'App\\Models\\Taxonomy';
            $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
            $categories = $model::whereNull('taxonomy_id')->with('childTaxonomies')->where('type_taxonomy_id', 1)->whereIn('account_user_id', $accountUsers)->get();
            $formattedCategories = [];
            foreach ($categories as $category) {
                $formattedCategories[] = TaxonomyController::formatTaxonomy($category);
            }
            $filters = HelperFunctions::filterColumns($request['categories']['inactive'], ['id', 'code', 'title']);
            $data['categories']['inactive'] =  HelperFunctions::getPagination(collect($formattedCategories), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }

    public function store(Request $requests)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.title' => [
                'required',
                'max:255',
                function ($attribute, $value, $fail) {
                    $user = getAccountUser()->account_id;
                    $accountUsers = AccountUser::where(['account_id' => $user, 'statut' => 1])->get()->pluck('id')->toArray();
                    $hasTitle = AccountCompensation::where('title', $value)
                        ->whereIn('account_user_id', $accountUsers)
                        ->exists();

                    if ($hasTitle) {
                        $fail("exist");
                    }
                },
            ],
            '*.commissions.*.amount' => 'required|numeric',
            '*.commissions.*.commission' => 'required|numeric',
            '*.commissions.*.comparison_operator_id' => 'required|exists:comparison_operators,id',
            '*.compensation_goal_id' => 'required|exists:compensation_goals,id',
            '*.statut' => 'int',
            '*.start_date' => 'nullable|date',
            '*.end_date' => 'nullable|date',
            '*.compensation_id' => 'required|exists:compensations,id',
            '*.categories.*' => 'exists:taxonomies,id|max:255',
            '*.products.*' => 'exists:products,id|max:255',
            '*.productVariationAttributes.*' => 'exists:product_variation_attribute,id|max:255',
            '*.warehouses.*' => 'exists:warehouses,id|max:255',
            '*.countries.*' => 'exists:countries,id|max:255',
            '*.regions.*' => 'exists:regions,id|max:255',
            '*.cities.*' => 'exists:cities,id|max:255',
            '*.brands.*' => 'exists:brands,id|max:255',
            '*.customers.*' => 'exists:customers,id|max:255',
            '*.customerTypes.*' => 'exists:customer_types,id|max:255',
            '*.brandSources.*' => 'exists:brand_source,id|max:255',
            '*.sources.*' => 'exists:sources,id|max:255',
            '*.statuses.*' => 'exists:order_statuses,id|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }
        $commissions = collect($requests->except('_method'))->map(function ($request) {
            $request['account_user_id'] = getAccountUser()->id;
            $request['code'] = DefaultCodeController::getAccountCode('Commission', getAccountUser()->account_id);
            $commission_only = collect($request)->only('code', 'title', 'description', 'statut', 'compensation_id', 'compensation_goal_id', 'account_user_id');
            $commission = AccountCompensation::create($commission_only->all());
            if (isset($request['commissions'])) {
                foreach ($request['commissions'] as $key => $commissionData) {
                    Compensationable::create([
                        'account_compensation_id' => $commission->id,
                        "account_user_id" => $request['account_user_id'],
                        "comparison_operator_id" => $commissionData['comparison_operator_id'],
                        "amount" => $commissionData['amount'],
                        "start_date" => $request['start_date'],
                        "end_date" => $request['end_date'],
                        "commission" => $commissionData['commission'],
                    ]);
                }
            }

            if (isset($request['warehouses'])) {
                foreach ($request['warehouses'] as $key => $warehouseId) {
                    $commission->warehouses()->syncWithoutDetaching([$warehouseId => [
                        "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['categories'])) {
                foreach ($request['categories'] as $key => $taxonomyId) {
                    $commission->taxonomies()->syncWithoutDetaching([$taxonomyId => [
                        "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['statuses'])) {
                foreach ($request['statuses'] as $key => $taxonomyId) {
                    $commission->orderStatuses()->syncWithoutDetaching([$taxonomyId => [
                        "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['countries'])) {
                foreach ($request['countries'] as $key => $countryId) {
                    $commission->countries()->syncWithoutDetaching([$countryId => [
                        "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['regions'])) {
                foreach ($request['regions'] as $key => $regionId) {
                    $commission->regions()->syncWithoutDetaching([$regionId => [
                        "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['cities'])) {
                foreach ($request['cities'] as $key => $cityId) {
                    $commission->cities()->syncWithoutDetaching([$cityId => [
                        "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['brands'])) {
                foreach ($request['brands'] as $key => $brandId) {
                    $commission->brands()->syncWithoutDetaching([$brandId => [
                        "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['sources'])) {
                foreach ($request['sources'] as $key => $sourceId) {
                    $commission->sources()->syncWithoutDetaching([$sourceId => [
                        "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['brandSources'])) {
                foreach ($request['brandSources'] as $key => $brandSourceId) {
                    $commission->brandSources()->syncWithoutDetaching([$brandSourceId => [
                        "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['customers'])) {
                foreach ($request['customers'] as $key => $customerId) {
                    $commission->customers()->syncWithoutDetaching([$customerId => [
                        "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['customerTypes'])) {
                foreach ($request['customerTypes'] as $key => $customerTypeId) {
                    $commission->customerTypes()->syncWithoutDetaching([$customerTypeId => [
                        "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['productVariationAttributes'])) {
                foreach ($request['productVariationAttributes'] as $key => $productVariationAttributeId) {
                    $commission->productVariationAttributes()->syncWithoutDetaching([$productVariationAttributeId => [
                        "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['products'])) {
                foreach ($request['products'] as $key => $productId) {
                    $commission->products()->syncWithoutDetaching([$productId => [
                        "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['roles'])) {
                foreach ($request['roles'] as $key => $roleId) {
                    $commission->roles()->syncWithoutDetaching([$roleId => [
                        "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            $commission = AccountCompensation::find($commission->id);

            return $commission;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $commissions,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $commission = AccountCompensation::find($id);
        if (!$commission)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        $commissionBrands = $commission->activeBrands->pluck('id')->toArray();
        $commissionSources = $commission->activeSources->pluck('id')->toArray();
        $commissionCustomerTypes = $commission->activeCustomerTypes->pluck('id')->toArray();
        $commissionCustomers = $commission->activeCustomers->pluck('id')->toArray();
        $commissionCities = $commission->activeCities->pluck('id')->toArray();
        $commissionRegions = $commission->activeRegions->pluck('id')->toArray();
        $commissionCountries = $commission->activeCountries->pluck('id')->toArray();
        $commissionWarehouses = $commission->activeWarehouses->pluck('id')->toArray();
        $commissionProducts = $commission->activeProducts->pluck('id')->toArray();
        $commissionCategories = $commission->activeTaxonomies->pluck('id')->toArray();
        $commissionRoles = $commission->activeRoles->pluck('id')->toArray();
        $commissionAccountUsers = $commission->activeAccountUsers->pluck('user_id')->toArray();
        $account = getAccountUser()->account_id;
        $data = [];
        if (isset($request['commissionInfo'])) {
            $commissionData = $commission->only("id", "code", "title", "statut");
            $commissionData['amount'] = $commission->defaultCompensations->first()->amount;
            $commissionData['start_date'] = $commission->defaultCompensations->first()->start_date;
            $commissionData['end_date'] = $commission->defaultCompensations->first()->end_date;
            $commissionData['compensation_goal'] = $commission->compensationGoal->only("id", "title");
            $commissionData['compensation'] = $commission->compensation->only("id", "title");
            $commissionData['statuses'] = $commission->activeOrderStatuses->map(function ($status) {
                return $status->only('id', 'title');
            });
            $commissionData['commissions'] = $commission->defaultCompensations->map(function ($commission) {
                $comData = $commission->only('id', 'amount', 'commission');
                $comData['comparison_operator'] = $commission->comparisonOperator->only('id', 'symbol', 'title');
                return $comData;
            });
            $data["commissionInfo"]['data'] = $commissionData;
        }

        if (isset($request['roles']['inactive'])) {
            $model = 'App\\Models\\Role';
            $associated = [];
            $request['roles']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $commissionRoles];
            $request['roles']['inactive']['where'] = ['column' => 'role_type_id', 'value' => 2];
            $data['roles']['inactive'] = FilterController::searchs(new Request($request['roles']['inactive']), $model, ['id', 'name'], true, $associated);
        }
        if (isset($request['roles']['active'])) {
            $model = 'App\\Models\\Role';
            $associated = [];
            $request['roles']['active']['whereArray'] = ['column' => 'id', 'values' => $commissionRoles];
            $datas = FilterController::searchs(new Request($request['roles']['active']), $model, ['id', 'name'], false, $associated)->map(function ($role) use ($commission) {
                $activeCompensation = $role->activeCompensations->where('id', $commission->id)->first();
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
            $request['users']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $commissionAccountUsers];
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
            $request['users']['active']['whereArray'] = ['column' => 'id', 'values' => $commissionAccountUsers];
            $request['users']['active']['whereIn'][] = ['table' => 'accounts', 'column' => 'account_id', 'value' => $account];
            $datas = FilterController::searchs(new Request($request['users']['active']), $model, ['id', 'code', 'firstname', 'lastname'], false)->map(function ($user) use ($account, $commission) {
                $user->id = $user->accountUsers()->where(['account_id' => $account, 'statut' => 1])->first()->id;
                return $user->only("id", "code", "name", "firstname", "lastname", "cin", "birthday", "email", "email_verified_at", "statut", "created_at", "updated_at", "deleted_at", "role_type_id", "effective_date", "amount");
            });
            $filters = HelperFunctions::filterColumns($request['users']['active'], ['id', 'code', 'firstname', 'lastname']);
            $data['users']['active'] =  HelperFunctions::getPagination($datas, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['brands']['inactive'])) {
            $model = 'App\\Models\\Brand';
            $associated[] = [
                'model' => 'App\\Models\\Source',
                'title' => 'sources',
                'search' => true,
            ];
            $request['brands']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['brands']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $commissionBrands];
            $data['brands']['inactive'] = FilterController::searchs(new Request($request['brands']['inactive']), $model, ['id', 'title'], true, $associated);
        }
        if (isset($request['brands']['active'])) {
            $model = 'App\\Models\\Brand';
            $associated = [];
            $associated[] = [
                'model' => 'App\\Models\\Source',
                'title' => 'sources',
                'search' => true,
            ];

            $request['brands']['active']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['brands']['active']['whereArray'] = ['column' => 'id', 'values' => $commissionBrands];
            $data['brands']['active'] = FilterController::searchs(new Request($request['brands']['active']), $model, ['id', 'title'], true, $associated);
        }

        if (isset($request['sources']['inactive'])) {
            $model = 'App\\Models\\Source';
            $request['sources']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['sources']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $commissionSources];
            $data['sources']['inactive'] = FilterController::searchs(new Request($request['sources']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['sources']['active'])) {
            $model = 'App\\Models\\Source';
            $request['sources']['active']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['sources']['active']['whereArray'] = ['column' => 'id', 'values' => $commissionSources];
            $data['sources']['active'] = FilterController::searchs(new Request($request['sources']['active']), $model, ['id', 'title'], true);
        }

        if (isset($request['customerTypes']['inactive'])) {
            $model = 'App\\Models\\CustomerType';
            $request['customerTypes']['inactive']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $request['customerTypes']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $commissionCustomerTypes];
            $data['customerTypes']['inactive'] = FilterController::searchs(new Request($request['customerTypes']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['customerTypes']['active'])) {
            $model = 'App\\Models\\CustomerType';
            $request['customerTypes']['active']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $request['customerTypes']['active']['whereArray'] = ['column' => 'id', 'values' => $commissionCustomerTypes];
            $data['customerTypes']['active'] = FilterController::searchs(new Request($request['customerTypes']['active']), $model, ['id', 'title'], true);
        }

        if (isset($request['customers']['inactive'])) {
            $model = 'App\\Models\\Customer';
            $request['customers']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['customers']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $commissionCustomers];
            $data['customers']['inactive'] = FilterController::searchs(new Request($request['customers']['inactive']), $model, ['id', 'name'], true);
        }
        if (isset($request['customers']['active'])) {
            $model = 'App\\Models\\Customer';
            $request['customers']['active']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['customers']['active']['whereArray'] = ['column' => 'id', 'values' => $commissionCustomers];
            $data['customers']['active'] = FilterController::searchs(new Request($request['customers']['active']), $model, ['id', 'name'], true);
        }

        if (isset($request['cities']['inactive'])) {
            $model = 'App\\Models\\City';
            $request['cities']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $commissionCities];
            $data['cities']['inactive'] = FilterController::searchs(new Request($request['cities']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['cities']['active'])) {
            $model = 'App\\Models\\City';
            $request['cities']['active']['whereArray'] = ['column' => 'id', 'values' => $commissionCities];
            $data['cities']['active'] = FilterController::searchs(new Request($request['cities']['active']), $model, ['id', 'title'], true);
        }

        if (isset($request['regions']['inactive'])) {
            $model = 'App\\Models\\Region';
            $request['regions']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $commissionRegions];
            $data['regions']['inactive'] = FilterController::searchs(new Request($request['regions']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['regions']['active'])) {
            $model = 'App\\Models\\Region';
            $request['regions']['active']['whereArray'] = ['column' => 'id', 'values' => $commissionRegions];
            $data['regions']['active'] = FilterController::searchs(new Request($request['regions']['active']), $model, ['id', 'title'], true);
        }

        if (isset($request['countries']['inactive'])) {
            $model = 'App\\Models\\Country';
            $request['countries']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $commissionCountries];
            $data['countries']['inactive'] = FilterController::searchs(new Request($request['countries']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['countries']['active'])) {
            $model = 'App\\Models\\Country';
            $request['countries']['active']['whereArray'] = ['column' => 'id', 'values' => $commissionCountries];
            $data['countries']['active'] = FilterController::searchs(new Request($request['countries']['active']), $model, ['id', 'title'], true);
        }

        if (isset($request['warehouses']['inactive'])) {
            $model = 'App\\Models\\Warehouse';
            $request['warehouses']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['warehouses']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $commissionWarehouses];
            $data['warehouses']['inactive'] = FilterController::searchs(new Request($request['warehouses']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['warehouses']['active'])) {
            $model = 'App\\Models\\Warehouse';
            $request['warehouses']['active']['whereArray'] = ['column' => 'id', 'values' => $commissionWarehouses];
            $request['warehouses']['active']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $data['warehouses']['active'] = FilterController::searchs(new Request($request['warehouses']['active']), $model, ['id', 'title'], true);
        }

        if (isset($request['products']['inactive'])) {
            $model = 'App\\Models\\Product';
            $request['products']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $commissionProducts];
            $request['products']['inactive']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $data['products']['inactive'] = FilterController::searchs(new Request($request['products']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['products']['active'])) {
            $model = 'App\\Models\\Product';
            $request['products']['active']['whereArray'] = ['column' => 'id', 'values' => $commissionProducts];
            $request['products']['active']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $data['products']['active'] = FilterController::searchs(new Request($request['products']['active']), $model, ['id', 'title'], true);
        }

        if (isset($request['categories']['inactive'])) {
            $model = 'App\\Models\\Taxonomy';
            $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
            $categories = $model::whereNull('taxonomy_id')->with('childTaxonomies')->where('type_taxonomy_id', 1)->whereIn('account_user_id', $accountUsers)->whereNotIn('id', $commissionCategories)->get();
            $formattedCategories = [];
            foreach ($categories as $category) {
                $formattedCategories[] = TaxonomyController::formatTaxonomy($category);
            }
            $data['categories']['inactive'] =  HelperFunctions::getPagination(collect($formattedCategories), $request['categories']['inactive']['pagination']['per_page'], $request['categories']['inactive']['pagination']['current_page']);
        }
        if (isset($request['categories']['active'])) {
            $model = 'App\\Models\\Taxonomy';
            $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
            $categories = $model::whereNull('taxonomy_id')->with('childTaxonomies')->where('type_taxonomy_id', 1)->whereIn('account_user_id', $accountUsers)->whereIn('id', $commissionCategories)->get();
            $formattedCategories = [];
            foreach ($categories as $category) {
                $formattedCategories[] = TaxonomyController::formatTaxonomy($category);
            }
            $data['categories']['active'] =  HelperFunctions::getPagination(collect($formattedCategories), $request['categories']['active']['pagination']['per_page'], $request['categories']['inactive']['pagination']['current_page']);
        }
        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }

    public function update(Request $requests, $id)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'required|exists:compensations,id',
            '*.statut' => 'required|int',
            '*.end_date' => [
                'date',
                function ($attribute, $value, $fail) {
                    $toDayDate = Carbon::now();
                    $endDate = Carbon::parse($value);

                    if ($endDate->lt($toDayDate)) {
                        $fail("not authorized");
                    }
                },
            ],
            '*.rolestoActive.*' => 'exists:roles,id|max:255',
            '*.rolesToInactive.*' => 'exists:roles,id|max:255',
            '*.statusestoActive.*' => 'exists:order_statuses,id|max:255',
            '*.statusesToInactive.*' => 'exists:order_statuses,id|max:255',
            '*.categoriesToActive.*' => 'exists:taxonomies,id|max:255',
            '*.categoriesToInactive.*' => 'exists:taxonomies,id|max:255',
            '*.productsToActive.*' => 'exists:products,id|max:255',
            '*.productsToInactive.*' => 'exists:products,id|max:255',
            '*.productVariationAttributesToActive.*' => 'exists:product_variation_attribute,id|max:255',
            '*.productVariationAttributesToInactive.*' => 'exists:product_variation_attribute,id|max:255',
            '*.warehousesToActive.*' => 'exists:warehouses,id|max:255',
            '*.warehousesToInactive.*' => 'exists:warehouses,id|max:255',
            '*.countriesToActive.*' => 'exists:countries,id|max:255',
            '*.countriesToInactive.*' => 'exists:countries,id|max:255',
            '*.regionsToActive.*' => 'exists:regions,id|max:255',
            '*.regionsToInactive.*' => 'exists:regions,id|max:255',
            '*.citiesToActive.*' => 'exists:cities,id|max:255',
            '*.citiesToInactive.*' => 'exists:cities,id|max:255',
            '*.brandsToActive.*' => 'exists:brands,id|max:255',
            '*.brandsToInactive.*' => 'exists:brands,id|max:255',
            '*.customersToActive.*' => 'exists:customers,id|max:255',
            '*.customersToInactive.*' => 'exists:customers,id|max:255',
            '*.customerTypesToActive.*' => 'exists:customer_types,id|max:255',
            '*.customerTypesToInactive.*' => 'exists:customer_types,id|max:255',
            '*.brandSourcesToActive.*' => 'exists:brand_source,id|max:255',
            '*.brandSourcesToInactive.*' => 'exists:brand_source,id|max:255',
            '*.giftsToActive.*' => 'exists:products,id|max:255',
            '*.giftsToInactive.*' => 'exists:products,id|max:255',
            '*.sourcesToActive.*' => 'exists:sources,id|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $commissions = collect($requests->except('_method'))->map(function ($request) {
            $request['account_user_id'] = getAccountUser()->id;
            $commission_only = collect($request)->only('title', 'statut', 'end_date');
            $commission = AccountCompensation::find($request['id']);
            $commission->update($commission_only->all());
            if (isset($request['end_date'])) {
                foreach ($commission->defaultCompensations as $defaultCompensation) {
                    $defaultCompensation->update(['end_date' => $request['end_date']]);
                }
            }
            if (isset($request['rolesToInactive'])) {
                foreach ($request['rolesToInactive'] as $key => $roleId) {
                    $commission->roles()->syncWithoutDetaching([$roleId => [
                        'statut' => 0, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            if (isset($request['rolesToActive'])) {
                foreach ($request['rolesToActive'] as $key => $roleId) {
                    $commission->roles()->syncWithoutDetaching([$roleId => [
                        'statut' => 1, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['usersToInactive'])) {
                foreach ($request['usersToInactive'] as $key => $userId) {
                    $commission->accountUsers()->syncWithoutDetaching([$userId => [
                        'statut' => 0, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            if (isset($request['usersToActive'])) {
                foreach ($request['usersToActive'] as $key => $userId) {
                    $commission->accountUsers()->syncWithoutDetaching([$userId => [
                        'statut' => 1, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['statusesToInactive'])) {
                foreach ($request['statusesToInactive'] as $key => $statutId) {
                    $commission->orderStatuses()->syncWithoutDetaching([$statutId => [
                        'statut' => 0, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            if (isset($request['statusesToActive'])) {
                foreach ($request['statusesToActive'] as $key => $statutId) {
                    $commission->orderStatuses()->syncWithoutDetaching([$statutId => [
                        'statut' => 1, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['warehousesToInactive'])) {
                foreach ($request['warehousesToInactive'] as $key => $warehouseId) {
                    $commission->warehouses()->syncWithoutDetaching([$warehouseId => [
                        'statut' => 0, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            if (isset($request['warehousesToActive'])) {
                foreach ($request['warehousesToActive'] as $key => $warehouseId) {
                    $commission->warehouses()->syncWithoutDetaching([$warehouseId => [
                        'statut' => 1, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['categoriesToInactive'])) {
                foreach ($request['categoriesToInactive'] as $key => $taxonomyId) {
                    $commission->taxonomies()->syncWithoutDetaching([$taxonomyId => [
                        'statut' => 0, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            if (isset($request['categoriesToActive'])) {
                foreach ($request['categoriesToActive'] as $key => $taxonomyId) {
                    $commission->taxonomies()->syncWithoutDetaching([$taxonomyId => [
                        'statut' => 1, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['countriesToInactive'])) {
                foreach ($request['countriesToInactive'] as $key => $countryId) {
                    $commission->countries()->syncWithoutDetaching([$countryId => [
                        'statut' => 0, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            if (isset($request['countriesToActive'])) {
                foreach ($request['countriesToActive'] as $key => $countryId) {
                    $commission->countries()->syncWithoutDetaching([$countryId => [
                        'statut' => 1, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['regionsToInactive'])) {
                foreach ($request['regionsToInactive'] as $key => $regionId) {
                    $commission->regions()->syncWithoutDetaching([$regionId => [
                        'statut' => 0, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            if (isset($request['regionsToActive'])) {
                foreach ($request['regionsToActive'] as $key => $regionId) {
                    $commission->regions()->syncWithoutDetaching([$regionId => [
                        'statut' => 1, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['citiesToInactive'])) {
                foreach ($request['citiesToInactive'] as $key => $cityId) {
                    $commission->cities()->syncWithoutDetaching([$cityId => [
                        'statut' => 0, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            if (isset($request['citiesToActive'])) {
                foreach ($request['citiesToActive'] as $key => $cityId) {
                    $commission->cities()->syncWithoutDetaching([$cityId => [
                        'statut' => 1, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['brandsToInactive'])) {
                foreach ($request['brandsToInactive'] as $key => $brandId) {
                    $commission->brands()->syncWithoutDetaching([$brandId => [
                        'statut' => 0, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            if (isset($request['brandsToActive'])) {
                foreach ($request['brandsToActive'] as $key => $brandId) {
                    $commission->brands()->syncWithoutDetaching([$brandId => [
                        'statut' => 1, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['sourcesToInactive'])) {
                foreach ($request['sourcesToInactive'] as $key => $sourceId) {
                    $commission->sources()->syncWithoutDetaching([$sourceId => [
                        'statut' => 0, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            if (isset($request['sourcesToActive'])) {
                foreach ($request['sourcesToActive'] as $key => $sourceId) {
                    $commission->sources()->syncWithoutDetaching([$sourceId => [
                        'statut' => 1, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['brandSourcesToInactive'])) {
                foreach ($request['brandSourcesToInactive'] as $key => $brandSourceId) {
                    $commission->brandSources()->syncWithoutDetaching([$brandSourceId => [
                        'statut' => 0, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            if (isset($request['brandSourcesToActive'])) {
                foreach ($request['brandSourcesToActive'] as $key => $brandSourceId) {
                    $commission->brandSources()->syncWithoutDetaching([$brandSourceId => [
                        'statut' => 1, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['customersToInactive'])) {
                foreach ($request['customersToInactive'] as $key => $customerId) {
                    $commission->customers()->syncWithoutDetaching([$customerId => [
                        'statut' => 0, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            if (isset($request['customersToActive'])) {
                foreach ($request['customersToActive'] as $key => $customerId) {
                    $commission->customers()->syncWithoutDetaching([$customerId => [
                        'statut' => 1, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['customerTypesToInactive'])) {
                foreach ($request['customerTypesToInactive'] as $key => $customerTypeId) {
                    $commission->customerTypes()->syncWithoutDetaching([$customerTypeId => [
                        'statut' => 0, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            if (isset($request['customerTypesToActive'])) {
                foreach ($request['customerTypesToActive'] as $key => $customerTypeId) {
                    $commission->customerTypes()->syncWithoutDetaching([$customerTypeId => [
                        'statut' => 1, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['productVariationAttributesToInactive'])) {
                foreach ($request['productVariationAttributesToInactive'] as $key => $productVariationAttributeId) {
                    $commission->productVariationAttributes()->syncWithoutDetaching([$productVariationAttributeId => [
                        'statut' => 0, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            if (isset($request['productVariationAttributesToActive'])) {
                foreach ($request['productVariationAttributesToActive'] as $key => $productVariationAttributeId) {
                    $commission->productVariationAttributes()->syncWithoutDetaching([$productVariationAttributeId => [
                        'statut' => 1, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['productsToInactive'])) {
                foreach ($request['productsToInactive'] as $key => $productId) {
                    $commission->products()->syncWithoutDetaching([$productId => [
                        'statut' => 0, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            if (isset($request['productsToActive'])) {
                foreach ($request['productsToActive'] as $key => $productId) {
                    $commission->products()->syncWithoutDetaching([$productId => [
                        'statut' => 1, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            if (isset($request['giftsToInactive'])) {
                foreach ($request['giftsToInactive'] as $key => $productId) {
                    $commission->products()->syncWithoutDetaching([$productId => [
                        'statut' => 0, 'gift' => 1, 'product_id' => $productId, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }
            if (isset($request['giftsToActive'])) {
                foreach ($request['giftsToActive'] as $key => $productId) {
                    $commission->products()->syncWithoutDetaching([$productId => [
                        'statut' => 1, 'gift' => 1, 'product_id' => $productId, 'account_user_id' => getAccountUser()->id, "account_user_id" => $request['account_user_id'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]]);
                }
            }

            $commission = AccountCompensation::find($commission->id);

            return $commission;
        });

        return response()->json([
            'statut' => 1,
            'data' => $commissions,
        ]);
    }



    public function destroy($id)
    {
        $commission = AccountCompensation::find($id);
        $commission->delete();
        return response()->json([
            'statut' => 1,
            'data' => $commission,
        ]);
    }
}
