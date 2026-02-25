<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Attribute;
use App\Models\BrandSource;
use App\Models\Carrier;
use App\Models\Comment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderComment;
use App\Models\OrderStatus;
use App\Models\Pickup;
use App\Models\ProductVariationAttribute;
use App\Models\Product;
use App\Models\Shipment;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Auth;
use App\Models\User; // Le modèle User

class ImportController extends Controller
{
    public function import(Request $requests,$entity, $id = null)
    {
        
        if ($entity == 'orders') {
            return $this->orders();
        }elseif ($entity == 'sync') {
            return $this->sync();
        }elseif ($entity == 'double') {
            return $this->double();
        }elseif ($entity == 'apporders') {
            return $this->appOrders($requests);
        }elseif ($entity == 'pickups') {
            return $this->pickups($id);
        }elseif ($entity == 'customers') {
            return $this->customers();
        }elseif ($entity == 'product') {
            return $this->products();
        }elseif ($entity == 'syncpickup') {
            return $this->syncPickup($id);
        } else {
            return "productsuppliers";
        }

        return response()->json([
            'statut' => 1,
            'data ' => "Entity restored successfully",
        ]);
    }
    public static function syncPickup($id){
        $pickup=Pickup::find($id);

        $orders=$pickup->orders->pluck('comment')->toArray();
        $data=[
            'accountcarrier_id'=>4,
            'code'=>$pickup->title,
            'orders'=>$orders
        ];
        $response = Http::post('https://space.meta-pixel.net/woocommerce/syncpickup/', $data);
        return $response;

    }
    public function sync(){
        // Authentifier un utilisateur spécifique, par exemple un administrateur
        $admin = User::where('email', 'achkar.abder@gmail.com')->first(); // Remplace 'admin' par le critère correct
        Auth::guard('web')->login($admin);
        //sychronisation des produits
        $lastPva=ProductVariationAttribute::orderBy('barcode','desc')->first();
        $checkPvaNew=$this->products($lastPva);
        //sychronisation des bls
        $lastPickup = Pickup::whereRaw('comment REGEXP "^[0-9]+$"')
                 ->orderByRaw('CAST(comment AS UNSIGNED) DESC')
                 ->first();
        $checkNewPickups=$this->pickups($lastPickup->comment);
        
        //sychronisation des shipments
        $lastShipment = Shipment::whereRaw('comment REGEXP "^[0-9]+$"')
                 ->orderByRaw('CAST(comment AS UNSIGNED) DESC')
                 ->first();
        $checkNewShipments=$this->invoices($lastShipment->comment);

        //sychronisation des commandes
        $lastOrder = Order::whereRaw('comment REGEXP "^[0-9]+$"')
                 ->orderByRaw('CAST(comment AS UNSIGNED) DESC')
                 ->first();
        $checkNewOrders=$this->orders($lastOrder->comment);
        //sychronisation des commentaires
        $lastComment = OrderComment::whereRaw('sync REGEXP "^[0-9]+$"')
                 ->orderByRaw('CAST(sync AS UNSIGNED) DESC')
                 ->first();
        $checkNewComments=$this->orderComments($lastComment->sync);
        Auth::guard('web')->logout();
    }
    public function orderComments($lastId)
    {
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/ordercomments/'.$lastId)->json();
        $itsOk = 0;
        $datas = [];

        // les status li mqadine 1 2 3 4 5 6 7 8 9 10 11
        // le reste a voir
        foreach ($responses as $key => $response) {
            $accountUser = AccountUser::where('code', $response['accountuser_id'])->first()->id;
            $order = Order::where('comment', $response['order_id'])->first();
            if($order){
                $response['statut_id']=($response['statut_id']==10 || $response['statut_id']==11)?4:$response['statut_id'];
                $orderStatus = ($response['statut_id']==5 && $order->pickup_id==null)? 2: OrderStatus::where('todelete', $response['statut_id'])->first()->id;
                if ($order) {
                    $comment_id = 64;
                    if ($response['subcomment_id'] == 7) {
                        $comment_id = 34;
                    } elseif ($response['subcomment_id'] == 12) {
                        $comment_id = 31;
                    } elseif ($response['subcomment_id'] == 51) {
                        $comment_id = 34;
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
                    }
                    if (in_array($response['subcomment_id'], [59, 1, 84])) {
                        $comment_id = 38;
                    }

                    $data = [
                        'order_id' => $order->id,
                        'comment_id' => $comment_id,
                        'title' => $response['title'],
                        'postpone' => $response['postpone'],
                        'account_user_id' => $accountUser,
                        'order_status_id' => $orderStatus,
                        'created_at' => $response['created'],
                        'updated_at' => $response['modified'],
                        'sync' => $response['id'],
                    ];
                    $orderComment = OrderComment::create($data);
                    /*$pickupdId = null;
                    if ($response['order']['shipping_id'])
                        $pickupdId = Pickup::where('comment', $response['shipping_id'])->first()->id;
                    $shipmentId = null;
                    if ($response['order']['invoice_id'])
                        $shipmentId = Shipment::where('comment', $response['invoice_id'])->first()->id;*/
                    $order->update([
                        'order_status_id'=>$orderComment->order_status_id,
                    ]);
                    $order->orderStatuses()->syncWithoutDetaching([$orderComment->order_status_id=>['account_user_id' => $orderComment->account_user_id, 'statut' => 1, 'created_at' => $orderComment->created_at, 'updated_at' => $orderComment->updated_at]]);
                }
            }
        }
        return "ok";
    }
    public function customers()
    {

        $responses = Http::get('https://space.meta-pixel.net/woocommerce/customers/')->json();
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
    public function orders($lastId=null)
    {
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/importorders/'.$lastId)->json();
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
                $orderStatus = ($response['statut_id']==5 && $response['shipping_id']==null)? 2: OrderStatus::where('todelete', $response['statut_id'])->first()->id;
                $pvaData=[];
                foreach ($response['orderproducts'] as $orderProduct) {
                    $pva=ProductVariationAttribute::where('barcode',$orderProduct['productsize_id'])->first();
                    if($pva)
                        $pvaData[] = ['id' => $pva->id, 'price' => $orderProduct['price'], 'quantity' => $orderProduct['quantity']];
                    
                }
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
                        'order_status_id' => $orderStatus,
                        'order_pva' => $pvaData
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
                $orderStatus = ($response['statut_id']==5 && $response['shipping_id']==null)? 2: OrderStatus::where('todelete', $response['statut_id'])->first()->id;
                $pvaData=[];
                foreach ($response['orderproducts'] as $orderProduct) {
                    $pva=ProductVariationAttribute::where('barcode',$orderProduct['productsize_id'])->first();
                    $pvaData[] = ['id' => $pva->id, 'price' => $orderProduct['price'], 'quantity' => $orderProduct['quantity']];
                    
                }
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
                        'order_pva' => $pvaData
                    ];
                }
            }
        }
        OrderController::store(new Request($data1), $isImport = 2);
        OrderController::store(new Request($data), $isImport = 1);
        return "Importation Done";
    }
    public function invoices($lastId)
    {
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/invoices/'.$lastId)->json();
        $data = [];
        if($responses){
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
    }
    public function pickups($lastId)
    {
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/shippings/'.$lastId)->json();
        $data = [];
        if($responses){
            foreach ($responses as $response) {
                if (!isset($response["accountcarrier"]["carrier"])) {
                    $carrierId = $response["accountcarrier_id"] == 7 ? 18 : 19;
                } else {
                    $carrierId = Carrier::where('title', $response["accountcarrier"]['carrier']['title'])->first()->id;
                }
                $orders=[];
                if($response['orders']){
                    foreach ($response['orders'] as $key => $orderData) {
                        $order=Order::where('comment',$orderData['id'])->first();
                        if($order)
                            $orders[]=$order->id;
                    }
                }
                $data[] = [
                    'code' => $response['title'],
                    'carrier_id' => $carrierId,
                    "warehouse_id" => 27,
                    'created_at' => $response['created'],
                    'updated_at' => $response['modified'],
                    'statut' => $response['statut'],
                    'comment' => $response['id'],
                    'orders' => $orders,
                ];
            }
            return PickupController::store(new Request($data));
        }
    }
    public function products($lastId = null)
    {
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/products/'.$lastId)->json();
        if($responses){
            $accountUsers=AccountUser::where('account_id',getAccountUser()->account_id)->get()->pluck('id')->toArray();
            $data = [];
            foreach ($responses as $response) {
                $sizes = collect($response['sizes'])->pluck('title')->toArray();
                $attributes = 'App\\Models\\Attribute'::whereIn('account_user_id', $accountUsers)->whereIn('title', $sizes)->get()->pluck('id')->toArray();
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
            ProductController::store(new Request($data));
            $this->product($lastId);
        }
    }
    public function product($lastId = null)
    {
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/products/'.$lastId)->json();
        $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->get()->pluck('id')->toArray();
        foreach ($responses as $response) {
            $product = Product::where('title', $response['title'])->whereIn('account_user_id', $accountUsers)->first();
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

            $pvas=$pvaCodeSizes->map(function ($size) {
                $pva = ProductVariationAttribute::find($size['product_variation_attribute']);
                $pva->update(['barcode' => $size['productsizeId']]);
                return $pva;
            });
        }
    }
    public function orders_website()
    {
        $consumer_key = "ck_24b91172606b51b191e4797252710c54402d93ab";
        $consumer_secret = "cs_05f60c41fda8b2bdc8d7a18dd8465d48e044dbf0";
        $url = "https://stylemen.net/wp-json/wc/v3/orders?consumer_key={$consumer_key}&consumer_secret={$consumer_secret}&status=processing&per_page=10";
        $client = new Client();
        $response = $client->request('GET', $url, ['headers' => ['Content-Type' => 'application/json']]);
        $orders = json_decode($response->getBody(), true);

        $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
        $productDatas = [];
        $orderToChangeStatus = [];
        foreach ($orders as $order) {
            if (intval($order['billing']['city']) > 0) {
                $productDatas = [];
                foreach ($order['line_items'] as $item) {
                    if ($item['sku']) {
                        $sku = $item['sku'];
                        if ($sku !== "089B" && isset($item['meta_data'][1])) {
                            $color = $item['meta_data'][1]['value'];
                            $sku .= match ($color) {
                                "الأزرق" => "B",
                                "الأسود" => "N",
                                "أحمر", "red" => "R",
                                "أبيض-خطوط-سوداء" => "BN",
                                "بلومارين-2" => "BM",
                                default => ""
                            };
                        }

                        $product = Product::where('reference', $sku)->whereIn('account_user_id', $accountUsers)->first();
                        if ($product) {
                            $attributes = collect($item['meta_data'])->map(function ($variant) use ($accountUsers) {
                                if ($variant['key'] == 'pa_size') {
                                    $sizeTitle = (strpos($variant['value'], '-')) ? strstr($variant['value'], '-', true) : $variant['value'];
                                    $size = Attribute::where('title', $sizeTitle)->whereIn('account_user_id', $accountUsers)->first();
                                    return $size->id;
                                }
                            })->filter()->toArray();
                            $productDatas[] = [
                                'id' => $product->id,
                                'attributes' => $attributes,
                                'price' => $item['price'],
                                'quantity' => $item['quantity']
                            ];
                        }
                    }
                }
                if ($productDatas) {
                    $cityId = Http::get('https://space.meta-pixel.net/woocommerce/getCity/' . $order['billing']['city'])->json();
                    $orderDatas[] = [
                        "customer" => [
                            //"id"=>22, not required
                            "name" => $order['billing']['first_name'],
                            "addresses" => [["city_id" => $cityId, "title" => $order['billing']['address_1'], "principal" => true]],
                            "phones" => [["phoneTypes" => [1, 2], "title" => $order['billing']['phone'], "principal" => true]]
                        ],
                        "warehouse_id" => 27,
                        "payment_type_id" => 1,
                        "payment_method_id" => 1,
                        "brand_source_id" => 75,
                        "order_status_id" => 1,
                        "products" => $productDatas,
                        'status_comment' => 'Commande Importé depuis stylemen.net',
                        "comment" => $order['customer_note'],
                    ];
                    $orderToChangeStatus[] = $order['id'];
                }
            }
        }
        if ($orderDatas) {
            OrderController::store(new Request($orderDatas));
            foreach ($orderToChangeStatus as $key => $orderToChange) {
                $this->updateStatus($consumer_key, $consumer_secret, $orderToChange, 'completed');
            }
        }
        return [
            'statut' => 1,
            'data' => 'Importation effectués avec succés'
        ];
    }

    public function updateStatus($consumer_key, $consumer_secret, $order_id, $status)
    {
        $url = "https://stylemen.net/wp-json/wc/v3/orders/{$order_id}?consumer_key={$consumer_key}&consumer_secret={$consumer_secret}";
        $client = new Client();
        $datarequest = [
            'status' => $status,
        ];
        $response = $client->request('PUT', $url, [
            'json' => $datarequest,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);

        return $response->getBody()->getContents();
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
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/sizes')->json();
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
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/regions')->json();
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
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/suppliers')->json();
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
    
    public function orderSuppliers()
    {
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/receipts')->json();
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
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/supplierorders')->json();
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
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/brandsources')->json();
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
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/carriers')->json();
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
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/users')->json();

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


    
    public function orderPvas()
    {
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/orderproducts')->json();
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
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/ordercomments')->json();
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
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/invoices')->json();
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

    public function products()
    {
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/products')->json();
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
        $responses = Http::get('https://space.meta-pixel.net/woocommerce/products')->json();
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
    }
        */
}
