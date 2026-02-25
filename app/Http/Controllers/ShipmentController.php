<?php


namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use App\Models\AccountUser;
use App\Models\Warehouse;
use App\Models\Pickup;
use App\Models\Shipment;
use App\Models\Carrier;
use App\Models\ShipmentType;
use Illuminate\Support\Facades\Validator;

class ShipmentController extends Controller
{
    public function index(Request $request)
    {
        // Clean, Eloquent-based index for Shipments, similar to OrderController
        $params = $request->all();
        $limit = isset($params['pagination']['per_page']) ? (int)$params['pagination']['per_page'] : 10;
        $page = isset($params['pagination']['current_page']) ? (int)$params['pagination']['current_page'] : 0;
        $sortBy = isset($params['sort']['column']) ? $params['sort']['column'] : 'created_at';
        $sortOrder = isset($params['sort']['order']) ? $params['sort']['order'] : 'desc';

        $query = Shipment::query();
        $query->where('account_user_id', getAccountUser()->id)
              ->whereHas('childShipments');

        // Filtering
        if (!empty($params['warehouses'])) {
            $query->whereIn('warehouse_id', $params['warehouses']);
        }
        if (!empty($params['carrier_id'])) {
            $query->where('carrier_id', $params['carrier_id']);
        }
        if (!empty($params['shipment_type_id'])) {
            $query->where('shipment_type_id', $params['shipment_type_id']);
        }
        if (!empty($params['status'])) {
            $query->whereIn('statut', $params['status']);
        }
        // Add startDate and endDate filters for created_at
        if (!empty($params['startDate'])) {
            $query->whereDate('created_at', '>=', $params['startDate']);
        }
        if (!empty($params['endDate'])) {
            $query->whereDate('created_at', '<=', $params['endDate']);
        }
        if (!empty($params['search'])) {
            $search = $params['search'];
            $query->where(function($q) use ($search) {
                $q->where('code', 'like', "%$search%")
                  ->orWhere('title', 'like', "%$search%")
                  ->orWhereHas('carrier', function($c) use ($search) {
                      $c->where('title', 'like', "%$search%") ;
                  })
                  ->orWhereHas('warehouse', function($w) use ($search) {
                      $w->where('title', 'like', "%$search%") ;
                  });
            });
        }

        $total = $query->count();
        $shipments = $query->orderBy($sortBy, $sortOrder)
            ->skip($page * $limit)
            ->take($limit)
            ->with(['carrier', 'warehouse', 'accountUser.user', 'shipmentType', 'childShipments.orders.orderPvas'])
            ->get();

        $data = $shipments->map(function ($shipment) {
            $orders = $shipment->childShipments->flatMap(function ($child) {
                return $child->orders;
            });
            $total = $orders->flatMap(function ($order) {
                return $order->orderPvas;
            })->reduce(function ($carry, $orderPva) {
                return $carry + ($orderPva->quantity * $orderPva->price);
            }, 0);
            $shipping = $orders->sum('real_carrier_price');
            $analytics = [
                'canceled' => $orders->whereIn('order_status_id', [5, 6])->count(),
                'delivred' => $orders->whereIn('order_status_id', [7, 10])->count(),
                'shipped' => $orders->whereIn('order_status_id', [8, 9, 11])->count(),
            ];
            return [
                'id' => $shipment->id,
                'code' => $shipment->code,
                'title' => $shipment->title,
                'user' => [
                    'id' => $shipment->accountUser->id,
                    'firstname' => $shipment->accountUser->user->firstname,
                    'lastname' => $shipment->accountUser->user->lastname,
                    'images' => $shipment->accountUser->user->images,
                ],
                'shipment_type' => [
                    'id' => $shipment->shipmentType->id,
                    'title' => $shipment->shipmentType->title,
                ],
                'carrier' => $shipment->carrier,
                'warehouse' => $shipment->warehouse ? $shipment->warehouse->only('id', 'title') : null,
                'count' => $orders->count(),
                'total' => $total,
                'shipping' => $shipping,
                'paid' => $total - $shipping,
                'analytics' => $analytics,
                'transactions' => $shipment->transactions,
            ];
        });

        return response()->json([
            'statut' => 1,
            'total' => $total,
            'data' => $data,
        ]);
    }
    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        if (isset($request['orders']['inactive'])) {
            $requestOrders = $request['orders']['inactive'];
            $filter=[];
            if (isset($requestOrders['pagination']) ) {
                $filter['limit']=isset($requestOrders['pagination']['per_page'])?$requestOrders['pagination']['per_page']:10;
                $filter['page']=isset($requestOrders['pagination']['current_page'])?$requestOrders['pagination']['current_page']:0;
                $filter['sort']['by']=isset($requestOrders['sort'][0]['column'])?$requestOrders['sort'][0]['column']:'created_at';
                $filter['sort']['order']=isset($requestOrders['sort'][0]['order'])?$requestOrders['sort'][0]['order']:'desc';
            }

            if(!isset($filter['limit'])) $filter['limit']=10;
            if(!isset($filter['page'])) $filter['page']=0;
            if(!isset($filter['sort']['by'])) $filter['sort']['by']='created_at';
            if(!isset($filter['sort']['order'])) $filter['sort']['order']='desc';
            $ordersQuery = Order::orderBy($filter['sort']['by'], $filter['sort']['order'])->where('account_id', getAccountUser()->account_id);


            // Add search filter for code, customer name, customer phone, and address title
            if (!empty($requestOrders['search']) && is_string($requestOrders['search'])) {
                $search = $requestOrders['search'];
                $ordersQuery = $ordersQuery->where(function ($query) use ($search) {
                    $query->where('code', 'like', "%$search%")->orWhere('shipping_code', 'like', "%$search%")
                        ->orWhereHas('customer', function ($q) use ($search) {
                            $q->where('name', 'like', "%$search%")
                                ->orWhereHas('phones', function ($q2) use ($search) {
                                    $q2->where('title', 'like', "%$search%") ;
                                })
                                ->orWhereHas('addresses', function ($q3) use ($search) {
                                    $q3->where('title', 'like', "%$search%") ;
                                });
                        });
                });
            }
            /*// Filter orders by carrier_id = 24 through pickup relationship
            if (isset($requestOrders['carrier'])) {
                $ordersQuery = $ordersQuery->whereHas('pickup', function ($q)use($requestOrders) {
                    $q->where('carrier_id', $requestOrders['carrier']);
                });
            }else{
                $ordersQuery = $ordersQuery->whereHas('pickup');
            }*/
            $total = $ordersQuery->count();
            $orders = $ordersQuery
                ->skip($filter['page'] * $filter['limit'])
                ->take($filter['limit'])
                ->get();

            $orderDatas = $orders->map(function ($data) {
                $orderData = $data->only('id', 'code','shipping_code', 'comment', 'pickup', 'order_id', 'real_carrier_price', 'created_at');
                if (!$orderData['shipping_code'])
                    $orderData['shipping_code'] = "";
                $orderData['can_change'] = in_array($data->order_status_id, [1, 2, 3, 4, 5]) ? true : false;
                $orderData['user'] = $data->userCreated->map(function ($user) {
                    return [
                        "id" => $user->id,
                        "firstname" => $user->user->firstname,
                        "lastname" => $user->user->lastname,
                        "images" => $user->user->images,
                    ];
                });
                $orderData['comments'] = $data->lastOrderComments()->where('type', 'comment')->get()->map(function ($comment) {
                    return [
                        "id" => $comment->id,
                        "title" => $comment->title,
                        "user" => $comment->accountUser->user,
                        "status" => $comment->orderStatus,
                    ];
                });
                $orderData['customer'] = $data->customer->only('id', 'name', 'images');
                $orderData['customer']['phones'] = $data->customer->phones->map(function ($phone) {
                    return $phone->only('id', 'title');
                });
                $orderData['customer']['address'] = $data->customer->addresses->map(function ($address) {
                    return $address->only('id', 'title', 'city');
                });
                $totalOrder = 0;
                $orderData['products'] = ($data->order_status_id==2 ? $data->inactiveOrderPvas : $data->activeOrderPvas)->map(function ($actfOrderPva) use (&$totalOrder) {
                    $totalOrder += $actfOrderPva->price * $actfOrderPva->quantity;
                    $attributes = $actfOrderPva->ProductVariationAttribute->variationAttribute->childVariationAttributes->map(function ($child) {
                        return $child->attribute->code;
                    })->toArray();
                    $productInfo = [
                        'id' => $actfOrderPva->productVariationAttribute->product->id,
                        'order_pva' => $actfOrderPva->id,
                        'price' => $actfOrderPva->price,
                        'quantity' => $actfOrderPva->quantity,
                        'images' => $actfOrderPva->productVariationAttribute->product->images->sortByDesc('created_at')->values(),
                        'productType' => $actfOrderPva->productVariationAttribute->product->productType,
                        'product' => $actfOrderPva->productVariationAttribute->product->title . " " . implode('-', $attributes),
                        'reference' => $actfOrderPva->productVariationAttribute->product->reference,
                        'attributes' => $actfOrderPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($child) {
                            return [
                                "id" => $child->attribute->id,
                                "title" => $child->attribute->title,
                                "typeAttribute" => $child->attribute->typeAttribute->title,
                            ];
                        }),
                    ];
                    return $productInfo;
                });
                $orderData['total'] = $totalOrder;
                $orderData['discount'] = $data->discount;
                $orderData['carrier_price'] = $data->carrier_price;
                $orderData['real_carrier_price'] = 30;
                $orderData['brand'] = $data->brandSource->brand->only('id', 'title', 'images');
                
                $orderData['source'] = $data->brandSource->source->only('id', 'title', 'images');
                return $orderData;
            });
            $data['orders']['inactive'] =  [
                'statut' => 1,
                'data' => $orderDatas,
                'per_page' => (string)($filter['limit'] ?? 10),
                'current_page' => (int)($filter['page'] ?? 0) + 1,
                'total' => $total,
            ];
        }
       
        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }
    public static function validateShip(Request $request, $shipment, $canceled = 0, $isRetour = 0)
    {
        $comment = ($isRetour == 1) ? ($shipment->statut == 1 ? ['id' => 53, "title" => "en attente de Validation"] : ['id' => 53, "title" => "Validée"]) : ($shipment->statut == 1 ? ['id' => 51, "title" => "en attente de Validation"] : ['id' => 51, "title" => "Validée"]);
        $shipmentId = $shipment->id;
        if ($canceled == 1) {
            $comment = collect($request)->map(function ($order) {
                $order = Order::find($order['id']);
                $lastComment = $order->orderComments()->whereNotIn('order_status_id', [5, 6, 7, 8, 9, 10, 11])->orderByDesc('created_at')->first();
                return ($lastComment) ? ['id' => $lastComment->comment_id, "title" => "Retirer d'un bon de sortie"] : ['id' => 29, "title" => 'En cours'];
            })->first();
            $shipmentId = null;
        }
        $datas = array_values(array_map(function ($order) use ($shipmentId, $comment) {
            return [
                'id' => $order['id'],
                'shipment_id' => $shipmentId,
                'real_carrier_price' => isset($order['carrier_price']) ? $order['carrier_price'] : 0,
                'comment' => $comment
            ];
        }, $request->toArray(), array_keys($request->toArray())));

        $orders = OrderController::update(new Request($datas), $local = 1);
        return $orders;
    }

    public static function store(Request $requests)
    {
        $warehouse = Warehouse::find(0);
        $validator = Validator::make($requests->except('_method'), [
            '*.carrier_id' => [ // Validate title field
                function ($attribute, $value, $fail) { // Custom validation rule
                    $titleModel = Carrier::where(['id' => $value])->first();
                    if (!$titleModel) {
                        $fail("not exist");
                    }
                },
            ],
            '*.shipment_type_id' => [ // Validate title field
                function ($attribute, $value, $fail) use ($requests) { // Custom validation rule
                    $index = str_replace(['*', '.shipment_type_id'], '', $attribute);
                    $carrierId = $requests->input("{$index}.carrier_id");
                    $isCarrier = 0;
                    if ($carrierId) {
                        $isCarrier = 1;
                    }
                    $idModel = ShipmentType::where(['id' => $value, 'is_carrier' => $isCarrier])->first();
                    if (!$idModel) {
                        $fail("not exist");
                    }
                },
            ],
            '*.warehouse_id' => [
                'required',
                'int',
                function ($attribute, $value, $fail) use (&$warehouse) {
                    $account = getAccountUser()->account_id;
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$warehouse) {
                        $fail("not exist");
                    }
                },
            ],
            '*.orders.*.id' => [
                'required',
                'int',
                /*function ($attribute, $value, $fail) use ($requests) {
                    //récupérer l'id dial carrier bach n verifie les commandes dialo
                    $index = str_replace(['*', '.orders'], '-', $attribute);
                    $requestIndex = explode("-", $index);
                    $carrierId = $requests->input("{$requestIndex[0]}.carrier_id");
                    $account = getAccountUser()->account_id;
                    $order = Order::where(['id' => $value, 'account_id' => $account, 'shipment_id' => null])->whereIn('pickup_id', Pickup::where('carrier_id', $carrierId)->get()->pluck('id')->toArray())->first();

                    if (!$order) {
                        $fail("not exist");
                    }
                },*/
            ],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $to_warehouse = $warehouse->childWarehouses()->where('warehouse_type_id', 2)->first()->childWarehouses()->where(['warehouse_nature_id' => 1, 'warehouse_type_id' => 3])->first();
        $shipments = collect($requests->except('_method'))->map(function ($request) use ($to_warehouse) {
            if ($request['carrier_id']) {
                $request["account_user_id"] = getAccountUser()->id;
                $account_id = getAccountUser()->account_id;
                $request['code'] = DefaultCodeController::getAccountCode('Shipment', $account_id);
                $shipment_only = collect($request)->only('code', 'title', 'warehouse_id', 'comment', 'carrier_id', 'statut', 'account_user_id', 'shipment_type_id', 'created_at', 'updated_at');
                $shipment = Shipment::create($shipment_only->all());
                $shipmentType = ShipmentType::find($request['shipment_type_id']);
                $shipmentChild = Shipment::create([
                    'code' => $shipment->code . $shipmentType->code,
                    'shipment_type_id' => $shipmentType->id,
                    'warehouse_id' => $shipment->warehouse_id,
                    'carrier_id' => $shipment->carrier_id,
                    'statut' => $shipment->statut,
                    'account_user_id' => $shipment->account_user_id,
                    'shipment_id' => $shipment->id,
                    'created_at' => $shipment->created_at,
                    'updated_at' => $shipment->updated_at,
                    'is_return' => ($shipmentType->id == 2) ? 1 : 0,
                ]);
                if (isset($request['orders'])) {
                    $orderPvas = self::validateShip(new Request($request['orders']), $shipmentChild, $canceled = 0, $isRetour = ($shipmentType->id == 2) ? 1 : 0);
                    if ($shipmentChild->statut == 1 && $shipment->shipment_type_id == 2) {
                        $productVariationAttributes = [];
                        $orderPvas->flatten()->Map(function ($orderPva) use (&$productVariationAttributes) {
                            if (isset($productVariationAttributes[$orderPva->product_variation_attribute_id])) {
                                $productVariationAttributes[$orderPva->product_variation_attribute_id]["quantity"] += $orderPva->quantity;
                            } else {
                                $productVariationAttributes[$orderPva->product_variation_attribute_id] = [
                                    "id" => $orderPva->product_variation_attribute_id,
                                    "quantity" => $orderPva->quantity,
                                ];
                            }
                            $productVariationAttributes[$orderPva->product_variation_attribute_id]['orders'][] = $orderPva->id;
                        });
                        $shipmentChildData[] = [
                            'to_warehouse' => $to_warehouse->id,
                            'statut' => 1,
                            'productVariationAttributes' => collect($productVariationAttributes)->values()->toArray()
                        ];
                        $return = ReturnController::store(new Request($shipmentChildData), $local = 1);
                        $shipmentChild->update(['mouvement_id' => $return->id]);
                    }
                    if ($shipmentChild->statut == 1 && $shipment->shipment_type_id == 1) {
                        $carrierTotal = 0;
                        $orderIds = [];
                        foreach ($request['orders'] as $order) {
                            $carrierTotal += $order['carrier_price'];
                            $orderIds[] = $order['id'];
                        }
                        $orders = Order::whereIn('id', $orderIds)->get();
                        $total = 0;
                        $orders->map(function ($data) use (&$total) {
                            $data->productVariationAttributes->map(function ($pva) use (&$total) {
                                $total += $pva->pivot->price * $pva->pivot->quantity;
                            });
                        });
                        $transactionData[] = [
                            "type" => "shipment",
                            "amount" => $total - $carrierTotal,
                            "transaction_type_id" => 3,
                            "transaction_id" => $shipment->id
                        ];
                        if (isset($request['given_amount']))
                            if ($request['given_amount'] > 0)
                                $transactionData[] = [
                                    "type" => "shipment",
                                    "amount" => $request['given_amount'],
                                    "transaction_type_id" => 1,
                                    "transaction_id" => $shipment->id
                                ];
                        TransactionController::store(new Request($transactionData));
                    }
                }
                $shipment = Shipment::with('childShipments.orders')->find($shipment->id);
                return $shipment;
            }
        });
        return response()->json([
            'statut' => 1,
            'data' => $shipments,
        ]);
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        $shipment = Shipment::with(['warehouse', 'carrier.phones.PhoneTypes', 'accountUser.user'])->find($id);
        if (!$shipment)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        if (isset($request['shipmentInfo'])) {
            $data["shipmentInfo"]['data']  = $shipment;
        }
        if (isset($request['orders']['active'])) {
            $requestOrders = $request['orders']['active'];
            $filter=[];
            if (isset($requestOrders['pagination']) ) {
                $filter['limit']=isset($requestOrders['pagination']['per_page'])?$requestOrders['pagination']['per_page']:10;
                $filter['page']=isset($requestOrders['pagination']['current_page'])?$requestOrders['pagination']['current_page']:0;
                $filter['sort']['by']=isset($requestOrders['sort'][0]['column'])?$requestOrders['sort'][0]['column']:'created_at';
                $filter['sort']['order']=isset($requestOrders['sort'][0]['order'])?$requestOrders['sort'][0]['order']:'desc';
            }

            if(!isset($filter['limit'])) $filter['limit']=10;
            if(!isset($filter['page'])) $filter['page']=0;
            if(!isset($filter['sort']['by'])) $filter['sort']['by']='created_at';
            if(!isset($filter['sort']['order'])) $filter['sort']['order']='desc';
            $ordersQuery = Order::orderBy($filter['sort']['by'], $filter['sort']['order'])->where('account_id', getAccountUser()->account_id);


            // Add search filter for code, customer name, customer phone, and address title
            if (!empty($requestOrders['search']) && is_string($requestOrders['search'])) {
                $search = $requestOrders['search'];
                $ordersQuery = $ordersQuery->where(function ($query) use ($search) {
                    $query->where('code', 'like', "%$search%")->orWhere('shipping_code', 'like', "%$search%")
                        ->orWhereHas('customer', function ($q) use ($search) {
                            $q->where('name', 'like', "%$search%")
                                ->orWhereHas('phones', function ($q2) use ($search) {
                                    $q2->where('title', 'like', "%$search%") ;
                                })
                                ->orWhereHas('addresses', function ($q3) use ($search) {
                                    $q3->where('title', 'like', "%$search%") ;
                                });
                        });
                });
            }
            // Filter orders by carrier_id = 24 through pickup relationship
            if (isset($requestOrders['carrier'])) {
                $ordersQuery = $ordersQuery->whereHas('pickup', function ($q)use($requestOrders) {
                    $q->where('carrier_id', $requestOrders['carrier']);
                });
            }else{
                $ordersQuery = $ordersQuery->whereHas('pickup');
            }
            $ordersQuery = $ordersQuery->whereIn('shipment_id', $shipment->childShipments->pluck('id')->toArray());
            $total = $ordersQuery->count();
            $orders = $ordersQuery
                ->skip($filter['page'] * $filter['limit'])
                ->take($filter['limit'])
                ->get();

            $orderDatas = $orders->map(function ($data) {
                $orderData = $data->only('id', 'code','shipping_code', 'comment', 'pickup', 'order_id', 'real_carrier_price', 'created_at');
                if (!$orderData['shipping_code'])
                    $orderData['shipping_code'] = "";
                $orderData['can_change'] = in_array($data->order_status_id, [1, 2, 3, 4, 5]) ? true : false;
                $orderData['user'] = $data->userCreated->map(function ($user) {
                    return [
                        "id" => $user->id,
                        "firstname" => $user->user->firstname,
                        "lastname" => $user->user->lastname,
                        "images" => $user->user->images,
                    ];
                });
                $orderData['comments'] = $data->lastOrderComments()->where('type', 'comment')->get()->map(function ($comment) {
                    return [
                        "id" => $comment->id,
                        "title" => $comment->title,
                        "user" => $comment->accountUser->user,
                        "status" => $comment->orderStatus,
                    ];
                });
                $orderData['customer'] = $data->customer->only('id', 'name', 'images');
                $orderData['customer']['phones'] = $data->customer->phones->map(function ($phone) {
                    return $phone->only('id', 'title');
                });
                $orderData['customer']['address'] = $data->customer->addresses->map(function ($address) {
                    return $address->only('id', 'title', 'city');
                });
                $totalOrder = 0;
                $orderData['products'] = ($data->order_status_id==2 ? $data->inactiveOrderPvas : $data->activeOrderPvas)->map(function ($actfOrderPva) use (&$totalOrder) {
                    $totalOrder += $actfOrderPva->price * $actfOrderPva->quantity;
                    $attributes = $actfOrderPva->ProductVariationAttribute->variationAttribute->childVariationAttributes->map(function ($child) {
                        return $child->attribute->code;
                    })->toArray();
                    $productInfo = [
                        'id' => $actfOrderPva->productVariationAttribute->product->id,
                        'order_pva' => $actfOrderPva->id,
                        'price' => $actfOrderPva->price,
                        'quantity' => $actfOrderPva->quantity,
                        'images' => $actfOrderPva->productVariationAttribute->product->images->sortByDesc('created_at')->values(),
                        'productType' => $actfOrderPva->productVariationAttribute->product->productType,
                        'product' => $actfOrderPva->productVariationAttribute->product->title . " " . implode('-', $attributes),
                        'reference' => $actfOrderPva->productVariationAttribute->product->reference,
                        'attributes' => $actfOrderPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($child) {
                            return [
                                "id" => $child->attribute->id,
                                "title" => $child->attribute->title,
                                "typeAttribute" => $child->attribute->typeAttribute->title,
                            ];
                        }),
                    ];
                    return $productInfo;
                });
                $orderData['total'] = $totalOrder;
                $orderData['discount'] = $data->discount;
                $orderData['carrier_price'] = $data->carrier_price;
                $orderData['brand'] = $data->brandSource->brand->only('id', 'title', 'images');
                
                $orderData['source'] = $data->brandSource->source->only('id', 'title', 'images');
                return $orderData;
            });
            $data['orders']['active'] =  [
                'statut' => 1,
                'data' => $orderDatas,
                'per_page' => (string)($filter['limit'] ?? 10),
                'current_page' => (int)($filter['page'] ?? 0) + 1,
                'total' => $total,
            ];
        }
        if (isset($request['orders']['inactive'])) {
            $requestOrders = $request['orders']['inactive'];
            $filter=[];
            if (isset($requestOrders['pagination']) ) {
                $filter['limit']=isset($requestOrders['pagination']['per_page'])?$requestOrders['pagination']['per_page']:10;
                $filter['page']=isset($requestOrders['pagination']['current_page'])?$requestOrders['pagination']['current_page']:0;
                $filter['sort']['by']=isset($requestOrders['sort'][0]['column'])?$requestOrders['sort'][0]['column']:'created_at';
                $filter['sort']['order']=isset($requestOrders['sort'][0]['order'])?$requestOrders['sort'][0]['order']:'desc';
            }

            if(!isset($filter['limit'])) $filter['limit']=10;
            if(!isset($filter['page'])) $filter['page']=0;
            if(!isset($filter['sort']['by'])) $filter['sort']['by']='created_at';
            if(!isset($filter['sort']['order'])) $filter['sort']['order']='desc';
            $ordersQuery = Order::orderBy($filter['sort']['by'], $filter['sort']['order'])->where('account_id', getAccountUser()->account_id);

            // Add search filter for code, customer name, customer phone, and address title
            if (!empty($requestOrders['search']) && is_string($requestOrders['search'])) {
                $search = $requestOrders['search'];
                $ordersQuery = $ordersQuery->where(function ($query) use ($search) {
                    $query->where('code', 'like', "%$search%")->orWhere('shipping_code', 'like', "%$search%")
                        ->orWhereHas('customer', function ($q) use ($search) {
                            $q->where('name', 'like', "%$search%")
                                ->orWhereHas('phones', function ($q2) use ($search) {
                                    $q2->where('title', 'like', "%$search%") ;
                                })
                                ->orWhereHas('addresses', function ($q3) use ($search) {
                                    $q3->where('title', 'like', "%$search%") ;
                                });
                        });
                });
            }
            // Filter orders by carrier_id = 24 through pickup relationship
            if (isset($requestOrders['carrier'])) {
                $ordersQuery = $ordersQuery->whereHas('pickup', function ($q)use($requestOrders) {
                    $q->where('carrier_id', $requestOrders['carrier']);
                });
            }else{
                $ordersQuery = $ordersQuery->whereHas('pickup');
            }
            $ordersQuery = $ordersQuery->whereNull('shipment_id');
            $total = $ordersQuery->count();
            $orders = $ordersQuery
                ->skip($filter['page'] * $filter['limit'])
                ->take($filter['limit'])
                ->get();

            $orderDatas = $orders->map(function ($data) {
                $orderData = $data->only('id', 'code','shipping_code', 'comment', 'pickup', 'order_id', 'real_carrier_price', 'created_at');
                if (!$orderData['shipping_code'])
                    $orderData['shipping_code'] = "";
                $orderData['can_change'] = in_array($data->order_status_id, [1, 2, 3, 4, 5]) ? true : false;
                $orderData['user'] = $data->userCreated->map(function ($user) {
                    return [
                        "id" => $user->id,
                        "firstname" => $user->user->firstname,
                        "lastname" => $user->user->lastname,
                        "images" => $user->user->images,
                    ];
                });
                $orderData['comments'] = $data->lastOrderComments()->where('type', 'comment')->get()->map(function ($comment) {
                    return [
                        "id" => $comment->id,
                        "title" => $comment->title,
                        "user" => $comment->accountUser->user,
                        "status" => $comment->orderStatus,
                    ];
                });
                $orderData['customer'] = $data->customer->only('id', 'name', 'images');
                $orderData['customer']['phones'] = $data->customer->phones->map(function ($phone) {
                    return $phone->only('id', 'title');
                });
                $orderData['customer']['address'] = $data->customer->addresses->map(function ($address) {
                    return $address->only('id', 'title', 'city');
                });
                $totalOrder = 0;
                $orderData['products'] = ($data->order_status_id==2 ? $data->inactiveOrderPvas : $data->activeOrderPvas)->map(function ($actfOrderPva) use (&$totalOrder) {
                    $totalOrder += $actfOrderPva->price * $actfOrderPva->quantity;
                    $attributes = $actfOrderPva->ProductVariationAttribute->variationAttribute->childVariationAttributes->map(function ($child) {
                        return $child->attribute->code;
                    })->toArray();
                    $productInfo = [
                        'id' => $actfOrderPva->productVariationAttribute->product->id,
                        'order_pva' => $actfOrderPva->id,
                        'price' => $actfOrderPva->price,
                        'quantity' => $actfOrderPva->quantity,
                        'images' => $actfOrderPva->productVariationAttribute->product->images->sortByDesc('created_at')->values(),
                        'productType' => $actfOrderPva->productVariationAttribute->product->productType,
                        'product' => $actfOrderPva->productVariationAttribute->product->title . " " . implode('-', $attributes),
                        'reference' => $actfOrderPva->productVariationAttribute->product->reference,
                        'attributes' => $actfOrderPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($child) {
                            return [
                                "id" => $child->attribute->id,
                                "title" => $child->attribute->title,
                                "typeAttribute" => $child->attribute->typeAttribute->title,
                            ];
                        }),
                    ];
                    return $productInfo;
                });
                $orderData['total'] = $totalOrder;
                $orderData['discount'] = $data->discount;
                $orderData['carrier_price'] = $data->carrier_price;
                $orderData['brand'] = $data->brandSource->brand->only('id', 'title', 'images');
                
                $orderData['source'] = $data->brandSource->source->only('id', 'title', 'images');
                return $orderData;
            });
            $data['orders']['inactive'] =  [
                'statut' => 1,
                'data' => $orderDatas,
                'per_page' => (string)($filter['limit'] ?? 10),
                'current_page' => (int)($filter['page'] ?? 0) + 1,
                'total' => $total,
            ];
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
                    $titleModel = Shipment::where(['id' => $value])->whereIn('account_user_id', $accountUsers)->first();
                    if (!$titleModel) {
                        $fail("not exist");
                    } elseif ($titleModel->statut == 2) {
                        $fail("not authorized");
                    }
                },
            ],
            '*.carrier_id' => [ // Validate title field
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
                'required',
                'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$warehouse) {
                        $fail("not exist");
                    }
                },
            ],
            /*'*.ordersToActive.*' => [
                'int',
                function ($attribute, $value, $fail) use ($requests) {
                    //récupérer l'id dial carrier bach n verifie les commandes dialo
                    $index = str_replace(['*', '.orders'], '-', $attribute);
                    $requestIndex = explode("-", $index);
                    $carrierId = $requests->input("{$requestIndex[0]}.carrier_id");
                    $id = $requests->input("{$requestIndex[0]}.id");
                    $ship = Shipment::find($id);
                    $account = getAccountUser()->account_id;
                    $order = Order::where(['id' => $value, 'account_id' => $account, 'shipment_id' => null])->orWhereIn('shipment_id', $ship->childShipments->pluck('id')->toArray())->whereNot('shipment_id', null)->first();
                    if (!$order) {
                        $fail("not exist" . $id);
                    } else {
                        if ($order->shipment->carrier_id !== $carrierId)
                            $fail("not exist");
                    }
                },
            ],*/
            '*.ordersToInactive.*' => [
                'int',
                function ($attribute, $value, $fail) use ($requests) {
                    //récupérer l'id dial carrier bach n verifie les commandes dialo
                    $index = str_replace(['*', '.orders'], '-', $attribute);
                    $requestIndex = explode("-", $index);
                    $carrierId = $requests->input("{$requestIndex[0]}.carrier_id");
                    $id = $requests->input("{$requestIndex[0]}.id");
                    $ship = Shipment::find($id);
                    $account = getAccountUser()->account_id;
                    $order = Order::where(['id' => $value, 'account_id' => $account])->whereIn('shipment_id', $ship->childShipments->pluck('id')->toArray())->whereNot('shipment_id', null)->first();
                    if (!$order) {
                        $fail("not exist");
                    } else {
                        if ($order->shipment->carrier_id !== $carrierId)
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
        $shipments = collect($requests->except('_method'))->map(function ($request) {
            $shipment_only = collect($request)->only('id', 'warehouse_id', 'carrier_id', 'title', 'comment', 'statut');
            $shipment = Shipment::find($shipment_only['id']);
            if ($shipment->carrier_id) {
                if (isset($request['ordersToInactive'])) {
                    $this->validateShip(new Request($request['ordersToInactive']), $shipment->childShipments->first(), $canceled = 1, $isRetour = ($shipment->shipmentType->id == 2) ? 1 : 0);
                }
                if (isset($request['ordersToActive'])) {
                    $this->validateShip(new Request($request['ordersToActive']), $shipment->childShipments->first(), $isRetour = ($shipment->shipmentType->id == 2) ? 1 : 0);
                }
            }
            $shipment = Shipment::with('childShipments.orders')->find($shipment->id);
            return $shipment;
        });
        return response()->json([
            'statut' => 1,
            'data' => $shipments,
        ]);
    }


    public function destroy($id)
    {
        $shipment = Shipment::find($id);
        $shipment->delete();
        return response()->json([
            'statut' => 1,
            'data' => $shipment,
        ]);
    }
}
