<?php


namespace App\Http\Controllers;

use Carbon\Carbon;

use App\Models\Order;
use Illuminate\Http\Request;
use App\Models\AccountUser;
use App\Models\OrderPva;
use App\Models\Product;
use App\Models\ProductVariationAttribute;
use App\Models\Transaction;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function calculatePercentageDifference($oldValue, $newValue)
    {
        // Ensure oldValue is not zero to avoid division by zero error
        if ($oldValue == 0) {
            return 0;
            throw new Exception("Old value cannot be zero.");
        }
        $difference = $oldValue - $newValue;
        $percentageDifference = ($difference / $oldValue) * 100;

        return $percentageDifference;
    }

    public function index(Request $request)
    {
        // Initialize an array to hold the data
        $data = [];
        $request = collect($request->query())->toArray();
        $data = [];
        $inAccountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();

        if (isset($request['product_sold'])) {
            //pour la semaine derniers
            $firstEndDate = Carbon::now()->subMonths(1);
            $firstStartDate = $firstEndDate->copy()->subMonths(1);
            //pour la semaine actuels
            $lastEndDate = Carbon::now();
            $lastStartDate = $lastEndDate->copy()->subMonths(1);

            $firstOrderPva = array_sum(OrderPva::whereBetween('created_at', [$firstStartDate, $firstEndDate])->whereIn('account_user_id', $inAccountUsers)->whereIn('order_status_id', [7, 10])->get()->pluck('quantity')->toArray());
            $orderPva = array_sum(OrderPva::whereBetween('created_at', [$lastStartDate, $lastEndDate])->whereIn('account_user_id', $inAccountUsers)->whereIn('order_status_id', [7, 10])->get()->pluck('quantity')->toArray());
            $series = [];
            for ($date = $lastStartDate; $date->lte($lastEndDate); $date->addWeek()) {
                $adWeek = $lastStartDate->copy()->addWeek();
                $orderPva = array_sum(OrderPva::whereBetween('created_at', [$lastStartDate, $adWeek])->whereIn('account_user_id', $inAccountUsers)->whereIn('order_status_id', [7, 10])->get()->pluck('quantity')->toArray());
                $series[] = $orderPva;
            }
            $total = array_sum($series);
            $percentageDifference = $this->calculatePercentageDifference($total, $firstOrderPva);
            $data['product_sold'] = [
                "title" => "Product Sold",
                "percent" => $percentageDifference,
                "total" => $total,
                "chart" => [
                    "series" => $series
                ]
            ];
        }
        if (isset($request['net_balance'])) {
            //pour la semaine derniers
            $firstEndDate = Carbon::now()->subMonths(1);
            $firstStartDate = $firstEndDate->copy()->subMonths(1);
            //pour la semaine actuels
            $lastEndDate = Carbon::now();
            $lastStartDate = $lastEndDate->copy()->subMonths(1);
            $firstTransactions = Transaction::whereBetween('created_at', [$firstStartDate, $firstEndDate])->whereIn('account_user_id', $inAccountUsers)->get();

            $totalFirst = 0;
            $firstTransactions->map(function ($transaction) use (&$totalFirst) {
                $totalFirst +=   ($transaction->transaction_type_id == 1) ? $transaction->amount : -$transaction->amount;
            });

            $series = [];
            for ($date = $lastStartDate; $date->lte($lastEndDate); $date->addWeek()) {
                $adWeek = $lastStartDate->copy()->addWeek();
                $transactions = Transaction::whereBetween('created_at', [$lastStartDate, $adWeek])->whereIn('account_user_id', $inAccountUsers)->get();
                $weekTotal = $transactions->map(function ($transaction) use (&$total) {
                    return ($transaction->transaction_type_id == 1) ? $transaction->amount : (-$transaction->amount);
                })->toArray();
                $series[] = array_sum($weekTotal);
            }
            $total = array_sum($series);
            $percentageDifference = $this->calculatePercentageDifference($total, $totalFirst);
            $data['net_balance'] = [
                "title" => "Net Balance",
                "percent" => $percentageDifference,
                "total" => $total,
                "chart" => [
                    "series" => $series
                ]
            ];
        }
        if (isset($request['sales_profit'])) {
            //pour la semaine derniers
            $firstEndDate = Carbon::now()->subMonths(1);
            $firstStartDate = $firstEndDate->copy()->subMonths(1);
            //pour la semaine actuels
            $lastEndDate = Carbon::now();
            $lastStartDate = $lastEndDate->copy()->subMonths(1);
            $firstOrders = Order::whereBetween('created_at', [$firstStartDate, $firstEndDate])->where('account_id', getAccountUser()->account_id)->whereIn('order_status_id', [7, 10])->get();
            $totalfirst = array_sum($firstOrders->map(function ($order) {
                $totalOrder = $order->activeOrderPvas->map(function ($orderPva) {
                    $totalPva = ($orderPva->quantity * $orderPva->price) - ($orderPva->quantity * $orderPva->realprice);
                    return $totalPva;
                })->toArray();
                $totalOrder = array_sum($totalOrder) - $order->real_carrier_price;
                return $totalOrder;
            })->toArray());


            $series = [];
            for ($date = $lastStartDate; $date->lte($lastEndDate); $date->addWeek()) {
                $adWeek = $lastStartDate->copy()->addWeek();
                $orders = Order::whereBetween('created_at', [$lastStartDate, $adWeek])->where('account_id', getAccountUser()->account_id)->whereIn('order_status_id', [7, 10])->get();
                $totalWeek = array_sum($orders->map(function ($order) {
                    $totalOrder = $order->activeOrderPvas->map(function ($orderPva) {
                        $totalPva = ($orderPva->quantity * $orderPva->price) - ($orderPva->quantity * $orderPva->realprice);
                        return $totalPva;
                    })->toArray();
                    $totalOrder = array_sum($totalOrder) - $order->real_carrier_price;
                    return $totalOrder;
                })->toArray());
                $series[] = $totalWeek;
            }
            $total = array_sum($series);

            $percentageDifference = $this->calculatePercentageDifference($total, $totalfirst);
            $data['sales_profit'] = [
                "title" => "Sales Profit",
                "percent" => $percentageDifference,
                "total" => $total,
                "chart" => [
                    "series" => $series
                ]
            ];
        }

        if (isset($request['total_balance'])) {
            $firstEndDate = Carbon::now();
            $firstStartDate = $firstEndDate->copy()->subMonths(1);
            $firstTransactions = Transaction::whereIn('account_user_id', AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray())->get();
            $total = [];
            $total['total'] = 0;
            $total['cash'] = 0;
            $total['carriers'] = 0;
            $total['team'] = 0;
            $total['ads'] = 0;
            $total['supplier'] = 0;
            $total['charges'] = 0;
            $firstTransactions->map(function ($transaction) use (&$total) {
                $total['total'] += $transaction->amount;
                $total['cash'] = ($transaction->transaction_type_id == 1) ? ($total['cash'] + $transaction->amount) : ($total['cash'] - $transaction->amount);
                switch ($transaction->transaction_type) {
                    case 'App\Models\Shipment':
                        $total['carriers'] += $transaction->amount;
                        break;
                    case 'App\Models\Supplier':
                        $total['supplier'] += $transaction->amount;
                        break;
                    case 'App\Models\Payment':
                        $total['team'] += $transaction->amount;
                        break;
                    case 'App\Models\Expense':
                        if ($transaction->expense->expense_type_id == 1) {
                            $total['ads'] += $transaction->amount;
                        } else {
                            $total['charges'] += $transaction->amount;
                        }
                        break;
                    case 'App\Models\Payment':
                        $total['payment'] += $transaction->amount;
                        break;
                    default:
                        break;
                }
            });
            $data['total_balance'] = [
                "title" => "Total Balance",
                "total" => $total['cash'],
                "data" => [
                    [
                        "status" => "cash",
                        "quantity" => $total['cash'],
                        "value" => ($total['cash'] == 0) ? 0 : $total['cash'] * 100  / $total['total']
                    ],
                    [
                        "status" => "carriers",
                        "quantity" => $total['carriers'],
                        "value" => ($total['carriers'] == 0) ? 0 : $total['carriers'] * 100  / $total['total']
                    ],
                    [
                        "status" => "team",
                        "quantity" => $total['team'],
                        "value" => ($total['team'] == 0) ? 0 : $total['team'] * 100  / $total['total']
                    ],
                    [
                        "status" => "Ads",
                        "quantity" => $total['ads'],
                        "value" => ($total['ads'] == 0) ? 0 : $total['ads'] * 100  / $total['total']
                    ],
                    [
                        "status" => "supplier",
                        "quantity" => $total['supplier'],
                        "value" => ($total['supplier'] == 0) ? 0 : $total['supplier'] * 100  / $total['total']
                    ],
                    [
                        "status" => "charges",
                        "quantity" => $total['charges'],
                        "value" => ($total['charges'] == 0) ? 0 : $total['charges'] * 100  / $total['total']
                    ]
                ]
            ];
        }
        //hadi khassha trje3 par mois bach tkon 3endna la visibilté ola tzad le choix par date
        if (isset($request['order_status'])) {
            $datas = [];
            $currentDate = Carbon::now();
            $startOfMonth = $currentDate->copy()->startOfMonth();
            $daysInMonthSoFar = $currentDate->diffInDays($startOfMonth) + 1; // Number of days from 1st of the month to today

            for ($i = 0; $i < $daysInMonthSoFar; $i++) {
                $dayStart = $currentDate->copy()->subDays($i)->startOfDay();
                $dayEnd = $currentDate->copy()->subDays($i)->endOfDay();
                // Get order statistics
                $datas['received'][] = DB::table('orders')
                    ->whereBetween('created_at', [$dayStart, $dayEnd])
                    ->where('account_id', getAccountUser()->account_id)
                    ->count();

                $datas['canceled'][] = DB::table('orders')
                    ->where('account_id', getAccountUser()->account_id)
                    ->whereBetween('created_at', [$dayStart, $dayEnd])
                    ->whereIn('order_status_id', [11, 8, 3, 2])
                    ->count();

                $datas['delivred'][] = DB::table('orders')
                    ->where('account_id', getAccountUser()->account_id)
                    ->whereBetween('created_at', [$dayStart, $dayEnd])
                    ->whereIn('order_status_id', [7, 10])
                    ->count();

                $datas['shipped'][] = DB::table('orders')
                    ->where('account_id', getAccountUser()->account_id)
                    ->whereBetween('created_at', [$dayStart, $dayEnd])
                    ->whereIn('order_status_id', [4, 5, 6, 9])
                    ->count();

                // Store the formatted date
                $datas['dates'][] = $dayStart->format('Y-m-d');
            }

            $data['order_status'] = [
                "title" => "Orders Status",
                "subheader" => "0%",
                "chart" => [
                    "labels" => array_reverse($datas['dates']), // Reverse to show chronological order
                    "series" => [
                        [
                            "name" => "received",
                            "data" => array_reverse($datas['received'])
                        ],
                        [
                            "name" => "cancelled",
                            "data" => array_reverse($datas['canceled'])
                        ],
                        [
                            "name" => "shipped",
                            "data" => array_reverse($datas['shipped'])
                        ],
                        [
                            "name" => "delivred",
                            "data" => array_reverse($datas['delivred'])
                        ]
                    ]
                ]
            ];
        }

        if (isset($request['latest_product'])) {
            //->whereIn('account_user_id', AccountUser::where('account_id', getAccountUser()->account_id)->get()->pluck('id')->toArray())
            $products = Product::take(5)->whereIn('account_user_id', $inAccountUsers)->orderBy('created_at')->get();
            $products = $products->map(function ($product) {
                $productData = $product->only('id', 'title', 'reference');
                $productData['price'] = $product->price->first()->price;
                $productData['images'] = $product->principalImage;
                return $productData;
            });
            $data['latest_product'] = $products;
        }
        if (isset($request['best_salesmen'])) {
            $data['best_salesmen'] = [];
            $topUsers = DB::table('order_pva')->whereIn('account_user_id', $inAccountUsers)
                ->select('account_user_id', DB::raw('COUNT(*) as order_count'))
                ->groupBy('account_user_id')
                ->orderBy('order_count', 'desc')
                ->limit(5)
                ->get();
            foreach ($topUsers as $key => $userdata) {
                $pvas = DB::table('order_pva')->whereIn('account_user_id', $inAccountUsers)
                    ->select('product_variation_attribute_id', DB::raw('COUNT(*) as pva_count'))
                    ->groupBy('product_variation_attribute_id')
                    ->orderBy('pva_count', 'desc')
                    ->where('account_user_id', $userdata->account_user_id)
                    ->first();

                $user = AccountUser::find($userdata->account_user_id);
                $product = ProductVariationAttribute::find($pvas->product_variation_attribute_id);
                $userInfo = [
                    "id" => $user->id,
                    "firstname" => $user->user->firstname,
                    "lastname" => $user->user->lastname,
                    "images" => $user->user->images,
                ];
                $productInfo = [
                    "id" => $product->product->id,
                    "title" => $product->product->title,
                ];

                $data['best_salesmen'][] = [
                    "id" => $user->id,
                    "user" => $userInfo,
                    "product" => $productInfo,
                    "total" => $userdata->order_count,
                    "rank" => $key + 1
                ];
            }
        }
        // if (isset($request['best_salesmen'])) {
        //     $data['best_salesmen'] = [[
        //         "id" => 1,
        //         "user" => ["id" => 1, "firstname" => "ana", "lastname" => "achkar", "images" => []],
        //         "product" => ["id" => 4, "title" => "089 white"], // Le produit le plus vendu
        //         "total" => 25000,
        //         "rank" => 1
        //     ]];
        // }
        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }

    public function dashboard(Request $request)
    {
        $startDate = $request->query('start_date', Carbon::now()->subDays(7)->format('Y-m-d'));
        $endDate = $request->query('end_date', Carbon::now()->format('Y-m-d'));
        $carrier = $request->query('carrier', 'All Carriers');

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        // Previous period for trend calculation
        $diffInDays = $start->diffInDays($end) + 1;
        $prevStart = $start->copy()->subDays($diffInDays)->startOfDay();
        $prevEnd = $start->copy()->subSeconds(1);

        $accountId = getAccountUser()->account_id;

        // Base Query
        $baseQuery = Order::whereBetween('created_at', [$start, $end])
            ->where('account_id', $accountId);

        $prevQuery = Order::whereBetween('created_at', [$prevStart, $prevEnd])
            ->where('account_id', $accountId);

        if ($carrier !== 'All Carriers' && $carrier !== '0' && !empty($carrier)) {
            $baseQuery->whereHas('pickup', function ($q) use ($carrier) {
                $q->where('carrier_id', $carrier);
            });
            $prevQuery->whereHas('pickup', function ($q) use ($carrier) {
                $q->where('carrier_id', $carrier);
            });
        }

        // 1. Summary Cards
        $deliveredOrders = (clone $baseQuery)->whereIn('order_status_id', [7, 10])->with('activeOrderPvas')->get();
        $prevDeliveredOrders = (clone $prevQuery)->whereIn('order_status_id', [7, 10])->with('activeOrderPvas')->get();

        $totalRevenue = $deliveredOrders->sum(function ($order) {
            return $order->activeOrderPvas->sum(function ($pva) {
                return $pva->price * $pva->quantity;
            });
        });

        $prevTotalRevenue = $prevDeliveredOrders->sum(function ($order) {
            return $order->activeOrderPvas->sum(function ($pva) {
                return $pva->price * $pva->quantity;
            });
        });

        $totalOrders = (clone $baseQuery)->count();
        $prevTotalOrders = (clone $prevQuery)->count();

        $activeCustomers = (clone $baseQuery)->distinct('customer_id')->count('customer_id');
        $prevActiveCustomers = (clone $prevQuery)->distinct('customer_id')->count('customer_id');

        // Conversion Rate (Delivered Orders / Total Orders)
        $conversionRate = $totalOrders > 0 ? ($deliveredOrders->count() / $totalOrders) * 100 : 0;
        $prevConversionRate = $prevTotalOrders > 0 ? ($prevDeliveredOrders->count() / $prevTotalOrders) * 100 : 0;

        // Helper function for trend
        $formatTrend = function ($old, $new) {
            if ($old == 0) {
                return $new > 0 ? '+100%' : '0%';
            }
            $diff = (($new - $old) / $old) * 100;
            $sign = $diff > 0 ? '+' : '';
            return $sign . round($diff, 1) . '%';
        };

        // 2. Revenue Overview Chart
        $revenueByDay = [
            'Mon' => ['revenue' => 0, 'profit' => 0],
            'Tue' => ['revenue' => 0, 'profit' => 0],
            'Wed' => ['revenue' => 0, 'profit' => 0],
            'Thu' => ['revenue' => 0, 'profit' => 0],
            'Fri' => ['revenue' => 0, 'profit' => 0],
            'Sat' => ['revenue' => 0, 'profit' => 0],
            'Sun' => ['revenue' => 0, 'profit' => 0],
        ];

        $allOrders = (clone $baseQuery)->with('activeOrderPvas')->get();
        foreach ($allOrders as $order) {
            $day = $order->created_at->format('D');
            $orderRevenue = $order->activeOrderPvas->sum(function ($pva) {
                return $pva->price * $pva->quantity;
            });
            $orderProfit = $order->activeOrderPvas->sum(function ($pva) {
                return ($pva->price * $pva->quantity) - ($pva->realprice * $pva->quantity);
            }) - $order->real_carrier_price;

            if (isset($revenueByDay[$day])) {
                $revenueByDay[$day]['revenue'] += $orderRevenue;
                $revenueByDay[$day]['profit'] += $orderProfit;
            }
        }

        $maxRevenue = max(array_column($revenueByDay, 'revenue')) ?: 1;
        $maxProfit = max(array_column($revenueByDay, 'profit')) ?: 1;

        $revenueOverview = [];
        foreach ($revenueByDay as $day => $data) {
            $revenueOverview[] = [
                'day' => $day,
                'revenue_factor' => round($data['revenue'] / $maxRevenue, 2),
                'profit_factor' => round(max(0, $data['profit']) / $maxProfit, 2),
            ];
        }

        // 3. Delivery Status Chart
        $statusByDay = [
            'Mon' => ['total' => 0, 'delivered' => 0, 'returned' => 0, 'in_transit' => 0],
            'Tue' => ['total' => 0, 'delivered' => 0, 'returned' => 0, 'in_transit' => 0],
            'Wed' => ['total' => 0, 'delivered' => 0, 'returned' => 0, 'in_transit' => 0],
            'Thu' => ['total' => 0, 'delivered' => 0, 'returned' => 0, 'in_transit' => 0],
            'Fri' => ['total' => 0, 'delivered' => 0, 'returned' => 0, 'in_transit' => 0],
            'Sat' => ['total' => 0, 'delivered' => 0, 'returned' => 0, 'in_transit' => 0],
            'Sun' => ['total' => 0, 'delivered' => 0, 'returned' => 0, 'in_transit' => 0],
        ];

        foreach ($allOrders as $order) {
            $day = $order->created_at->format('D');
            if (isset($statusByDay[$day])) {
                $statusByDay[$day]['total']++;
                
                if (in_array($order->order_status_id, [7, 10])) {
                    $statusByDay[$day]['delivered']++;
                } elseif (in_array($order->order_status_id, [11, 8, 3, 2])) {
                    $statusByDay[$day]['returned']++;
                } elseif (in_array($order->order_status_id, [4, 5, 6, 9])) {
                    $statusByDay[$day]['in_transit']++;
                }
            }
        }

        $deliveryStatus = [];
        foreach ($statusByDay as $day => $data) {
            $total = $data['total'] ?: 1;
            $deliveryStatus[] = [
                'day' => $day,
                'delivered' => round($data['delivered'] / $total, 2),
                'returned' => round($data['returned'] / $total, 2),
                'in_transit' => round($data['in_transit'] / $total, 2),
            ];
        }

        // 4. Inventory Alerts
        $pvas = ProductVariationAttribute::where('account_id', $accountId)
            ->with([
                'product',
                'activeWarehouses',
                'orderPvas.order.orderStatus',
                'supplierOrderPvas.supplierOrder'
            ])
            ->get();

        $groupedProducts = [];

        foreach ($pvas as $pva) {
            $productId = $pva->product_id;
            if (!$productId || !$pva->product) continue;

            if (!isset($groupedProducts[$productId])) {
                $groupedProducts[$productId] = [
                    'product_name' => $pva->product->title,
                    'ordered' => 0,
                    'in_transit' => 0,
                    'available' => 0,
                ];
            }

            // Available
            if ($pva->activeWarehouses) {
                $groupedProducts[$productId]['available'] += $pva->activeWarehouses->sum('pivot.quantity');
            }

            // Ordered (from supplier orders)
            if ($pva->supplierOrderPvas) {
                foreach ($pva->supplierOrderPvas as $supplierOrderPva) {
                    if ($supplierOrderPva->supplierOrder && $supplierOrderPva->supplierOrder->statut == 1) {
                        $groupedProducts[$productId]['ordered'] += $supplierOrderPva->quantity;
                    }
                }
            }

            // In Transit (from customer orders)
            if ($pva->orderPvas) {
                foreach ($pva->orderPvas as $orderPva) {
                    if ($orderPva->order && $orderPva->order->orderStatus) {
                        $statusId = $orderPva->order->orderStatus->id;
                        if (in_array($statusId, [5, 6, 8, 9])) {
                            $groupedProducts[$productId]['in_transit'] += $orderPva->quantity;
                        }
                    }
                }
            }
        }

        $inventoryAlerts = collect(array_values($groupedProducts))->map(function ($item) {
            $colorCode = 'green';
            if ($item['available'] <= 0) {
                $colorCode = 'red';
            } elseif ($item['available'] <= 5) {
                $colorCode = 'orange';
            }
            $item['color_code'] = $colorCode;
            return $item;
        })->sortBy('available')->take(10)->values()->toArray();

        // 5. Recent Orders Table
        $recentOrders = (clone $baseQuery)
            ->with(['customer', 'orderStatus', 'activeOrderPvas'])
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        $recentOrdersData = $recentOrders->map(function ($order) {
            $statusTitle = $order->orderStatus ? $order->orderStatus->title : 'Unknown';
            $statusColor = 'orange';
            if (in_array($order->order_status_id, [7, 10])) {
                $statusColor = 'green';
            } elseif (in_array($order->order_status_id, [11, 8, 3, 2])) {
                $statusColor = 'red';
            }

            $amount = $order->activeOrderPvas->sum(function ($pva) {
                return $pva->price * $pva->quantity;
            });

            return [
                'order_id' => '#' . $order->code,
                'customer' => $order->customer ? $order->customer->name : 'Unknown',
                'date' => $order->created_at->format('M d, Y'),
                'amount' => number_format($amount, 2) . ' DH',
                'status' => $statusTitle,
                'status_color' => $statusColor
            ];
        })->toArray();

        return response()->json([
            'status' => 'success',
            'data' => [
                'summary' => [
                    'total_revenue' => [
                        'value' => number_format($totalRevenue, 2) . ' DH',
                        'trend' => $formatTrend($prevTotalRevenue, $totalRevenue)
                    ],
                    'total_orders' => [
                        'value' => number_format($totalOrders),
                        'trend' => $formatTrend($prevTotalOrders, $totalOrders)
                    ],
                    'active_customers' => [
                        'value' => number_format($activeCustomers),
                        'trend' => $formatTrend($prevActiveCustomers, $activeCustomers)
                    ],
                    'conversion_rate' => [
                        'value' => number_format($conversionRate, 2) . '%',
                        'trend' => $formatTrend($prevConversionRate, $conversionRate)
                    ]
                ],
                'revenue_overview' => $revenueOverview,
                'delivery_status' => $deliveryStatus,
                'inventory_alerts' => $inventoryAlerts,
                'recent_orders' => $recentOrdersData
            ]
        ]);
    }
}
