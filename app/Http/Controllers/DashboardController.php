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
