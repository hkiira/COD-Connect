<?php


namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use App\Models\AccountUser;
use App\Models\Warehouse;
use App\Models\Pickup;
use App\Models\Carrier;
use App\Models\Collector;
use Illuminate\Support\Facades\Validator;

class CashDrawerController extends Controller
{
    public function index(Request $request)
    {
        $searchIds = [];
        $request = collect($request->query())->toArray();
        if (isset($request['carriers']) && array_filter($request['carriers'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['carriers'] as $carrier) {
                if (Carrier::find($carrier))
                    $searchIds = array_merge($searchIds, Carrier::find($carrier)->pickups->pluck('id')->unique()->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        if (isset($request['users']) && array_filter($request['users'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['users'] as $carrier) {
                if (Carrier::find($carrier))
                    $searchIds = array_merge($searchIds, Carrier::find($carrier)->pickups->pluck('id')->unique()->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        if (isset($request['warehouses']) && array_filter($request['warehouses'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['warehouses'] as $warehouseId) {
                if (Warehouse::find($warehouseId))
                    $searchIds = array_merge($searchIds, Warehouse::find($warehouseId)->pickups->pluck('id')->unique()->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        $associated = [];
        $model = 'App\\Models\\Shipment';
        $request['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
        $datas = collect(FilterController::searchs(new Request($request), $model, ['id', 'code'], true, $associated)['data'])->map(function ($shipment) {
            $shipmentData = $shipment;
            $shipment->carrier->images;
            $shipmentData['user'] = [
                "id" => $shipment->accountUser->id,
                "firstname" => $shipment->accountUser->user->firstname,
                "lastname" => $shipment->accountUser->user->lastname,
                "images" => $shipment->accountUser->user->images,
            ];
            $shipmentData['carrier'] = $shipment->carrier;
            $shipmentData['warehouse'] = $shipment->warehouse->only('id', 'title');
            $shipmentData['collector'] = ($shipment->collector_id) ? $shipment->collector->only('id', 'name') : null;
            $shipmentData['count'] = 30;
            $shipmentData['Total'] = 15000;
            $shipmentData['shipping'] = 1200;
            $shipmentData['analytics'] = ['canceled' => 10, 'delivred' => 20, 'shipped' => 15];
            return $shipmentData;
        });
        return  $datas;
    }

    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        if (isset($request['orders']['inactive'])) {
            $model = 'App\\Models\\Order';
            $request['orders']['inactive']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $datas['data'] = collect(FilterController::searchs(new Request($request), $model, ['id', 'code'], true, [])['data'])->map(function ($data) {
                $orderData = $data->only('id', 'code', 'comment', 'order_id', 'created_at');

                $orderData['user'] = $data->userCreated->map(function ($user) {
                    return [
                        "id" => $user->id,
                        "firstname" => $user->user->firstname,
                        "lastname" => $user->user->lastname,
                        "images" => $user->user->images,
                    ];
                });
                $orderData['comments'] = $data->lastOrderComments->map(function ($comment) {
                    return [
                        "id" => $comment->id,
                        "title" => $comment->title,
                        "user" => $comment->accountUser->user,
                        "status" => $comment->orderStatus,
                    ];
                });
                $orderData['customer'] = $data->customer->only('id', 'name', 'images');
                $orderData['customer']['phones'] = $data->phones->map(function ($phone) {
                    return $phone->only('id', 'title');
                });
                $orderData['customer']['address'] = $data->addresses->map(function ($address) {
                    return $address->only('id', 'title', 'city');
                });
                $total = 0;
                $orderData['products'] = $data->productVariationAttributes->map(function ($pva) use (&$total) {
                    $total += $pva->pivot->price * $pva->pivot->quantity;
                    $productInfo = [
                        'id' => $pva->id,
                        'price' => $pva->pivot->price,
                        'quantity' => $pva->pivot->quantity,
                        'images' => $pva->product->images,
                        'productType' => $pva->product->productType,
                        'product' => $pva->product->title,
                        'reference' => $pva->product->reference,
                        'attributes' => $pva->variationAttribute->childVariationAttributes->map(function ($child) {
                            return [
                                "id" => $child->attribute->id,
                                "title" => $child->attribute->title,
                                "typeAttribute" => $child->attribute->typeAttribute->title,
                            ];
                        })
                    ];
                    return $productInfo;
                });
                $orderData['total'] = $total;
                $orderData['discount'] = 0;
                $orderData['brand'] = $data->brandSource->brand->only('id', 'title', 'images');
                $orderData['source'] = $data->brandSource->source->only('id', 'title', 'images');

                return $orderData;
            });
            $data['orders']['inactive'] =  $datas;
        }

        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }
    public function validatePickup(Request $request, $pickup, $canceled = 0)
    {

        $comment = ($pickup->statut == 1) ? ['id' => 48, "title" => "En préparation"] : ['id' => 29, "title" => "En cours"];
        $pickupId = $pickup->id;
        if ($canceled == 1) {
            $comment = collect($request)->map(function ($orderId) {
                $order = Order::find($orderId);
                $lastComment = $order->orderComments()->whereNotIn('order_status_id', [5, 6, 7, 8, 9, 10, 11])->orderByDesc('created_at')->first();
                return ['id' => $lastComment->comment_id, "title" => "Retirer d'un bon de sortie"];
            })->first();
            $pickupId = null;
        }
        $datas = array_values(array_map(function ($value) use ($pickupId, $comment) {
            return [
                'id' => $value, 'pickup_id' => $pickupId,
                'comment' => $comment
            ];
        }, $request->toArray(), array_keys($request->toArray())));
        $orders = OrderController::update(new Request($datas));
        return $orders;
    }
    public function store(Request $requests)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.carrier_id' => [ // Validate title field
                'required', // Title is required
                function ($attribute, $value, $fail) { // Custom validation rule
                    $titleModel = Carrier::where(['id' => $value])->first();
                    if (!$titleModel) {
                        $fail("not exist");
                    }
                },
            ],
            '*.collector_id' => [ // Validate title field
                function ($attribute, $value, $fail) { // Custom validation rule
                    $titleModel = Collector::where(['id' => $value])->first();
                    if (!$titleModel) {
                        $fail("not exist");
                    }
                },
            ],

            '*.orders.*' => [
                'required', 'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $order = Order::where(['id' => $value, 'account_id' => $account, 'pickup_id' => null])->first();
                    if (!$order) {
                        $fail("not exist");
                    }
                },
            ],
            '*.warehouse_id' => [
                'required', 'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$warehouse) {
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
        $pickups = collect($requests->except('_method'))->map(function ($request) {

            $request["account_user_id"] = getAccountUser()->id;
            $account_id = getAccountUser()->account_id;
            $request['code'] = DefaultCodeController::getAccountCode('Pickup', $account_id);
            $pickup_only = collect($request)->only('code', 'title', 'warehouse_id', 'comment', 'carrier_id', 'statut', 'account_user_id');
            $pickup = Pickup::create($pickup_only->all());
            if (isset($request['orders'])) {
                $this->validatePickup(new Request($request['orders']), $pickup);
            }

            $pickup = Pickup::with('orders')->find($pickup->id);
            return $pickup;
        });
        return response()->json([
            'statut' => 1,
            'data' => $pickups,
        ]);
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        $pickup = Pickup::with(['warehouse', 'carrier.phones.PhoneTypes', 'collector', 'accountUser.user'])->find($id);
        if (!$pickup)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        if (isset($request['pickupInfo'])) {
            $data["pickupInfo"]['data'] = $pickup;
        }
        if (isset($request['orders']['active'])) {
            $datas = [];
            $model = 'App\\Models\\Order';
            //permet de récupérer la liste des regions active filtrés
            $filters = HelperFunctions::filterColumns($request['orders']['active'], ['id', 'code']);
            $request['orders']['active']['where'] = ["column" => 'pickup_id', "value" => $pickup->id];
            $datas['data'] = collect(FilterController::searchs(new Request($request['orders']['active']), $model, ['id', 'code'], true, [])['data'])->map(function ($data) {
                $orderData = $data->only('id', 'code', 'pickup_id', 'comment', 'order_id', 'created_at');

                $orderData['user'] = $data->userCreated->map(function ($user) {
                    return [
                        "id" => $user->id,
                        "firstname" => $user->user->firstname,
                        "lastname" => $user->user->lastname,
                        "images" => $user->user->images,
                    ];
                });
                $orderData['comments'] = $data->lastOrderComments->map(function ($comment) {
                    return [
                        "id" => $comment->id,
                        "title" => $comment->title,
                        "user" => $comment->accountUser->user,
                        "status" => $comment->orderStatus,
                    ];
                });
                $orderData['customer'] = $data->customer->only('id', 'name', 'images');
                $orderData['customer']['phones'] = $data->phones->map(function ($phone) {
                    return $phone->only('id', 'title');
                });
                $orderData['customer']['address'] = $data->addresses->map(function ($address) {
                    return $address->only('id', 'title', 'city');
                });
                $total = 0;
                $orderData['products'] = $data->productVariationAttributes->map(function ($pva) use (&$total) {
                    $total += $pva->pivot->price * $pva->pivot->quantity;
                    $productInfo = [
                        'id' => $pva->id,
                        'price' => $pva->pivot->price,
                        'quantity' => $pva->pivot->quantity,
                        'images' => $pva->product->images,
                        'productType' => $pva->product->productType,
                        'product' => $pva->product->title,
                        'reference' => $pva->product->reference,
                        'attributes' => $pva->variationAttribute->childVariationAttributes->map(function ($child) {
                            return [
                                "id" => $child->attribute->id,
                                "title" => $child->attribute->title,
                                "typeAttribute" => $child->attribute->typeAttribute->title,
                            ];
                        })
                    ];
                    return $productInfo;
                });
                $orderData['total'] = $total;
                $orderData['discount'] = 0;
                $orderData['brand'] = $data->brandSource->brand->only('id', 'title', 'images');
                $orderData['source'] = $data->brandSource->source->only('id', 'title', 'images');

                return $orderData;
            });
            $data['orders']['active'] =  $datas;
        }
        if (isset($request['orders']['inactive'])) {
            $datas = [];
            $model = 'App\\Models\\Order';
            //permet de récupérer la liste des regions inactive filtrés
            $filters = HelperFunctions::filterColumns($request['orders']['inactive'], ['id', 'code']);
            $request['orders']['inactsive']['inAccountUser'] = ['account_suser_id', getAccountUser()->account_id];
            $request['orders']['inactive']['where'] = ["column" => 'pickup_id', "value" => null];
            $datas = FilterController::searchs(new Request($request['orders']['inactive']), $model, ['id', 'code'], false, [])->map(function ($data) {
                $orderData = $data->only('id', 'code', 'comment', 'pickup_id', 'order_id', 'created_at');

                $orderData['user'] = $data->userCreated->map(function ($user) {
                    return [
                        "id" => $user->id,
                        "firstname" => $user->user->firstname,
                        "lastname" => $user->user->lastname,
                        "images" => $user->user->images,
                    ];
                });
                $orderData['comments'] = $data->lastOrderComments->map(function ($comment) {
                    return [
                        "id" => $comment->id,
                        "title" => $comment->title,
                        "user" => $comment->accountUser->user,
                        "status" => $comment->orderStatus,
                    ];
                });
                $orderData['customer'] = $data->customer->only('id', 'name', 'images');
                $orderData['customer']['phones'] = $data->phones->map(function ($phone) {
                    return $phone->only('id', 'title');
                });
                $orderData['customer']['address'] = $data->addresses->map(function ($address) {
                    return $address->only('id', 'title', 'city');
                });
                $total = 0;
                $orderData['products'] = $data->productVariationAttributes->map(function ($pva) use (&$total) {
                    $total += $pva->pivot->price * $pva->pivot->quantity;
                    $productInfo = [
                        'id' => $pva->id,
                        'price' => $pva->pivot->price,
                        'quantity' => $pva->pivot->quantity,
                        'images' => $pva->product->images,
                        'productType' => $pva->product->productType,
                        'product' => $pva->product->title,
                        'reference' => $pva->product->reference,
                        'attributes' => $pva->variationAttribute->childVariationAttributes->map(function ($child) {
                            return [
                                "id" => $child->attribute->id,
                                "title" => $child->attribute->title,
                                "typeAttribute" => $child->attribute->typeAttribute->title,
                            ];
                        })
                    ];
                    return $productInfo;
                });
                $orderData['total'] = $total;
                $orderData['discount'] = 0;
                $orderData['brand'] = $data->brandSource->brand->only('id', 'title', 'images');
                $orderData['source'] = $data->brandSource->source->only('id', 'title', 'images');

                return $orderData;
            });
            $data['orders']['inactive'] =  HelperFunctions::getPagination($datas, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }


    public function update(Request $requests)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => [ // Validate title field
                'required', // Title is required
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $account_id = getAccountUser()->account_id;
                    $accountUsers = AccountUser::where(['account_id' => $account_id, 'statut' => 1])->get()->pluck('id')->toArray();
                    $titleModel = Pickup::where(['id' => $value])->whereIn('account_user_id', $accountUsers)->first();
                    if (!$titleModel) {
                        $fail("not exist");
                    }
                    // elseif($titleModel->statut==2){
                    //     $fail("not authorized");
                    // }
                },
            ],
            '*.carrier_id' => [ // Validate title field
                'required', // Title is required
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $account_id = getAccountUser()->account_id;
                    $titleModel = Carrier::where(['id' => $value])->where('account_id', $account_id)->first();
                    if (!$titleModel) {
                        $fail("not exist");
                    }
                },
            ],
            '*.warehouse_id' => [
                'required', 'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$warehouse) {
                        $fail("not exist");
                    }
                },
            ],
            '*.ordersToActive.*' => [
                'required', 'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $phone = Order::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$phone) {
                        $fail("not exist");
                    }
                },
            ],
            '*.ordersToInactive.*' => [
                'required', 'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $phone = Order::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$phone) {
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
        $pickups = collect($requests->except('_method'))->map(function ($request) {

            $pickup_only = collect($request)->only('id', 'collector_id', 'warehouse_id', 'carrier_id', 'title', 'comment', 'statut');
            $pickup = Pickup::find($pickup_only['id']);
            $pickup->update($pickup_only->all());
            if (isset($request['ordersToInactive'])) {
                return $this->validatePickup(new Request($request['ordersToInactive']), $pickup, $canceled = 1);
            }
            if (isset($request['ordersToActive'])) {
                $this->validatePickup(new Request($request['ordersToActive']), $pickup);
            }

            $pickup = Pickup::with('orders')->find($pickup->id);
            return $pickup;
        });
        return response()->json([
            'statut' => 1,
            'data' => $pickups,
        ]);
    }


    public function destroy($id)
    {
        $pickup = Pickup::find($id);
        $pickup->delete();
        return response()->json([
            'statut' => 1,
            'data' => $pickup,
        ]);
    }
}
