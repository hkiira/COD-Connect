<?php


namespace App\Http\Controllers;

use niklasravnsborg\LaravelPdf\Facades\Pdf as PDF;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

use App\Models\Order;
use Illuminate\Http\Request;
use App\Models\AccountUser;
use App\Models\Warehouse;
use App\Models\Pickup;
use App\Models\Carrier;
use App\Models\Collector;
use Illuminate\Support\Facades\Validator;

class PickupController extends Controller
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
        $model = 'App\\Models\\Pickup';
        $request['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'code'], true, $associated);
        $datas['data'] = collect($datas['data'])->map(function ($pickup) {
            $pickupData = $pickup;
            $pickup->carrier->images;
            $pickupData['user'] = [
                "id" => $pickup->accountUser->id,
                "firstname" => $pickup->accountUser->user->firstname,
                "lastname" => $pickup->accountUser->user->lastname,
                "images" => $pickup->accountUser->user->images,
            ];
            $total = 0;
            $shipping = 0;
            $analytics = ['canceled' => 0, 'delivred' => 0, 'shipped' => 0];
            $pickup->orders->map(function ($order) use (&$total, &$shipping, &$analytics) {
                $analytics['canceled'] += (in_array($order->order_status_id, [5, 6])) ? 1 : 0;
                $analytics['delivred'] += (in_array($order->order_status_id, [7, 10])) ? 1 : 0;
                $analytics['shipped'] += (in_array($order->order_status_id, [8, 9, 11])) ? 1 : 0;
                $shipping += $order->carrier_price;
                $order->orderPvas->map(function ($orderPva) use (&$total) {
                    $total += ($orderPva->quantity * $orderPva->price);
                });
            });
            $pickupData['carrier'] = $pickup->carrier;
            $pickupData['warehouse'] = ($pickup->warehouse) ? $pickup->warehouse->only('id', 'title') : "null";
            $pickupData['collector'] = ($pickup->collector_id) ? $pickup->collector->only('id', 'name') : null;
            $pickupData['count'] = $pickup->orders->count();
            $pickupData['total'] = $total;
            $pickupData['shipping'] = $shipping; 
            $pickupData['analytics'] = $analytics;
            return $pickupData; 
        });
        return $datas;
    }

    public function generateTickets($id)
    {
        $orders = Order::where('pickup_id', $id)->get();
        $datas = [];

        foreach ($orders as $key => $order) {
            QrCode::size(100)->generate($order->code, public_path('qrcodes/' . $order->code . '.png'));
            $total = 0;
            $order->activePvas->map(function ($activePva) use (&$total) {
                $total += $activePva->pivot->quantity * $activePva->pivot->price;
            });

            $datas['datas'][] = [
                "code" => $order->code,
                "sender" => $order->brandSource->brand->title,
                "sender_mail" => $order->brandSource->brand->email,
                "sender_phone" => $order->brandSource->brand,
                "customer" => $order->customer->name,
                "address" => $order->customer->addresses->map(function ($address) {
                    return $address->title." - ".$address->city->title;
            })->first(),
                "phones" => $order->phones->map(function ($phone) {
                    return $phone->title;
                }),
                "products" => $order->activePvas->map(function ($activePva) {
                    $variations = $activePva->variationAttribute->childVariationAttributes->map(function ($childVa) {
                        return $childVa->attribute->title;
                    });
                    return $activePva->pivot->quantity . " x " . $activePva->product->title . ' : ' . implode(", ", $variations->toArray());
                }),
                "total" => $total . ' DH ',
                'qr_code' => "{$order->code}.png" // QR code for the tracking number
            ];
        }

        $html = view('pdf.tickets', $datas)->render();

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => [100, 100], // 100mm x 100mm
            'orientation' => 'P', // Portrait
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);

        $mpdf->WriteHTML($html);

        $pdfContent = $mpdf->Output('', 'S'); // Output as a string
        // Encode PDF to Base64
        $base64Pdf = base64_encode($pdfContent);

        // Return JSON response with Base64 PDF
        return response()->json([
            'statut' => 1,
            'data' => $base64Pdf,
        ]);
    }
    public function generatePdf($id)
    {
        $pickup = Pickup::find($id);
        $orders = Order::where('pickup_id', $id)->get();
        $datas = [];
        $pickUpTotal = 0;
        foreach ($orders as $key => $order) {
            $total = 0;
            $order->activePvas->map(function ($activePva) use (&$total) {
                $total += $activePva->pivot->quantity * $activePva->pivot->price;
            });

            $datas['datas'][] = [
                "code" => $order->code,
                "comment" => $order->note,
                "customer" => $order->customer->name,
                "address" => $order->addresses->first()->title . "-" . $order->city->title,
                "city" => $order->city->title,
                "phones" => $order->phones->map(function ($phone) {
                    return $phone->title;
                }),
                "products" => $order->activePvas->map(function ($activePva) {
                    $variations = $activePva->variationAttribute->childVariationAttributes->map(function ($childVa) {
                        return $childVa->attribute->title;
                    });
                    return $activePva->pivot->quantity . " x " . $activePva->product->title . ' : ' . implode(", ", $variations->toArray());
                }),
                "total" => $total . ' DH ',
            ];
            $pickUpTotal += $total;
        }
        $datas['shippedBy'] = ($pickup->carrier_id) ? $pickup->carrier->title : $pickup->accountUser->user->firstname . " " . $pickup->accountUser->user->lastname;
        $datas['account'] = $pickup->accountUser->account->name;
        $datas['code'] = $pickup->code;
        $datas['total'] = $pickUpTotal;
        $datas['countOrders'] = $orders->count();
        $html = view('pdf.pickup', $datas)->render();
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'orientation' => 'P', // P for Portrait, L for Landscape
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);

        $mpdf->WriteHTML($html);
        $pdfContent = $mpdf->Output('', 'S'); // Output as a string
        // Encode PDF to Base64
        $base64Pdf = base64_encode($pdfContent);

        // Return JSON response with Base64 PDF
        return response()->json([
            'statut' => 1,
            'data' => $base64Pdf,
        ]);
    }


    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        if (isset($request['orders']['inactive'])) {
            $model = 'App\\Models\\Order';
            //permet de récupérer la liste des regions inactive filtrés
            $request['orders']['inactive']['where'] = ['column' => 'pickup_id', 'value' => null];
            $request['orders']['inactive']['whereArray'] = ['column' => 'order_status_id', 'values' => [4]];
            $request['orders']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $datas = FilterController::searchs(new Request($request['orders']['inactive']), $model, ['id', 'code'], true, []);
            $datas['data'] = collect($datas['data'])->map(function ($data) {
                $orderData = $data->only('id', 'code', 'comment', 'order_id', 'pickup_id', 'order_status_id', 'created_at');
                $orderData['carriers'] = $data->city->activeCarriers->map(function ($carrier) {
                    return $carrier->only('id', 'title');
                });
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
            // Add pagination fields at the root level as well as inside meta
            if (isset($datas['meta'])) {
                $datas['total'] = $datas['meta']['total'] ?? null;
                $datas['per_page'] = $datas['meta']['per_page'] ?? null;
                $datas['current_page'] = $datas['meta']['current_page'] ?? null;
            }
            $data['orders']['inactive'] = $datas;
        }

        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }
    public static function validatePickup(Request $request, $pickup, $canceled = 0)
    {
        $comment = ($pickup->statut == 0) ? ['id' => 48, "title" => "En préparation"] : ['id' => 29, "title" => "En cours"];
        $pickupId = $pickup->id;
        if ($canceled == 1) {
            $comment = collect($request)->map(function ($orderId) {
                $order = Order::find($orderId);
                $lastComment = $order->orderComments()->whereNotIn('order_status_id', [5, 6, 7, 8, 9, 10, 11])->orderByDesc('created_at')->first();
                if ($lastComment) {
                return ['id' => $lastComment->comment_id, "title" => "Retirer d'un bon de sortie"];
                }
                return ['id' => 1, "title" => "Commande en attente"];
            })->first();
            $pickupId = null;
        }
        $datas = array_values(array_map(function ($value) use ($pickupId, $comment) {
            return [
                'id' => $value,
                'pickup_id' => $pickupId,
                'comment' => $comment
            ];
        }, $request->toArray(), array_keys($request->toArray())));
        $orders = OrderController::update(new Request($datas), $local = 1);
        return $orders;
    }
    public static function store(Request $requests)
    {
        $warehouse = Warehouse::where(['account_id' => getAccountUser()->account_id,'warehouse_type_id'=>1,'statut'=>1])->whereNull(['warehouse_nature_id','warehouse_id'])->first();
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
                'required',
                'int',
                function ($attribute, $value, $fail) {
                    /*$account = getAccountUser()->account_id;
                    $order=Order::where(['id'=>$value,'pickup_id'=>null])->first();
                    if (!$order) {
                            $fail("not exist");
                    }*/
                },
            ],
            '*.warehouse_id' => [
                'int',
                function ($attribute, $value, $fail) use (&$warehouse) {
                    $account = getAccountUser()->account_id;
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$warehouse) {
                        $fail("not exist " . $account);
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
        $from_warehouse = $warehouse->childWarehouses()->where('warehouse_type_id', 2)->first()->childWarehouses()->where(['warehouse_nature_id' => 1, 'warehouse_type_id' => 3])->first();
        $pickups = collect($requests->except('_method'))->map(function ($request) use ($from_warehouse) {
            $request["account_user_id"] = getAccountUser()->id;
            $account_id = getAccountUser()->account_id;
            $request['code'] = (isset($request['code'])) ? $request['code'] : DefaultCodeController::getAccountCode('Pickup', $account_id);
            $pickup_only = collect($request)->only('code', 'title', 'warehouse_id', 'comment', 'carrier_id', 'statut', 'account_user_id', 'created_at', 'updated_at');
            $pickup = Pickup::create($pickup_only->all());
            if (isset($request['orders'])) {
                $orderPvas = self::validatePickup(new Request($request['orders']), $pickup);
                if ($pickup->statut = 1) {
                    $productVariationAttributes = [];
                    collect($orderPvas)->flatten()->Map(function ($orderPva) use (&$productVariationAttributes) {
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
                    $pickupData = [
                        'from_warehouse' => $from_warehouse->id,
                        'statut' => 1,
                        'productVariationAttributes' => collect($productVariationAttributes)->values()->toArray()
                    ];
                    $exitslip = ExitslipController::store(new Request($pickupData));
                    $pickup->update(['mouvement_id' => $exitslip->id]);
                }
            }
            return $pickup;
        });
        return response()->json([
            'statut' => 1,
            'data' => $pickups,
        ]);
    }

    public function show($id)
    {
        $account_id = getAccountUser()->account_id;
        $accountUserIds = AccountUser::where(['account_id' => $account_id, 'statut' => 1])->pluck('id')->toArray();

        $pickup = Pickup::with([
            'carrier.images',
            'carrier.phones.PhoneTypes',
            'warehouse',
            'collector',
            'accountUser.user.images',
            'orders.orderPvas',
        ])->whereIn('account_user_id', $accountUserIds)->find($id);

        if (!$pickup) {
            return response()->json(['statut' => 0, 'data' => 'not exist'], 404);
        }

        $total = 0;
        $shipping = 0;
        $analytics = ['canceled' => 0, 'delivred' => 0, 'shipped' => 0];
        $pickup->orders->each(function ($order) use (&$total, &$shipping, &$analytics) {
            $analytics['canceled'] += in_array($order->order_status_id, [5, 6]) ? 1 : 0;
            $analytics['delivred'] += in_array($order->order_status_id, [7, 10]) ? 1 : 0;
            $analytics['shipped']  += in_array($order->order_status_id, [8, 9, 11]) ? 1 : 0;
            $shipping += $order->carrier_price;
            $order->orderPvas->each(function ($orderPva) use (&$total) {
                $total += $orderPva->quantity * $orderPva->price;
            });
        });

        $data = $pickup->toArray();
        $data['user'] = [
            'id'        => $pickup->accountUser->id,
            'firstname' => $pickup->accountUser->user->firstname,
            'lastname'  => $pickup->accountUser->user->lastname,
            'images'    => $pickup->accountUser->user->images,
        ];
        $data['warehouse'] = $pickup->warehouse ? $pickup->warehouse->only('id', 'title') : null;
        $data['collector'] = $pickup->collector ? $pickup->collector->only('id', 'name') : null;
        $data['count']     = $pickup->orders->count();
        $data['total']     = $total;
        $data['shipping']  = $shipping;
        $data['analytics'] = $analytics;

        return response()->json(['statut' => 1, 'data' => $data]);
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
            // Add the list of orders in the same structure as active orders
            $data["pickupInfo"]['orders'] = $pickup->orders->map(function ($order) {
                $orderData = $order->only('id', 'code', 'pickup_id', 'comment', 'order_id', 'created_at');
                $orderData['carriers'] = $order->city->activeCarriers->map(function ($carrier) {
                    return $carrier->only('id', 'title');
                });
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
                $orderData['customer']['phones'] = $order->phones->map(function ($phone) {
                    return $phone->only('id', 'title');
                });
                $orderData['customer']['address'] = $order->addresses->map(function ($address) {
                    return $address->only('id', 'title', 'city');
                });
                $total = 0;
                $orderData['products'] = $order->productVariationAttributes->map(function ($pva) use (&$total) {
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
                $orderData['brand'] = $order->brandSource->brand->only('id', 'title', 'images');
                $orderData['source'] = $order->brandSource->source->only('id', 'title', 'images');
                return $orderData;
            });
        }
        if (isset($request['orders']['all'])) {
            $model = 'App\\Models\\Order';
            //permet de récupérer la liste des regions all filtrés
            $request['orders']['all']['inAccount'] = ["account_id", getAccountUser()->account_id];
            $orders = FilterController::searchs(new Request($request['orders']['all']), $model, ['id', 'code'], true, []);
            $orders['data'] = collect($orders['data'])->map(function ($data) {
                $orderData = $data->only('id', 'code', 'pickup_id', 'comment', 'order_id', 'created_at');
                $orderData['carriers'] = $data->city->activeCarriers->map(function ($carrier) {
                    return $carrier->only('id', 'title');
                });
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
                $orderData['discount'] = $data->discount;
                $orderData['brand'] = $data->brandSource->brand->only('id', 'title', 'images');
                $orderData['source'] = $data->brandSource->source->only('id', 'title', 'images');

                return $orderData;
            });
            if (isset($orders['meta'])) {
                $orders['total'] = $orders['meta']['total'] ?? null;
                $orders['per_page'] = $orders['meta']['per_page'] ?? null;
                $orders['current_page'] = $orders['meta']['current_page'] ?? null;
            }
            $data['orders']['all'] =  $orders;
        }
        if (isset($request['orders']['active'])) {
            $model = 'App\\Models\\Order';
            //permet de récupérer la liste des regions active filtrés
            $request['orders']['active']['where'] = ["column" => 'pickup_id', "value" => $pickup->id];
            $request['orders']['active']['inAccount'] = ["account_id", getAccountUser()->account_id];
            $orders = FilterController::searchs(new Request($request['orders']['active']), $model, ['id', 'code'], true, []);
            $orders['data'] = collect($orders['data'])->map(function ($data) {
                $orderData = $data->only('id', 'code', 'pickup_id', 'comment', 'order_id', 'created_at');
                $orderData['carriers'] = $data->city->activeCarriers->map(function ($carrier) {
                    return $carrier->only('id', 'title');
                });
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
                $orderData['discount'] = $data->discount;
                $orderData['brand'] = $data->brandSource->brand->only('id', 'title', 'images');
                $orderData['source'] = $data->brandSource->source->only('id', 'title', 'images');

                return $orderData;
            });
            $data['orders']['active'] =  $orders;
        }
        if (isset($request['orders']['inactive'])) {
            $model = 'App\\Models\\Order';
            //permet de récupérer la liste des regions inactive filtrés
            $request['orders']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['orders']['inactive']['where'] = ["column" => 'pickup_id', "value" => null];
            $request['orders']['inactive']['whereArray'] = ["column" => 'order_status_id', "values" => [4]];
            $orders = FilterController::searchs(new Request($request['orders']['inactive']), $model, ['id', 'code'], true, []);
            $orders['data'] = collect($orders['data'])->map(function ($data) {
                $orderData = $data->only('id', 'code', 'comment', 'pickup_id', 'order_id', 'created_at');
                $orderData['carriers'] = $data->city->activeCarriers->map(function ($carrier) {
                    return $carrier->only('id', 'title');
                });
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
                $orderData['discount'] = $data->discount;
                $orderData['brand'] = $data->brandSource->brand->only('id', 'title', 'images');
                $orderData['source'] = $data->brandSource->source->only('id', 'title', 'images');

                return $orderData;
            });
            $data['orders']['inactive'] =  $orders;
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
                    } elseif ($titleModel->statut == 2) {
                        $fail("not authorized");
                    }
                },
            ],
            '*.ordersToActive.*' => [
                'required',
                'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $phone = Order::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$phone) {
                        $fail("not exist");
                    }
                },
            ],
            '*.ordersToInactive.*' => [
                'required',
                'int',
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
            $pickup_only = collect($request)->only('id', 'collector_id', 'title', 'comment', 'statut');
            $pickup = Pickup::find($pickup_only['id']);
            if ($pickup_only['statut'] == 1 && $pickup->statut == 0) {
                $pickup->update($pickup_only->all());
                $this->validatePickup(new Request($pickup->orders->pluck('id')->toArray()), $pickup);
            } else {
                $pickup->update($pickup_only->all());
                $this->validatePickup(new Request($pickup->orders->pluck('id')->toArray()), $pickup);
            }
            $warehouse = Warehouse::find($pickup->warehouse_id);
            $from_warehouse = $warehouse->childWarehouses()->where('warehouse_type_id', 2)->first()->childWarehouses()->where(['warehouse_nature_id' => 1, 'warehouse_type_id' => 3])->first();
            if (isset($request['ordersToInactive'])) {
                $this->validatePickup(new Request($request['ordersToInactive']), $pickup, $canceled = 1);
            }
            if (isset($request['ordersToActive'])) {
                $orderPvas = $this->validatePickup(new Request($request['ordersToActive']), $pickup);
                if ($pickup->statut = 1) {
                    $productVariationAttributes = [];
                    collect($orderPvas)->flatMap(function ($orderPva) use (&$productVariationAttributes) {
                        print($orderPva->first()->product_variation_attribute_id);
                        if (isset($productVariationAttributes[$orderPva->first()->product_variation_attribute_id])) {
                            $productVariationAttributes[$orderPva->first()->product_variation_attribute_id]["quantity"] += $orderPva->first()->quantity;
                        } else {
                            $productVariationAttributes[$orderPva->first()->product_variation_attribute_id] = [
                                "id" => $orderPva->first()->product_variation_attribute_id,
                                "quantity" => $orderPva->first()->quantity,
                            ];
                        }
                        $productVariationAttributes[$orderPva->first()->product_variation_attribute_id]['orders'][] = $orderPva->first()->id;
                    });
                    $pickupData = [
                        'from_warehouse' => $from_warehouse->id,
                        'statut' => 1,
                        'productVariationAttributes' => collect($productVariationAttributes)->values()->toArray()
                    ];
                    $exitslip = ExitslipController::store(new Request($pickupData));
                    $pickup->update(['mouvement_id' => $exitslip->id]);
                }
            }

            $pickup = Pickup::with('orders')->find($pickup->id);
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

