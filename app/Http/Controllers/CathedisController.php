<?php

namespace App\Http\Controllers;

use App\Models\AccountCarrier;
use Illuminate\Support\Facades\Http;
use App\Models\City;
use App\Models\DefaultCarrier;
use App\Models\Order;
use App\Models\Pickup;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CathedisController extends Controller
{
    public static $url = 'https://api.cathedis.delivery';
    public function rest(Request $request, $entity, $id = null, $type = null)
    {
        if ($entity == 'login') {
            return $this->login();
        } elseif ($entity == 'tickets') {
            return $this->tickets($id, $type);
        } elseif ($entity == 'cities') {
            return $this->cities();
        } elseif ($entity == 'import') {
            return $this->import($id);
        } elseif ($entity == 'check_cities') {
            return $this->checkCities($request);
        }  elseif ($entity == 'update_cities') {
            return $this->updateCities();
        } else {
            return "productsuppliers";
        }

        return response()->json([
            'statut' => 1,
            'data ' => "Entity restored successfully",
        ]);
    }
    public function getToken()
    {
        $accountCarrier = AccountCarrier::where(['account_id' => getAccountUser()->account_id, 'carrier_id' => 19])->first();
        return $accountCarrier->token;
    }
    public function login()
    {
        $accountCarrier = AccountCarrier::where(['account_id' => getAccountUser()->account_id, 'carrier_id' => 19])->first();
        $url = self::$url . '/login.jsp';
        $data = [
            'username' => $accountCarrier->username,
            'password' => $accountCarrier->password
        ];
        $client = new Client();
        $response = $client->post($url, [
            'json' => $data,
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'http_errors' => false // Prevents Guzzle from throwing exceptions on 4xx and 5xx status codes
        ]);

        $result = $response->getBody()->getContents();
        $headers = $response->getHeader('Set-Cookie');
        $token = null;
        foreach ($headers as $cookie) {
            if (strpos($cookie, 'CSRF-TOKEN') !== false) {
                $parts = explode(';', $cookie);
                foreach ($parts as $part) {
                    if (strpos($part, 'CSRF-TOKEN') !== false) {
                        list(, $token) = explode('=', $part);
                        $token = "CSRF-TOKEN=" . trim($token);
                    }
                }
            }
        }
        $jsessionId = null;
        foreach ($headers as $header) {
            if (strpos($header, 'JSESSIONID') !== false) {
                $parts = explode(';', $header);
                foreach ($parts as $part) {
                    if (strpos($part, 'JSESSIONID') !== false) {
                        $jsessionId = $part;
                        break 2;
                    }
                }
            }
        }
        if ($token && $jsessionId) {
            $accountCarrier->update(['token' => $token . "; " . $jsessionId]);
        }
        return $token . "; " . $jsessionId;
    }
    public function cities()
    {
        $token = $this->getToken();
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Cookie' => $token,
        ])->get(self::$url . "/ws/rest/com.axelor.apps.base.db.City?limit=0&offset=0");
        $decoded = $response->json();
        if (!isset($decoded['status'])) {
            $token = $this->login();
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Cookie' => $token,
            ])->get(self::$url . "/ws/rest/com.axelor.apps.base.db.City?limit=0&offset=0");
        }

        $decoded = $response->json();
        if ($decoded['status'] == 0 && isset($decoded['status'])) {
            $cities = collect($decoded['data'])->map(function ($city) {
                $cityData = collect($city)->only('id', 'displayName', 'active');
                if ($cityData['active'])
                    return $cityData;
            })->filter()->values()->toArray();
            return [
                "statut" => 1,
                "data" => $cities
            ];
        }
        return [
            "statut" => 0,
            "data" => "probléme data"
        ];
    }

    public function updateCities()
    {

        $token = $this->login();
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Cookie' => $token,
        ])->get(self::$url . "/ws/rest/com.axelor.apps.base.db.City?limit=0&offset=0");
        $decoded = $response->json();
        if (isset($decoded['status']) && $decoded['status'] == 0) {
            $cities = collect($decoded['data'])->map(function ($defaultCity) {
                $city = DefaultCarrier::where('city_id_carrier', $defaultCity['id'])->where('carrier_id', 19)->first();
                if ($city) {
                    if ($defaultCity['active']) {
                        $city->update([
                            'statut' => 1,
                            'name' => $defaultCity['displayName'],
                        ]);
                        return $city->city_id;
                    } else {
                        $city->update([
                            'statut' => 0,
                            'name' => $defaultCity['displayName'],
                        ]);
                    }
                } else {
                    $city = DefaultCarrier::where('name', $defaultCity['displayName'])->where('carrier_id', 19)->first();
                    if ($city) {
                        if ($defaultCity['active']) {
                            $city->update([
                                'statut' => 1,
                                'city_id_carrier' => $defaultCity['id'],
                            ]);
                            return $city->city_id;
                        } else {
                            $city->update([
                                'statut' => 0,
                                'city_id_carrier' => $defaultCity['id'],
                            ]);
                        }
                    } else {
                        $city = City::where('title', 'like', "%{$defaultCity['displayName']}%")->first();
                        if ($city) {
                            $defaultCity = DefaultCarrier::create([
                                'carrier_id' => 19,
                                'city_id' => $city->id,
                                'name' => $defaultCity['displayName'],
                                'city_id_carrier' => $defaultCity['id'],
                                'price' => 35,
                                'return' => 0,
                                'delivery_time' => 1,
                            ]);
                            return $defaultCity->city_id;
                        } else {
                            return "ok";
                            $newCity = City::create([
                                'title' => $defaultCity['displayName'],
                            ]);
                            DefaultCarrier::create([
                                'carrier_id' => 19,
                                'city_id' => $newCity->id,
                                'name' => $defaultCity['displayName'],
                                'city_id_carrier' => $defaultCity['id'],
                                'price' => 35,
                                'return' => 0,
                                'delivery_time' => 1,
                            ]);
                        }
                    }
                }
            })->filter()->values()->toArray();
            return [
                "statut" => 1,
                "data" => []
            ];
        } else {
            return [
                "statut" => 0,
                "data" => "probléme de connexion"
            ];
        }
    }
    public function checkCities(Request $request)
    {
        $validator = Validator::make($request->except('_method'), [
            'orders.*' => [ // Validate title field
                'required', // Title is required
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $order = Order::where('id', $value)->whereIn('order_status_id', [1, 2, 3, 4])->first();
                    if (!$order) {
                        $fail("Déja envoyée");
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

        $cityUpdated = $this->updateCities();
        if (1 == $cityUpdated['statut']) {
            $orders = collect($request['orders'])->map(function ($orderId) {
                $order = Order::find($orderId);
                $cityExist = $order->city->defaultCarriers()->where(['carrier_id' => 19, 'statut' => 1])->first();
                if (!$cityExist)
                    return $cityExist;
            })->filter()->values()->toArray();
            return [
                "statut" => 1,
                "data" => $orders
            ];
        }
        return [
            "statut" => 0,
            "data" => "probléme data"
        ];
    }
    public function import($id)
    {
        $token = $this->login();
        $pickup = Pickup::find($id);
        $countvalid = 0;
        $countinvalid = 0;
        $codeinvalid = [];
        foreach ($pickup->orders as $order) {
            $total = 0;
            $products = $order->productVariationAttributes->map(function ($pva) use (&$total) {
                $total += $pva->pivot->price * $pva->pivot->quantity;
                $attributes = $pva->variationAttribute->childVariationAttributes->map(function ($child) {
                    return $child->attribute->title;
                })->toArray();
                $productInfo = [
                    'id' => $pva->id,
                    'price' => $pva->pivot->price,
                    'quantity' => $pva->pivot->quantity,
                    'product' => $pva->product->title . ' - ' . implode(' - ', $attributes),
                ];
                return $productInfo;
            });
            if (!$order->shipping_code) {
                $ville = "";
                $secteur = "";
                $defaultCity = $pickup->carrier->defaultCarriers->where('city_id', $order->city_id)->first();
                if ($defaultCity) {
                    $ville = $defaultCity->name;
                    $secteur = $defaultCity->name;
                } else {
                    $ville = $order->city->title;
                    $secteur = $order->city->title;
                }

                $data = [
                    "action" => "delivery.api.save",
                    "data" => [
                        "context" => [
                            "delivery" => [
                                "recipient" => $order->customer->name . '-' . $order->code,
                                "city" => $ville,
                                "sector" => $secteur,
                                "phone" => $order->phones->first()->title,
                                "amount" => (string) ($total - $order->discount),
                                "caution" => "0",
                                "fragile" => "0",
                                "declaredValue" => "0",
                                "address" => $order->addresses->first()->title,
                                "nomOrder" => $order->code,
                                "comment" => $order->note,
                                "rangeWeight" => "Entre 1.2 Kg et 5 Kg",
                                "weight" => "1",
                                "width" => "0",
                                "length" => "0",
                                "height" => "0",
                                "subject" => implode(' \n', $products->pluck('product')->toArray()),
                                "paymentType" => "ESPECES",
                                "deliveryType" => "Livraison CRBT",
                                "packageCount" => "1",
                                "allowOpening" => "1"
                            ]
                        ]
                    ]
                ];

                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Cookie' => $token,
                ])->post(self::$url . "/ws/action", $data);

                $decoded = $response->json();
                if ($decoded['status'] == 0 && isset($decoded['data'][0]['values']['delivery'])) {
                    $response = $decoded['data'][0]['values']['delivery'];
                    $order->update(['shipping_code' => $response['id']]);

                    $countvalid++;
                } else {
                    $countinvalid++;
                    $codeinvalid[] = $order->code;
                }
            }
        }

        if ($countinvalid > 0) {
            $msg = "Les codes ";
            $msg .= implode(', ', $codeinvalid);
            $msg .= " ont des problèmes d'importation";
            return [
                'statut' => 0,
                'data' => $msg
            ];
        } else {
            return $this->tickets($id);
        }
    }
    public function tickets($id, $type = null)
    {
        if (!$type)
            $type = 'a4';
        $pdfType = "delivery.print.bl";
        if ($type == "landscape") {
            $pdfType = "delivery.print.bl.A4-by-4";
        } elseif ($type == "4x4") {
            $pdfType = "delivery.print.bl4x4";
        }
        $pickup = Pickup::with('orders')->find($id);
        $token = $this->getToken();
        $ids = [];
        $ids = $pickup->orders->map(function ($order) {
            return intval($order->shipping_code);
        })->filter()->values()->toArray();
        $datas = [
            "action" => $pdfType,
            "data" => [
                "context" => [
                    "_ids" => $ids,
                    "_model" => "com.tracker.delivery.db.Delivery"
                ]
            ]
        ];
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Cookie' => $token,
        ])->post(self::$url . "/ws/action", $datas);

        $decoded = $response->json();
        return $decoded;
        if (!isset($decoded['status'])) {
            $this->login();
            return [
                'statut' => 0,
                'data' => 'login problem, try again'
            ];
        } elseif ($decoded['status'] !== 0) {
            return redirect()->route('shippings.index')->withErrors($decoded['data'][0]['error']);
        } else {
            if (isset($decoded['data'][0]['error'])) {
                return redirect()->route('shippings.index')->withErrors($decoded['data'][0]['error']);
            } else {
                $file = "app/public/cathedis/pickup-{$type}-{$id}.pdf";
                $source = self::$url . "/" . $decoded['data'][0]['view']['views'][0]['name']; // Target file to download
                $destination = storage_path($file); // Save to this file
                $timeout = 30; // 30 seconds CURL timeout, increase if downloading large file

                $fh = fopen($destination, "w") or die("ERROR opening " . $destination);

                $client = new \GuzzleHttp\Client();
                $client->request('GET', $source, [
                    'sink' => $fh,
                    'timeout' => $timeout,
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Cookie' => $token,
                    ],
                ]);
                return [
                    'statut' => 1,
                    'data' => "https://space.metapixel.ma/storage/{$file}"
                ];
            }
        }
    }
    /*
    public function orderStatuses()
    {
        $expenses = Expense::whereIn('account_user_id', AccountUser::where('account_id', getAccountUser()->account_id)->get()->pluck('id')->toArray())->get();
        foreach ($expenses as $key => $expense) {
            $transactionData = [
                "code" => DefaultCodeController::getAccountCode('Transaction', getAccountUser()->account_id),
                "transaction_type" => "App\Models\Expense",
                "transaction_id" => $expense->id,
                "amount" => $expense->amount,
                "transaction_type_id" => 2,
                "statut" => 1,
                "created_at" => $expense->created_at,
                "updated_at" => $expense->updated_at,
                "account_user_id" => getAccountUser()->id,
            ];
            $transaction = Transaction::create($transactionData);
        }
        return $expenses;

        /*    $accountUsers = AccountUser::with('modelRoles')->where('account_id', getAccountUser()->account_id)
            ->where('statut', 1)
            ->whereHas('modelRoles', function ($query) {
                $query->where('id', 1)->orWhere('id', 2);
            })
            ->get();*//*
        $datedepart = Order::orderBy('created_at')->first()->created_at;
        $startDate = Carbon::parse($datedepart);
        $endDate = Carbon::now();

        // Calculate the number of months
        $numberOfMonths = $startDate->diffInMonths($endDate);

        // Get the first day of each month between the start and end dates
        $firstDaysOfMonths = [];
        $currentDate = $startDate->copy()->firstOfMonth();
        $lastDayDate = $startDate->copy()->lastOfMonth();

        while ($currentDate <= $endDate) {
            $firstDaysOfMonths[] = $currentDate->toDateString();
            $lastDaysOfMonths[] = $lastDayDate->toDateString();
            $currentDate->addMonth()->firstOfMonth();
            $lastDayDate->addMonth()->lastOfMonth();
        }

        // return response()->json([
        //     'number_of_months' => $numberOfMonths,
        //     'first_days_of_months' => $firstDaysOfMonths,
        //     'last_days_of_months' => $lastDaysOfMonths,
        // ]);
        for ($i = 0; $i <= $numberOfMonths; $i++) {
            $expenseData = [
                "code" => DefaultCodeController::getAccountCode('Expense', getAccountUser()->account_id),
                "description" => 'Facebook Ads',
                "date" => $lastDaysOfMonths[$i],
                "amount" => 10000,
                "expense_type_id" => 1,
                "statut" => 1,
                "created_at" => Carbon::parse($firstDaysOfMonths[$i]),
                "updated_at" => Carbon::parse($firstDaysOfMonths[$i]),
                "account_user_id" => getAccountUser()->id,
            ];
            $expense = Expense::create($expenseData);
        }
        // return $accountUsers;*//*
    }

    public function attribute()
    {
        $responses = Http::get('https://app.meta-pixel.net/woocommerce/sizes')->json();
        $data = [];
        foreach ($responses as $response) {
            $data[] = [
                'title' => $response['title'],
                'types_attribute_id' => 14,
                'statut' => 1
            ];
        }
        return AttributeController::store(new Request($data));
    }
    
    public function regions()
    {
        $responses = Http::get('https://app.meta-pixel.net/woocommerce/regions')->json();
        $data = [];
        foreach ($responses as $response) {
            $data[] = [
                'id' => $response['id'],
                'title' => $response['title'],
                'titlear' => $response['titlear'],
                'statut' => $response['statut'],
            ];
        }
        return RegionController::store(new Request($data));
    }

    public function supplierOrders()
    {
    }

    public function suppliers()
    {
        $responses = Http::get('https://app.meta-pixel.net/woocommerce/suppliers')->json();
        $data = [];
        foreach ($responses as $response) {
            $warehouses = 'App\\Models\\Warehouse'::where(['account_id' => getAccountUser()->account_id, 'warehouse_id' => null])->get()->pluck('id')->toArray();
            $data[] = [
                'title' => $response['title'],
                'code' => $response['id'],
                'statut' => $response['statut'],
                'phones' => [['title' => $response['phone']['title'], 'phoneTypes' => [1]]],
                'addresses' => [['title' => $response['adress']['title'], 'city_id' => 1]],
                'warehouses' => $warehouses
            ];
        }
        return SupplierController::store(new Request($data));

        // $suppliers=Supplier::where('account_id',getAccountUser()->account_id)->get();
        // return $suppliers->map(function($supplier){
        //     $pvas=ProductVariationAttribute::where('account_id',getAccountUser()->account_id)->get();
        //     return $pvas->map(function($pva)use($supplier){
        //         $supplier->productVariationAttributes()->attach($pva->id, ['price'=>95,'statut'=>1,'account_id'=>getAccountUser()->account_id,'created_at'=>now(),"updated_at"=>now()]);
        //     });
        // });
    }
    //hna ra sayabte function customers f systémes dial app o sayabt import f customers folderf postman 
    public function customers()
    {

        $responses = Http::get('https://app.meta-pixel.net/woocommerce/customers/')->json();
        $data = [];
        foreach ($responses as $key => $response) {
            $customer = Customer::where('comment', $response['id'])->first();
            if (!$customer) {
                $data[$key] = [
                    "customer_type_id" => 1,
                    "name" => $response['name'],
                    "note" => $response['note'],
                    "statut" => 1,
                    "facebook" => $response['facebook'],
                    "comment" => $response['id'],
                    "address" => $response['address'],
                    "created_at" => "2024-02-21T17=>59=>55.000000Z",
                    "updated_at" => "2024-02-21T18=>09=>22.000000Z",
                    'code' => $response['id'],
                    'statut' => 1,
                    'addresses' => [['title' => $response['adress']['title'], 'city_id' => $response['adress']['city_id']]],
                ];
                if (isset($response['phone']))
                    $data[$key]["phones"] = [['title' => $response['phone']['title'], 'phoneTypes' => [1, 3]]];
            }
        }
        if ($data)
            return CustomerController::store(new Request($data));
    }
    public function orderSuppliers()
    {
        $responses = Http::get('https://app.meta-pixel.net/woocommerce/receipts')->json();
        $datas = [];
        collect($responses)->map(function ($response) use (&$datas) {
            $supplier = Supplier::where('code', $response['supplier_id'])->first();
            $datas[] = [
                "code" => $response['id'],
                "supplier_id" => $supplier->id,
                "statut" => $response['statut'],
                "created_at" => $response['created'],
                "updated_at" => $response['modified'],
                "warehouse_id" => 29,
            ];
            return $response;
        });
        SupplierReceiptController::store(new Request($datas));
        $responses = Http::get('https://app.meta-pixel.net/woocommerce/supplierorders')->json();
        $datas = [];
        collect($responses)->map(function ($response, $key) use (&$datas) {
            $supplier = Supplier::where('code', $response['supplier_id'])->first();
            $productVariationAttributes = [];
            foreach ($response['supporderproducts'] as $supporder) {
                $pva = ProductVariationAttribute::where('barcode', $supporder['productsize_id'])->first();
                $receipt = SupplierReceipt::where('code', $supporder['receipt_id'])->first();
                if ($pva && $receipt)
                    $productVariationAttributes[] = [
                        "id" => $pva->id,
                        "supplier_receipt_id" => $receipt->id,
                        "quantity" => $supporder['quantity'],
                        "price" => $supporder['price']
                    ];
            }
            $datas[] = [
                "supplier_id" => $supplier->id,
                "statut" => $response['statut'],
                "warehouse_id" => 27,
                "productVariationAttributes" => $productVariationAttributes,
            ];
        });
        return SupplierOrderController::store(new Request($datas));
    }
    public function brandsources()
    {
        $responses = Http::get('https://app.meta-pixel.net/woocommerce/brandsources')->json();
        $sources = [];
        $brands = [];
        foreach ($responses as $response) {
            $brands[] = [
                'code' => $response['source']['id'],
                'title' => $response['source']['title'],
                'statut' => $response['source']['statut'],
            ];
            $sources[] = [
                'code' => $response['data']['id'],
                'title' => $response['data']['title'],
                'statut' => $response['data']['statut'],
            ];
        }
        //save brands
        BrandController::store(new Request(collect($brands)->unique()->values()->toArray()));
        //save sources
        SourceController::store(new Request(collect($sources)->unique()->values()->toArray()));

        foreach ($responses as $response) {
            $source = 'App\\Models\\Source'::where('code', $response['data_id'])->get();
            $brand = 'App\\Models\\Brand'::where('code', $response['source_id'])->get();

            $brandSource = [
                'account_id' => getAccountUser()->account_id,
                'source_id' => $source->first()->id,
                'brand_id' => $brand->first()->id,
                'statut' => $response['id'],
            ];
            $hasBrandSource = "App\\Models\\BrandSource"::where([
                'account_id' => getAccountUser()->account_id,
                'source_id' => $source->first()->id,
                'brand_id' => $brand->first()->id,
            ])->get()->first();
            if ($hasBrandSource)
                $saved = "App\\Models\\BrandSource"::create($brandSource);
        }
    }

    public function carriers()
    {
        $responses = Http::get('https://app.meta-pixel.net/woocommerce/carriers')->json();
        $data = [];
        foreach ($responses as $response) {
            $cities = [];
            foreach ($response['defaultcarriers'] as $city) {
                $cities[] = [
                    'id' => $city['city_id'],
                    'name' => $city['title'],
                    'price' => $city['price'],
                    'return' => $city['returne'],
                    'delivery_time' => $city['deliverytime'],
                ];
            }
            $data[] = [
                'title' => $response['title'],
                'email' => $response['email'],
                'trackinglink' => $response['trackinglink'],
                'autocode' => $response['autocode'],
                'comment' => $response['comment'],
                'statut' => $response['statut'],
                'phones' => [['title' => $response['phone']['title'], 'phoneTypes' => [1]]],
                "cities" => $cities
            ];
        }
        return CarrierController::store(new Request($data));
    }
    public function users()
    {
        $responses = Http::get('https://app.meta-pixel.net/woocommerce/users')->json();

        $data = [];
        foreach ($responses as $response) {
            $phones = [];
            if (isset($response['phones'])) {
                foreach ($response['phones'] as $phone)
                    $phones[] = ['title' => $phone['title'], 'phoneTypes' => [1]];
            }
            $data[] = [
                'firstname' => $response['firstname'],
                'lastname' => $response['lastname'],
                'cin' => $response['id'],
                'name' => $response['username'],
                'password' => "password",
                'password_confirmation' => "password",
                'email' => $response['email'],
                'statut' => $response['statut'],
                'created_at' => $response['created'],
                'updated_at' => $response['modified'],
                "warehouses" => [27],
                "account_userid" => $response['accountusers'][0]['id'],
                'phones' => $phones,
            ];
        }
        return UserController::store(new Request($data));
    }


    public function orders()
    {
        $responses = Http::get('https://app.meta-pixel.net/woocommerce/importorders')->json();
        $data = [];
        $data1 = [];
        foreach ($responses as $key => $response) {
            $customer = Customer::where('comment', $response['customer_id'])->first();
            if (!$customer) {
                $datasource = $response['datasource_id'] == 99 ? 98 : $response['datasource_id'];
                $brandSource = BrandSource::where('statut', $datasource)->first();
                if (!$brandSource)
                    return $response;
                $pickupdId = null;
                if ($response['shipping_id'])
                    $pickupdId = Pickup::where('comment', $response['shipping_id'])->first()->id;
                $shipmentId = null;
                if ($response['invoice_id'])
                    $shipmentId = Shipment::where('comment', $response['invoice_id'])->first()->id;

                $accountUserId = AccountUser::where('code', $response['accountuser_id'])->first()->id;
                $orderStatus = OrderStatus::where('todelete', $response['statut_id'])->first()->id;

                if (isset($response['cityaccount']['city_id'])) {

                    $data[$key] = [
                        'brand_source_id' => $brandSource->id,
                        'pickup_id' => $pickupdId,
                        'shipment_id' => $shipmentId,
                        'code' => $response['code'],
                        'comment' => $response['id'],
                        'discount' => $response['discount'] ? $response['discount'] : 0,
                        'carrier_price' => $response['carrierprice'] ? $response['carrierprice'] : 0,
                        'real_carrier_price' => ($response['realcarrierprice']) ? $response['realcarrierprice'] : ($response['carrierprice'] ? $response['carrierprice'] : 0),
                        'created_at' => $response['created'],
                        'updated_at' => $response['modified'],
                        'city_id' => $response['cityaccount']['city_id'],
                        'shipping_code' => $response['shippingcode'],
                        'warehouse_id' => 27,
                        'payment_type_id' => 1,
                        'payment_method_id' => 1,
                        'account_user_id' => $accountUserId,
                        'order_status_id' => $orderStatus
                    ];
                    $data[$key]['customer'] = [
                        "customer_type_id" => 1,
                        "name" => $response['customer']['name'],
                        "note" => $response['customer']['note'],
                        "statut" => 1,
                        "facebook" => $response['customer']['facebook'],
                        "comment" => $response['customer']['id'],
                        "address" => $response['customer']['address'],
                        "created_at" => "2024-02-21T17=>59=>55.000000Z",
                        "updated_at" => "2024-02-21T18=>09=>22.000000Z",
                        'code' => $response['customer']['id'],
                        'statut' => 1,
                        'addresses' => [['title' => $response['customer']['adress']['title'], 'city_id' => $response['cityaccount']['city_id']]],
                    ];
                    $data[$key]['adresse'] = $data[$key]['customer']["addresses"] ? $data[$key]['customer']["addresses"][0]['title'] : "";

                    if (isset($response['customer']['phone']))
                        $data[$key]['customer']["phones"] = [['title' => $response['customer']['phone']['title'], 'phoneTypes' => [1, 3]]];
                }
            } else {
                $datasource = $response['datasource_id'] == 99 ? 98 : $response['datasource_id'];
                $brandSource = BrandSource::where('statut', $datasource)->first();
                if (!$brandSource)
                    return $response;
                $pickupdId = null;
                if ($response['shipping_id'])
                    $pickupdId = Pickup::where('comment', $response['shipping_id'])->first()->id;
                $shipmentId = null;
                if ($response['invoice_id'])
                    $shipmentId = Shipment::where('comment', $response['invoice_id'])->first()->id;

                $accountUserId = AccountUser::where('code', $response['accountuser_id'])->first()->id;
                $orderStatus = OrderStatus::where('todelete', $response['statut_id'])->first()->id;
                if (isset($response['cityaccount']['city_id'])) {
                    $data1[] = [
                        'customer_id' => $customer->id,
                        'adresse' => $customer->addresses->first() ? $customer->addresses->first()->title : "",
                        'brand_source_id' => $brandSource->id,
                        'pickup_id' => $pickupdId,
                        'shipment_id' => $shipmentId,
                        'code' => $response['code'],
                        'comment' => $response['id'],
                        'discount' => $response['discount'] ? $response['discount'] : 0,
                        'carrier_price' => $response['carrierprice'] ? $response['carrierprice'] : 0,
                        'real_carrier_price' => ($response['realcarrierprice']) ? $response['realcarrierprice'] : ($response['carrierprice'] ? $response['carrierprice'] : 0),
                        'created_at' => $response['created'],
                        'updated_at' => $response['modified'],
                        'city_id' => $response['cityaccount']['city_id'],
                        'shipping_code' => $response['shippingcode'],
                        'warehouse_id' => 27,
                        'payment_type_id' => 1,
                        'payment_method_id' => 1,
                        'account_user_id' => $accountUserId,
                        'order_status_id' => $orderStatus,
                    ];
                }
            }
        }
        OrderController::store(new Request($data), $isImport = 1);
        OrderController::store(new Request($data1), $isImport = 2);
        return "ok";
    }
    public function orderPvas()
    {
        $responses = Http::get('https://app.meta-pixel.net/woocommerce/orderproducts')->json();
        $notDoneOrder = [];
        $notDonePva = [];
        foreach ($responses as $response) {
            $order = Order::where('comment', $response['order_id'])->first();
            if ($order) {
                $pva = ProductVariationAttribute::where('barcode', $response['productsize_id'])->first();
                if ($pva) {
                    $data = [
                        'order_id' => $order->id,
                        'product_variation_attribute_id' => $pva->id,
                        'price' => $response['price'],
                        'initial_price' => $response['id'],
                        'realprice' => $response['realprice'],
                        'quantity' => $response['quantity'],
                        'created_at' => $response['created'],
                        'updated_at' => $response['modified'],
                        'account_user_id' => 80,
                        'order_status_id' => $order->order_status_id,
                        'principale' => 0
                    ];
                    $createOrderPva = OrderPva::create($data);
                    $notDoneOrder[] = $createOrderPva->id;
                } else {
                    $notDonePva[] = $response['id'];
                }
            }
            //303
        }
        return ["done" => $notDoneOrder, "orderPva" => $notDonePva];
    }
    public function orderComments()
    {
        $responses = Http::get('https://app.meta-pixel.net/woocommerce/ordercomments')->json();
        $itsOk = 0;
        $datas = [];
        // les status li mqadine 1 2 3 4 5 6 7 8 9 10 11
        // le reste a voir
        foreach ($responses as $key => $response) {
            $user = User::where('email', $response['accountuser']['user']['email'])->first();
            $accountUser = 80;
            if ($user) {
                $accountUser = $user->accountUsers->first()->id;
            }
            $order = Order::where('comment', $response['order_id'])->first();
            if ($order) {
                $comment_id = 64;
                $statut = 6;
                if ($response['subcomment_id'] == 7) {
                    $comment_id = 34;
                    $statut = 9;
                } elseif ($response['subcomment_id'] == 12) {
                    $comment_id = 31;
                } elseif ($response['subcomment_id'] == 51) {
                    $comment_id = 34;
                    $statut = 9;
                } elseif ($response['subcomment_id'] == 56) {
                    $comment_id = 63;
                } elseif ($response['subcomment_id'] == 57) {
                    $comment_id = 28;
                } elseif ($response['subcomment_id'] == 58) {
                    $comment_id = 29;
                } elseif ($response['subcomment_id'] == 60) {
                    $comment_id = 64;
                } elseif ($response['subcomment_id'] == 61) {
                    $comment_id = 64;
                } elseif ($response['subcomment_id'] == 64) {
                    $comment_id = 29;
                } elseif ($response['subcomment_id'] == 65) {
                    $comment_id = 65;
                } elseif ($response['subcomment_id'] == 66) {
                    $comment_id = 31;
                } elseif ($response['subcomment_id'] == 69) {
                    $comment_id = 28;
                } elseif ($response['subcomment_id'] == 82) {
                    $comment_id = 33;
                    $statut = 9;
                }
                // if (in_array($response['subcomment_id'], [59, 1, 84])) {
                //     $comment_id = 38;
                // }

                $data = [
                    'order_id' => $order->id,
                    'comment_id' => $comment_id,
                    'title' => $response['title'],
                    'postpone' => $response['postpone'],
                    'account_user_id' => $accountUser,
                    'order_status_id' => $statut,
                    'created_at' => $response['created'],
                    'updated_at' => $response['modified'],
                ];
                $orderComment = OrderComment::create($data);
            }
        }
        return "ok";
    }

    public function invoices()
    {
        $responses = Http::get('https://app.meta-pixel.net/woocommerce/invoices')->json();
        $data[] = [
            "comment" => "rag",
            "givenAmount" => "64",
            "statut" => 1,
            "loading" => false,
            "selectedPaymentCarrier" => null,
            "shipping_code" => "fac45",
            "shipment_type_id" => 1,
            "given_amount" => "64",
            "warehouse_id" => 5,
            "carrier_id" => 5
        ];
        $data = [];
        foreach ($responses as $response) {

            if (!isset($response["accountcarrier"]["carrier"])) {
                $carrierId = $response["accountcarrier_id"] == 7 ? 18 : 19;
            } else {
                $carrierId = Carrier::where('title', $response["accountcarrier"]['carrier']['title'])->first()->id;
            }
            $data[] = [
                'shipping_code' => $response['title'],
                'carrier_id' => $carrierId,
                "warehouse_id" => 27,
                'created_at' => $response['created'],
                'updated_at' => $response['modified'],
                'statut' => $response['statut'],
                'comment' => $response['id'],
                'shipment_type_id' => $response['type'],
            ];
        }
        return ShipmentController::store(new Request($data));
    }
    public function pickups()
    {
        $responses = Http::get('https://app.meta-pixel.net/woocommerce/shippings')->json();
        $data = [];
        foreach ($responses as $response) {
            if (!isset($response["accountcarrier"]["carrier"])) {
                $carrierId = $response["accountcarrier_id"] == 7 ? 18 : 19;
            } else {
                $carrierId = Carrier::where('title', $response["accountcarrier"]['carrier']['title'])->first()->id;
            }
            $data[] = [
                'code' => $response['title'],
                'carrier_id' => $carrierId,
                "warehouse_id" => 27,
                'created_at' => $response['created'],
                'updated_at' => $response['modified'],
                'statut' => $response['statut'],
                'comment' => $response['id'],
            ];
        }
        return PickupController::store(new Request($data));
    }

    public function products()
    {
        $responses = Http::get('https://app.meta-pixel.net/woocommerce/products')->json();
        $data = [];
        foreach ($responses as $response) {
            $sizes = collect($response['sizes'])->pluck('title')->toArray();
            $attributes = 'App\\Models\\Attribute'::whereIn('title', $sizes)->where('account_user_id', getAccountUser()->id)->get()->pluck('id')->toArray();
            $data[] = [
                'title' => $response['title'],
                'reference' => $response['code'],
                'price' => $response['price'],
                'product_type_id' => 1,
                'default_measurement_id' => 1,
                'attributes' => $attributes,
                'statut' => 1,
            ];
        }
        // return ProductController::store(new Request($data));
        return $this->product();
    }

    public function product()
    {
        $responses = Http::get('https://app.meta-pixel.net/woocommerce/products')->json();
        $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->get()->pluck('id')->toArray();
        foreach ($responses as $response) {
            $product = Product::where('title', $response['title'],)->whereIn('account_user_id', $accountUsers)->first();
            $pvaCodeSizes = $product->productVariationAttributes->flatMap(function ($pva) use ($response, $product) {
                return $pva->variationAttribute->childVariationAttributes->map(function ($childVa) use ($response, $product) {
                    $variationBySize = [];
                    foreach ($response["sizes"] as $size) {
                        if ($size['title'] == $childVa->attribute->title)
                            $variationBySize = ['title' => $childVa->attribute->title, 'product_variation_attribute' => $childVa->parentVariationAttribute->productVariationAttributes->where('product_id', $product->id)->first()->id, 'variation_attribute' => $childVa->variation_attribute_id, 'productsizeId' => $size['productsizeId']];
                    }
                    return $variationBySize;
                });
            });
            $pvaCodeSizes->map(function ($size) {
                $pva = ProductVariationAttribute::find($size['product_variation_attribute']);
                $pva->update(['barcode' => $size['productsizeId']]);
                return $pva;
            });
        }
    }*/
}
