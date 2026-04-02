<?php

namespace App\Http\Controllers;

use App\Models\AccountCarrier;
use App\Models\City;
use App\Models\DefaultCarrier;
use Illuminate\Http\Request;
use App\Models\Carrier;
use App\Models\Image;
use Illuminate\Support\Facades\Validator;

class CarrierController extends Controller
{

    public function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated = [];
        $model = 'App\\Models\\Carrier';
        $request['inAccount'] = ['account_id', getAccountUser()->account_id];
        $filters = HelperFunctions::filterColumns($request, ['id', 'title']);
        //permet de récupérer la liste des regions inactive filtrés

        if (isset($request['cities']) && array_filter($request['cities'], function ($value) {
            return $value !== null;
        })) {
            $associated[] = [
                'model' => 'App\\Models\\City',
                'title' => 'cities',
                'search' => true,
                'column' => 'title',
                'foreignKey' => 'city_id',
                'pivot' => ['table' => 'carriers', 'column' => 'title', 'key' => 'id'],
                'select' => array_filter($request['cities'], function ($value) {
                    return $value !== null;
                }),
            ];
        } else {
            $associated[] = [
                'model' => 'App\\Models\\City',
                'title' => 'cities',
                'search' => false,
            ];
        }
        $associated[] = [
            'model' => 'App\\Models\\Address',
            'title' => 'addresses',
            'search' => true,
        ];
        $associated[] = [
            'model' => 'App\\Models\\Phone',
            'title' => 'phones',
            'search' => true,
        ];
        $associated[] = [
            'model' => 'App\\Models\\Images',
            'title' => 'images',
            'search' => false,
        ];
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'title'], true, $associated);
        $datas['data'] = collect($datas['data'])->map(function ($data) {
            $carrier = collect($data)->except(['cities', 'addresses', 'phones']);
            $carrier['image'] = ($data->images) ? $data->images->first : [];
            $carrier['addresses'] = collect($data->activeAddresses ?? $data->addresses ?? [])->map(function ($addresse) {
                return ["id" => $addresse->id, "title" => $addresse->title];
            });
            $carrier['phones'] = collect($data->activePhones ?? [])->map(function ($phone) {
                return ["id" => $phone->id, "title" => $phone->title, "phoneTypes" => $phone->phoneTypes];
            });
            $carrier['cities'] = collect($data->activeCities ?? [])->map(function ($city) {
                return [
                    "id"            => $city->id,
                    "title"         => $city->title,
                    "name"         => optional($city->pivot)->name,
                    "price"         => optional($city->pivot)->price,
                    "return"        => optional($city->pivot)->return,
                    "delivery_time" => optional($city->pivot)->delivery_time,
                ];
            });

            return $carrier;
        });
        return $datas;
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

    public static function store(Request $requests)
    {
        $phoneableType = "App\Models\Carrier";
        $validator = Validator::make($requests->except('_method'), [
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($requests) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('carrier', 'title', $value);
                    $account_id = getAccountUser()->account_id;
                    $titleModel = Carrier::where(['title' => $value])->where('account_id', $account_id)->first();
                    if ($titleModel) {
                        $fail("exist");
                    }
                },
            ],
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
            '*.phones.*.phoneTypes.*' => 'required|exists:phone_types,id',
            '*.addresses.*.title' => 'max:255',
            '*.addresses.*.city_id' => 'exists:cities,id|max:255',
            '*.email' => 'string',
            '*.trackinglink' => 'string',
            '*.autocode' => 'required|int',
            '*.statut' => 'required',
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
            '*.cities.*.id' => 'exists:cities,id|max:255',
            '*.cities.*.name' => 'string',
            '*.cities.*.price' => 'required|numeric',
            '*.cities.*.return' => 'required|numeric',
            '*.cities.*.delivery_time' => 'required|int',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $carriers = collect($requests->except('_method'))->map(function ($request) {
            $request["account_id"] = getAccountUser()->account_id;
            $request['code'] = DefaultCodeController::getAccountCode('Carrier', $request["account_id"]);
            $carrier_only = collect($request)->only('code', 'title', 'email', 'trackinglink', 'autocode', 'comment', 'statut', 'account_id');
            $carrier = Carrier::create($carrier_only->all());
            AccountCarrier::create([
                "carrier_id" => $carrier->id,
                "account_id" => $request["account_id"],
                "autocode" => $request["autocode"],
                "username" => isset($request["username"]) ? $request['username'] : null,
                "password" => isset($request["password"]) ? $request['password'] : null,
                "token" => isset($request["token"]) ? $request['token'] : null,
                "statut" => 1
            ]);
            if (isset($request['phones'])) {
                $request_phone = new Request($request['phones']);
                PhoneController::store($request_phone, $local = 1, $carrier);
            }

            if (isset($request['addresses'])) {
                $request_address = new Request($request['addresses']);
                AddressController::store($request_address, $local = 1, $carrier);
            }

            if (isset($request['cities'])) {
                foreach ($request['cities'] as $key => $cityData) {
                    $city = City::find($cityData['id']);
                    $price = ($cityData['price']) ? $cityData['price'] : 0;
                    $return = ($cityData['return']) ? $cityData['return'] : 0;
                    $delivery_time = ($cityData['delivery_time']) ? $cityData['delivery_time'] : 0;
                    $title = ($cityData['name']) ? $cityData['name'] : $city->title;
                    if ($city) {
                        $city->carriers()->syncWithoutDetaching([$carrier->id => ['name' => $title, 'price' => $price, 'return' => $return, 'delivery_time' => $delivery_time, 'statut' => 1, 'created_at' => now(), 'updated_at' => now()]]);
                        $city->save();
                    }
                }
            }

            if (isset($request['principalImage'])) {
                $image = Image::find($request['principalImage']);
                $image->images()->syncWithoutDetaching([
                    $carrier->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($request['newPrincipalImage'])) {

                $images[]["image"] = $request['newPrincipalImage'];
                $imageData = [
                    'title' => $carrier->title,
                    'type' => 'carrier',
                    'image_type_id' => 10,
                    'images' => $images
                ];
                ImageController::store(new Request([$imageData]), $carrier);
            }

            $carrier = Carrier::with('images', 'phones', 'addresses')->find($carrier->id);
            return $carrier;
        });
        return response()->json([
            'statut' => 1,
            'data' => $carriers,
        ]);
    }
    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $params = collect($request->query())->toArray();
        $data   = [];

        $carrier = Carrier::with(['images', 'addresses.city', 'activePhones.phoneTypes', 'defaultCarriers.city'])->find($id);

        if (!$carrier) {
            return response()->json([
                'statut' => 0,
                'data'   => 'not exist',
            ]);
        }

        if (isset($params['carrierInfo'])) {
            $carrierData                       = $carrier->only('id', 'title', 'email', 'trackinglink', 'autocode', 'comment', 'statut', 'created_at', 'updated_at', 'code');
            $carrierData['images']             = $carrier->images;
            $carrierData['addresses']          = $carrier->addresses;
            $carrierData['phones']             = $carrier->activePhones;
            
            $carrierData['cities'] = collect($carrier->activeCities ?? [])->map(function ($city) {
                return [
                    "id"            => $city->id,
                    "title"         => $city->title,
                    "name"         => optional($city->pivot)->name,
                    "price"         => optional($city->pivot)->price,
                    "return"        => optional($city->pivot)->return,
                    "delivery_time" => optional($city->pivot)->delivery_time,
                ];
            });
            $data['carrierInfo']['data']       = $carrierData;
        }

        $activeCityIds = $carrier->defaultCarriers->pluck('city_id')->toArray();

        if (isset($params['cities']['active'])) {
            $activeParams                   = $params['cities']['active'];
            $activeParams['whereArray']     = ['column' => 'id', 'values' => $activeCityIds];
            $result                         = FilterController::searchs(new Request($activeParams), 'App\\Models\\City', ['id', 'title'], true);
            $result['data']                 = collect($result['data'])->map(function ($city) use ($carrier) {
                $default = $city->defaultCarriers->where('carrier_id', $carrier->id)->first();
                if (!$default) {
                    return null;
                }
                return array_merge(
                    $city->only('id', 'title', 'statut', 'created_at', 'updated_at'),
                    [
                        'name'          => $default->name,
                        'price'         => $default->price,
                        'return'        => $default->return,
                        'delivery_time' => $default->delivery_time,
                    ]
                );
            })->filter()->values();
            $data['cities']['active'] = $result;
        }

        if (isset($params['cities']['inactive'])) {
            $inactiveParams                     = $params['cities']['inactive'];
            $inactiveParams['whereNotArray']    = ['column' => 'id', 'values' => $activeCityIds];
            $data['cities']['inactive']         = FilterController::searchs(new Request($inactiveParams), 'App\\Models\\City', ['id', 'title'], true);
        }

        if (isset($params['cities']['all'])) {
            $allParams          = $params['cities']['all'];
            $result             = FilterController::searchs(new Request($allParams), 'App\\Models\\City', ['id', 'title'], true);
            $defaultsByCity     = $carrier->defaultCarriers->keyBy('city_id');
            $result['data']     = collect($result['data'])->map(function ($city) use ($carrier, $defaultsByCity) {
                $default = $defaultsByCity->get($city->id);
                return array_merge(
                    $city->only('id', 'title', 'statut', 'created_at', 'updated_at'),
                    [
                        'name'          => $default ? $default->name          : null,
                        'price'         => $default ? $default->price         : null,
                        'return'        => $default ? $default->return        : null,
                        'delivery_time' => $default ? $default->delivery_time : null,
                    ]
                );
            })->values();
            $data['cities']['all'] = $result;
        }

        return response()->json([
            'statut' => 1,
            'data'   => $data,
        ]);
    }

    public function update(Request $requests, $id)
    {
        $phoneableType = "App\Models\Carrier";
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'exists:carriers,id|max:255',
            '*.title' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($requests) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('carrier', 'title', $value);
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $requests->input("{$index}.id"); // Get ID from request
                    $account_id = getAccountUser()->account_id;
                    $titleModel = Carrier::where('title', $value)->where('account_id', $account_id)->first();
                    $idModel = Carrier::where('id', $id)->where('account_id', $account_id)->first(); // Find model by ID
                    // Check if a country with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.phones.*.phone_type_id' => 'exists:phone_types,id|max:255',
            '*.addresses.*.title' => 'max:255',
            '*.addresses.*.city_id' => 'exists:cities,id|max:255',
            '*.autocode' => 'required|int',
            '*.comment' => 'string',
            '*.statut' => 'required',
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

            '*.citiesToActive.*.id' => 'exists:cities,id|max:255',
            '*.citiesToActive.*.name' => 'string',
            '*.citiesToActive.*.price' => 'required|numeric',
            '*.citiesToActive.*.return' => 'required|numeric',
            '*.citiesToActive.*.delivery_time' => 'required|int',
            '*.citiesToInactive.*' => 'exists:cities,id|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }
        $carriers = collect($requests->except("_method"))->map(function ($request) {
            $request["account_id"] = getAccountUser()->account_id;
            $carrier_only = collect($request)->only('id', 'title', 'statut');
            $carrier = Carrier::find($carrier_only['id']);
            $carrier->update($carrier_only->all());
            if (isset($request['phones'])) {
                foreach ($carrier->activePhones as $phone) {
                    $phone->carriers()->syncWithoutDetaching([
                        $carrier->id => ['updated_at' => now(), 'statut' => 0]
                    ]);
                }
                $request_phone = new Request($request['phones']);
                PhoneController::update($request_phone, $carrier->id, $local = 1, $carrier, 'carriers');
            }
            if (isset($request['addresses'])) {
                $request_address = new Request($request['addresses']);
                 AddressController::update($request_address, $carrier->id, 1, $carrier);
            }

            if (isset($request['citiesToInactive'])) {
                foreach ($request['citiesToInactive'] as $cityId) {
                    $defaultcity = DefaultCarrier::where(['city_id' => $cityId, 'carrier_id' => $carrier->id])->first();
                    if ($defaultcity) {
                        $defaultcity->update(['statut' => 0]);
                    }
                }
            }
            if (isset($request['citiesToActive'])) {
                foreach ($request['citiesToActive'] as $cityData) {
                    $city = City::find($cityData['id']);
                    $price = ($cityData['price']) ? $cityData['price'] : 0;
                    $return = ($cityData['return']) ? $cityData['return'] : 0;
                    $delivery_time = ($cityData['delivery_time']) ? $cityData['delivery_time'] : 0;
                    $title = ($cityData['name']) ? $cityData['name'] : $city->title;
                    if ($city) {
                        $city->carriers()->syncWithoutDetaching([$carrier->id => ['name' => $title, 'price' => $price, 'return' => $return, 'delivery_time' => $delivery_time, 'statut' => 1, 'created_at' => now(), 'updated_at' => now()]]);
                    }
                }
            }

            if (isset($request['principalImage'])) {
                $image = Image::find($request['principalImage']);
                $carrier->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($request['newPrincipalImage'])) {
                $images[]["image"] = $request['newPrincipalImage'];
                $imageData = [
                    'title' => $carrier->title,
                    'type' => 'carrier',
                    'image_type_id' => 10,
                    'images' => $images
                ];
                ImageController::store(new Request([$imageData]), $carrier);
            }

            $carrier = Carrier::with('images', 'phones', 'addresses')->find($carrier->id);
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
