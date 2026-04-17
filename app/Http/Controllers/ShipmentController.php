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
            
            return [
                'id' => $shipment->id,
                'code' => $shipment->code,
                'title' => $shipment->title,
                'statut' => $shipment->statut,
                'created_at' => $shipment->created_at,
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
                'paid' => $shipment->transactions()->where('transaction_type', 'App\Models\Shipment')->sum('amount'),
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
                    $query->where('code', 'like', "%$search%")
                        ->orWhere('shipping_code', 'like', "%$search%")
                        ->orWhereHas('customer', function ($q2) use ($search) {
                            $q2->where('name', 'like', "%$search%") ;
                        })
                        ->orWhereHas('phones', function ($q3) use ($search) {
                            $q3->where('title', 'like', "%$search%") ;
                        })
                        ->orWhereHas('addresses', function ($q4) use ($search) {
                            $q4->where('title', 'like', "%$search%") ;
                        });
                });
            }
            /*// Filter orders by carrier_id = 24 through pickup relationship*/
            if (isset($requestOrders['carrier'])) {
                $pickupIds=Pickup::where('carrier_id', $requestOrders['carrier'])->pluck('id')->toArray();
                $ordersQuery = $ordersQuery->whereIn('pickup_id', $pickupIds)->whereNull('shipment_id');
            }else{
                $ordersQuery = $ordersQuery->where('order_status_id',9)->whereHas('pickup');
            }
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
                $orderData['status'] = $data->orderStatus->only('id', 'title');
                $orderData['customer'] = $data->customer->only('id', 'name', 'images');
                $orderData['customer']['phones'] = $data->customer->activePhones->map(function ($phone) {
                    return $phone->only('id', 'title');
                });
                $orderData['customer']['address'] = $data->customer->activeAddresses->map(function ($address) {
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
                $orderData['real_carrier_price'] = $data->real_carrier_price;
                $orderData['brand'] = $data->brandSource->brand->only('id', 'title', 'images');
                
                $orderData['source'] = $data->brandSource->source->only('id', 'title', 'images');
                return $orderData;
            });
            $inactiveOrders = [
                'statut' => 1,
                'data' => $orderDatas,
                'per_page' => (string)($filter['limit'] ?? 10),
                'current_page' => (int)($filter['page'] ?? 0) + 1,
                'total' => $total,
            ];
            $inactiveOrders['meta'] = [
                'total' => $inactiveOrders['total'],
                'per_page' => $inactiveOrders['per_page'],
                'current_page' => $inactiveOrders['current_page'],
            ];
            $data['orders']['inactive'] = $inactiveOrders;
        }
       
        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }
    public static function validateShip(Request $request, $shipment, $canceled = 0, $isRetour = 0)
    {
        $comment = ($isRetour == 1) ? ($shipment->statut == 0 ? ['id' => 53, "title" => "en attente de Validation"] : ['id' => 53, "title" => "Validée"]) : ($shipment->statut == 0 ? ['id' => 51, "title" => "en attente de Validation"] : ['id' => 51, "title" => "Validée"]);
        $shipmentId = $shipment->id;
        
        $datas = collect($request)->map(function ($order) use ($shipmentId, $comment,$canceled) {
            $orderModel = Order::find($order['id']);
            if ($canceled == 1) {
                $lastComment = $orderModel->orderComments()->whereNotIn('order_status_id', [5, 6, 7, 8, 9, 10, 11])->orderByDesc('created_at')->first();
                $comment=($lastComment) ? ['id' => $lastComment->comment_id, "title" => "Retirer d'un bon de sortie"] : ['id' => 29, "title" => 'En cours'];
            }
            return [
                'id' => $orderModel->id,
                'shipment_id' => $canceled==1 ? null : $shipmentId,
                'real_carrier_price' => isset($order['carrier_price']) ? $order['carrier_price'] : 0,
                'discount' => isset($order['discount']) ? $order['discount'] : $orderModel->discount,
                'comment' => $comment
            ];
        })->values()->all();

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
                    if ($shipment->shipment_type_id == 2) {
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
                    if ($shipment->shipment_type_id == 1) {
                        $carrierTotal = 0;
                        $total = 0;
                        foreach ($request['orders'] as $orderData) {
                            $order=Order::find($orderData['id']);
                            $carrierTotal += $order->real_carrier_price;
                            $order->activeOrderPvas->map(function ($pva) use (&$total) {
                                $total += $pva->price * $pva->quantity;
                            });
                            $total-=$order->discount;
                        }
                        
                        $transactionData[] = [
                            "type" => "shipment",
                            "amount" => $carrierTotal,
                            "transaction_type_id" => 2,
                            "transaction_id" => $shipment->id
                        ];
                        if (isset($request['given_amount']))
                            if ($request['given_amount'] != 0)
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
        $shipment = Shipment::with(['warehouse', 'carrier', 'accountUser.user'])->find($id);
        if (!$shipment)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        if (isset($request['shipmentInfo'])) {
            $shipment['orders'] = $shipment->childShipments->flatMap(function ($childShipment) {
                return $childShipment->orders->map(function ($order) {
                    $orderData = $order->only('id', 'code', 'pickup_id', 'comment', 'order_id', 'created_at');
                    $orderData['user'] = $order->userCreated->map(function ($user) {
                    return [
                        "id" => $user->id,
                        "firstname" => $user->user->firstname,
                        "lastname" => $user->user->lastname,
                        "images" => $user->user->images,
                    ];
                });
                $orderData['comments'] = $order->lastOrderComments->map(function ($comment) {
                    return [
                        "id" => $comment->id,
                        "title" => $comment->title,
                        "user" => $comment->accountUser->user,
                        "status" => $comment->orderStatus,
                    ];
                });
                $orderData['customer'] = $order->customer->only('id', 'name', 'images');
                $orderData['customer']['phones'] = $order->activePhones->map(function ($phone) {
                    return $phone->only('id', 'title');
                });
                $orderData['customer']['address'] = $order->activeAddresses->map(function ($address) {
                    return $address->only('id', 'title', 'city');
                });
                $total = 0;
                $orderData['products'] = $order->activePvas->map(function ($pva) use (&$total) {
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
                $orderData['discount'] = $order->discount;
                $orderData['real_carrier_price'] = $order->real_carrier_price;
                $orderData['brand'] = $order->brandSource->brand->only('id', 'title', 'images');
                $orderData['source'] = $order->brandSource->source->only('id', 'title', 'images');
                return $orderData;
            });
            });
            $data["shipmentInfo"]['data']  = $shipment;
        }
        // Add transaction.active to response if present in request
        if (isset($request['transactions']['active'])) {
            // Assuming there is a transactions() relationship on the Shipment model
            // and 'active' means statut == 1 (or adjust as per your business logic)
            $activeTransactions = $shipment->transactions()
                ->where('statut', 1)
                ->get()
                ->map(function ($transaction) {
                    // Return only relevant fields, adjust as needed
                    return [
                        'id' => $transaction->id,
                        'amount' => $transaction->amount ?? null,
                        'transaction_type' => $transaction->transactionType->only('id', 'title') ?? null,
                        'statut' => $transaction->statut,
                        'created_at' => $transaction->created_at,
                        'updated_at' => $transaction->updated_at,
                        // Add more fields as needed
                    ];
                });
            $data['transactions']['active'] = [
                'statut' => 1,
                'data' => $activeTransactions,
            ];
        }
        if (isset($request['orders']['all'])) {
            $requestOrders = $request['orders']['all'];
            $filter = [];
            if (isset($requestOrders['pagination'])) {
                $filter['limit'] = isset($requestOrders['pagination']['per_page']) ? $requestOrders['pagination']['per_page'] : 10;
                $filter['page'] = isset($requestOrders['pagination']['current_page']) ? $requestOrders['pagination']['current_page'] : 0;
                $filter['sort']['by'] = isset($requestOrders['sort'][0]['column']) ? $requestOrders['sort'][0]['column'] : 'created_at';
                $filter['sort']['order'] = isset($requestOrders['sort'][0]['order']) ? $requestOrders['sort'][0]['order'] : 'desc';
            }

            if (!isset($filter['limit'])) $filter['limit'] = 10;
            if (!isset($filter['page'])) $filter['page'] = 0;
            if (!isset($filter['sort']['by'])) $filter['sort']['by'] = 'created_at';
            if (!isset($filter['sort']['order'])) $filter['sort']['order'] = 'desc';

            $ordersQuery = Order::orderBy($filter['sort']['by'], $filter['sort']['order'])
                ->where('account_id', getAccountUser()->account_id);

            if (!empty($requestOrders['search']) && is_string($requestOrders['search'])) {
                $search = $requestOrders['search'];
                $ordersQuery = $ordersQuery->where(function ($query) use ($search) {
                    $query->where('code', 'like', "%$search%")
                        ->orWhere('shipping_code', 'like', "%$search%")
                        ->orWhereHas('customer', function ($q2) use ($search) {
                            $q2->where('name', 'like', "%$search%") ;
                        })
                        ->orWhereHas('phones', function ($q3) use ($search) {
                            $q3->where('title', 'like', "%$search%") ;
                        })
                        ->orWhereHas('addresses', function ($q4) use ($search) {
                            $q4->where('title', 'like', "%$search%") ;
                        });
                });
            }
            $pickupIds=Pickup::where('carrier_id', $shipment->carrier_id)->pluck('id')->toArray();
            $ordersQuery = $ordersQuery->whereIn('pickup_id', $pickupIds)->whereNull('shipment_id');

            $total = $ordersQuery->count();
            $orders = $ordersQuery
                ->skip($filter['page'] * $filter['limit'])
                ->take($filter['limit'])
                ->get();

            $orderDatas = $orders->map(function ($data) {
                $orderData = $data->only('id', 'code', 'shipping_code', 'comment', 'pickup', 'order_id', 'real_carrier_price', 'created_at');
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
                $orderData['status'] = $data->orderStatus->only('id', 'title');
                $orderData['customer'] = $data->customer->only('id', 'name', 'images');
                $orderData['customer']['phones'] = $data->customer->activePhones->map(function ($phone) {
                    return $phone->only('id', 'title');
                });
                $orderData['customer']['address'] = $data->customer->activeAddresses->map(function ($address) {
                    return $address->only('id', 'title', 'city');
                });
                $totalOrder = 0;
                $orderData['products'] = ($data->order_status_id == 2 ? $data->inactiveOrderPvas : $data->activeOrderPvas)->map(function ($actfOrderPva) use (&$totalOrder) {
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
                $orderData['real_carrier_price'] = $data->real_carrier_price;
                $orderData['brand'] = $data->brandSource->brand->only('id', 'title', 'images');
                $orderData['source'] = $data->brandSource->source->only('id', 'title', 'images');
                return $orderData;
            });

            $ordersAll = [
                'statut' => 1,
                'data' => $orderDatas,
                'per_page' => (string)($filter['limit'] ?? 10),
                'current_page' => (int)($filter['page'] ?? 0) + 1,
                'total' => $total,
            ];
            $ordersAll['meta'] = [
                'total' => $ordersAll['total'],
                'per_page' => $ordersAll['per_page'],
                'current_page' => $ordersAll['current_page'],
            ];

            $data['orders']['all'] = $ordersAll;
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
            '*.carrier_id' => [
                function ($attribute, $value, $fail) {
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
            '*.ordersToActive.*' => [
                /*function ($attribute, $value, $fail) use ($requests) {
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
                },*/
            ],
            '*.ordersToUpdate.*' => [
                function ($attribute, $value, $fail) use ($requests) {
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
            ],
            '*.ordersToInactive.*' => [
                'int',
                function ($attribute, $value, $fail) use ($requests) {
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
            $shipmentChild = $shipment->childShipments->first();

            // --- Handle type 1 (delivery) shipment order changes ---
            if ($shipmentChild->statut == 1 && $shipment->shipment_type_id == 1) {
                $carrierTotal = 0;
                $total = 0;
                
                if(isset($request['ordersToActive'])){
                    foreach ($request['ordersToActive'] as $orderData) {
                        $this->validateShip(new Request($request['ordersToActive']), $shipmentChild, $isRetour = 0);
                        $order=Order::find($orderData['id']);
                        $carrierTotal += $order->real_carrier_price;
                        $order->activeOrderPvas->map(function ($pva) use (&$total) {
                            $total += $pva->price * $pva->quantity;
                        });
                        $total-=$order->discount;
                    }
                }
                if(isset($request['ordersToUpdate'])){
                    foreach ($request['ordersToUpdate'] as $orderData) {
                        $order=Order::find($orderData['id']);
                        $carrierTotal += $orderData['carrier_price']-$order->real_carrier_price;
                    }
                    $this->validateShip(new Request($request['ordersToUpdate']), $shipmentChild, $isRetour = 0);
                }
                if(isset($request['ordersToInactive'])){
                    $ordersToInactive=[];
                    foreach ($request['ordersToInactive'] as $orderId) {
                        $order=Order::find($orderId);
                        $ordersToInactive[]=["id"=>$order->id];
                        $carrierTotal -= $order->real_carrier_price;
                        $totalOrder=0;
                        $order->activeOrderPvas->map(function ($pva) use (&$totalOrder) {
                            $totalOrder -= $pva->price * $pva->quantity;
                        });
                        $totalOrder-=$order->discount;
                        $total -= $totalOrder;
                    }
                    $this->validateShip(new Request($ordersToInactive), $shipmentChild, $canceled = 1, $isRetour = 0);
                }
                if($carrierTotal!=0){
                    $transactionData[] = [
                        "type" => "shipment",
                        "amount" => $carrierTotal,
                        "transaction_type_id" =>2,
                        "transaction_id" => $shipment->id
                    ];
                }
                if (isset($request['given_amount']))
                    if ($request['given_amount'] != 0)
                        $transactionData[] = [
                            "type" => "shipment",
                            "amount" => $request['given_amount'],
                            "transaction_type_id" => 1,
                            "transaction_id" => $shipment->id
                        ];
                TransactionController::store(new Request($transactionData));
            }

            // --- Handle type 2 (return) shipment: sync mouvement stock like PickupController ---
            if ($shipment->shipment_type_id == 2 && (isset($request['ordersToInactive']) || isset($request['ordersToActive']))) {

                // Remove inactive orders from shipment child.
                if (!empty($request['ordersToInactive'])) {
                    $ordersToInactiveFormatted = array_map(fn($id) => ['id' => $id], $request['ordersToInactive']);
                    $this->validateShip(new Request($ordersToInactiveFormatted), $shipmentChild, $canceled = 1, $isRetour = 1);
                }

                // Add new active orders to shipment child.
                if (!empty($request['ordersToActive'])) {
                    $this->validateShip(new Request($request['ordersToActive']), $shipmentChild, $canceled = 0, $isRetour = 1);
                }

                // Resolve destination warehouse (return movements go to the receival rayon).
                $warehouse = Warehouse::find($shipment->warehouse_id);
                $toWarehouseParent = $warehouse ? $warehouse->childWarehouses()->where('warehouse_type_id', 2)->first() : null;
                $to_warehouse = $toWarehouseParent
                    ? $toWarehouseParent->childWarehouses()->where(['warehouse_nature_id' => 1, 'warehouse_type_id' => 3])->first()
                    : null;

                if ($to_warehouse) {
                    // Reload shipmentChild with fresh order PVAs after order status changes.
                    $shipmentChild = Shipment::with('orders.orderPvas')->find($shipmentChild->id);

                    // Rebuild PVA totals from real current shipment child orders.
                    $productVariationAttributes = [];
                    $shipmentChild->orders->each(function ($order) use (&$productVariationAttributes) {
                        $order->orderPvas->each(function ($orderPva) use (&$productVariationAttributes) {
                            if (isset($productVariationAttributes[$orderPva->product_variation_attribute_id])) {
                                $productVariationAttributes[$orderPva->product_variation_attribute_id]['quantity'] += $orderPva->quantity;
                            } else {
                                $productVariationAttributes[$orderPva->product_variation_attribute_id] = [
                                    'id'       => $orderPva->product_variation_attribute_id,
                                    'quantity' => $orderPva->quantity,
                                ];
                            }
                            $productVariationAttributes[$orderPva->product_variation_attribute_id]['orders'][] = $orderPva->id;
                        });
                    });

                    $pvaLines = collect($productVariationAttributes)->values()->toArray();

                    if ($shipmentChild->mouvement_id) {
                        // Update the existing return mouvement with the reconciled PVA lines.
                        app(ReturnController::class)->update(new Request([[
                            'id'                       => $shipmentChild->mouvement_id,
                            'to_warehouse'             => $to_warehouse->id,
                            'statut'                   => 1,
                            'productVariationAttributes' => $pvaLines,
                        ]]));
                    } elseif (count($pvaLines) > 0) {
                        // No mouvement yet: create one and link it to the shipment child.
                        $return = ReturnController::store(new Request([[
                            'to_warehouse'             => $to_warehouse->id,
                            'statut'                   => 1,
                            'productVariationAttributes' => $pvaLines,
                        ]]), $local = 1);
                        $shipmentChild->update(['mouvement_id' => $return->id]);
                    }
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

    public function printShipment($id)
    {
        $shipment = Shipment::with([
            'carrier',
            'transactions.transactionType',
            'childShipments.orders.customer',
            'childShipments.orders.orderPvas'
        ])->find($id);

        if (!$shipment) {
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ], 404);
        }

        $orders = $shipment->childShipments->flatMap(function ($childShipment) {
            return $childShipment->orders;
        })->values();

        $ordersData = $orders->map(function ($order) {
            $orderTotal = $order->orderPvas->sum(function ($pva) {
                return $pva->price * $pva->quantity;
            });

            return [
                'id' => $order->id,
                'code' => $order->code,
                'shipping_code' => $order->shipping_code,
                'customer' => $order->customer ? $order->customer->only('id', 'name') : null,
                'total' => $orderTotal,
                'discount' => (float) $order->discount,
                'net' => $orderTotal - (float) $order->discount,
            ];
        })->values();

        $transactionsData = $shipment->transactions->map(function ($transaction) {
            return [
                'id' => $transaction->id,
                'amount' => (float) $transaction->amount,
                'transaction_type_id' => $transaction->transaction_type_id,
                'transaction_type' => $transaction->transactionType ? $transaction->transactionType->only('id', 'title') : null,
                'created_at' => $transaction->created_at,
            ];
        })->values();

        $ordersNetTotal = $ordersData->sum(function ($order) {
            return $order['net'];
        });

        $transactionsType1 = $shipment->transactions->where('transaction_type_id', 1)->sum('amount');
        $transactionsType2 = $shipment->transactions->where('transaction_type_id', 2)->sum('amount');
        $transactionsImpact = (float) $transactionsType1 - (float) $transactionsType2;

        $totalAmount = $ordersNetTotal - $transactionsImpact;

        return response()->json([
            'statut' => 1,
            'data' => [
                'shipment' => $shipment->only('id', 'code', 'title', 'created_at', 'statut'),
                'carrier' => $shipment->carrier,
                'orders' => $ordersData,
                'transactions' => $transactionsData,
                'totals' => [
                    'orders_net_total' => $ordersNetTotal,
                    'transactions_impact' => $transactionsImpact,
                    'total_amount' => $totalAmount,
                ],
            ],
        ]);
    }

    public function printShipmentPdf($id)
    {
        $shipment = Shipment::with([
            'carrier',
            'transactions.transactionType',
            'childShipments.orders.customer',
            'childShipments.orders.orderPvas'
        ])->find($id);

        if (!$shipment) {
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ], 404);
        }

        $orders = $shipment->childShipments->flatMap(function ($childShipment) {
            return $childShipment->orders;
        })->values();

        $ordersData = $orders->map(function ($order) {
            $orderTotal = $order->orderPvas->sum(function ($pva) {
                return $pva->price * $pva->quantity;
            });

            return [
                'id' => $order->id,
                'code' => $order->code,
                'shipping_code' => $order->shipping_code,
                'customer' => $order->customer ? $order->customer->name : '-',
                'total' => (float) $orderTotal,
                'discount' => (float) $order->discount,
                'carrier_price' => (float) $order->real_carrier_price,
                'net' => (float) $orderTotal - (float) $order->discount- (float) $order->real_carrier_price,
            ];
        })->values();

        $transactionsData = $shipment->transactions->map(function ($transaction) {
            return [
                'id' => $transaction->id,
                'amount' => (float) $transaction->amount,
                'transaction_type_id' => $transaction->transaction_type_id,
                'transaction_type' => $transaction->transactionType ? $transaction->transactionType->title : '-',
                'created_at' => $transaction->created_at,
            ];
        })->values();

        $ordersNetTotal = $ordersData->sum(function ($order) {
            return $order['net'];
        });

        $transactionsType1 = $shipment->transactions->where('transaction_type_id', 1)->sum('amount');
        $transactionsType2 = $shipment->transactions->where('transaction_type_id', 2)->sum('amount');
        $transactionsImpact = (float) $transactionsType1 - (float) $transactionsType2;

        $totalAmount = $ordersNetTotal - $transactionsImpact;

        $carrierTitle = $shipment->carrier ? htmlspecialchars($shipment->carrier->title) : 'N/A';
        $shipmentCode = htmlspecialchars((string) $shipment->code);
        $shipmentTitle = htmlspecialchars((string) ($shipment->title ?? ''));

        $ordersRows = '';
        foreach ($ordersData as $order) {
            $ordersRows .= '<tr>'
                . '<td>' . htmlspecialchars((string) $order['id']) . '</td>'
                . '<td>' . htmlspecialchars((string) $order['code']) . '</td>'
                . '<td>' . htmlspecialchars((string) ($order['shipping_code'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string) $order['customer']) . '</td>'
                . '<td style="text-align:right;">' . number_format((float) $order['total'], 2, '.', ' ') . '</td>'
                . '<td style="text-align:right;">' . number_format((float) $order['discount'], 2, '.', ' ') . '</td>'
                . '<td style="text-align:right;">' . number_format((float) $order['net'], 2, '.', ' ') . '</td>'
                . '</tr>';
        }

        $transactionsRows = '';
        foreach ($transactionsData as $transaction) {
            $transactionsRows .= '<tr>'
                . '<td>' . htmlspecialchars((string) $transaction['id']) . '</td>'
                . '<td>' . htmlspecialchars((string) $transaction['transaction_type']) . '</td>'
                . '<td style="text-align:right;">' . number_format((float) $transaction['amount'], 2, '.', ' ') . '</td>'
                . '<td>' . htmlspecialchars((string) $transaction['created_at']) . '</td>'
                . '</tr>';
        }

        $html = '
            <h2>Shipment Print</h2>
            <p><strong>Code:</strong> ' . $shipmentCode . '</p>
            <p><strong>Title:</strong> ' . $shipmentTitle . '</p>
            <p><strong>Carrier:</strong> ' . $carrierTitle . '</p>

            <h3>Orders</h3>
            <table width="100%" border="1" cellspacing="0" cellpadding="6">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Code</th>
                        <th>Shipping Code</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Discount</th>
                        <th>Net</th>
                    </tr>
                </thead>
                <tbody>' . $ordersRows . '</tbody>
            </table>

            <h3 style="margin-top:16px;">Transactions</h3>
            <table width="100%" border="1" cellspacing="0" cellpadding="6">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>' . $transactionsRows . '</tbody>
            </table>

            <h3 style="margin-top:16px;">Totals</h3>
            <p><strong>Orders Net Total:</strong> ' . number_format((float) $ordersNetTotal, 2, '.', ' ') . '</p>
            <p><strong>Transactions Impact:</strong> ' . number_format((float) $transactionsImpact, 2, '.', ' ') . '</p>
            <p><strong>Total Amount:</strong> ' . number_format((float) $totalAmount, 2, '.', ' ') . '</p>
        ';

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'orientation' => 'P',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
        ]);

        $mpdf->WriteHTML($html);
        $pdfContent = $mpdf->Output('', 'S');

        return response()->json([
            'statut' => 1,
            'data' => base64_encode($pdfContent),
            'filename' => 'shipment-' . $shipment->id . '.pdf',
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
