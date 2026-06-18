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
        // 4. Inventory Alerts
        // Optimized to prevent memory exhaustion by computing aggregates in database instead of hydrating all models.
        $productsWithPvas = Product::whereHas('productVariationAttributes', function ($query) use ($accountId) {
                $query->where('account_id', $accountId);
            })
            ->get(['id', 'title']);

        $groupedProducts = [];
        foreach ($productsWithPvas as $product) {
            $groupedProducts[$product->id] = [
                'product_name' => $product->title,
                'ordered' => 0,
                'in_transit' => 0,
                'available' => 0,
            ];
        }

        // Fetch available sums per product
        $availableData = DB::table('warehouse_pva')
            ->join('product_variation_attribute', 'warehouse_pva.product_variation_attribute_id', '=', 'product_variation_attribute.id')
            ->where('warehouse_pva.statut', 1)
            ->where('product_variation_attribute.account_id', $accountId)
            ->whereNull('product_variation_attribute.deleted_at')
            ->groupBy('product_variation_attribute.product_id')
            ->select('product_variation_attribute.product_id', DB::raw('SUM(warehouse_pva.quantity) as total_available'))
            ->get();

        foreach ($availableData as $row) {
            if (isset($groupedProducts[$row->product_id])) {
                $groupedProducts[$row->product_id]['available'] = (int) $row->total_available;
            }
        }

        // Fetch ordered sums per product
        $orderedData = DB::table('supplier_order_pva')
            ->join('product_variation_attribute', 'supplier_order_pva.product_variation_attribute_id', '=', 'product_variation_attribute.id')
            ->join('supplier_orders', 'supplier_order_pva.supplier_order_id', '=', 'supplier_orders.id')
            ->where('supplier_orders.statut', 1)
            ->where('product_variation_attribute.account_id', $accountId)
            ->whereNull('product_variation_attribute.deleted_at')
            ->whereNull('supplier_orders.deleted_at')
            ->whereNull('supplier_order_pva.deleted_at')
            ->groupBy('product_variation_attribute.product_id')
            ->select('product_variation_attribute.product_id', DB::raw('SUM(supplier_order_pva.quantity) as total_ordered'))
            ->get();

        foreach ($orderedData as $row) {
            if (isset($groupedProducts[$row->product_id])) {
                $groupedProducts[$row->product_id]['ordered'] = (int) $row->total_ordered;
            }
        }

        // Fetch in_transit sums per product
        $inTransitData = DB::table('order_pva')
            ->join('product_variation_attribute', 'order_pva.product_variation_attribute_id', '=', 'product_variation_attribute.id')
            ->join('orders', 'order_pva.order_id', '=', 'orders.id')
            ->whereIn('orders.order_status_id', [5, 6, 8, 9])
            ->where('product_variation_attribute.account_id', $accountId)
            ->whereNull('product_variation_attribute.deleted_at')
            ->whereNull('orders.deleted_at')
            ->whereNull('order_pva.deleted_at')
            ->groupBy('product_variation_attribute.product_id')
            ->select('product_variation_attribute.product_id', DB::raw('SUM(order_pva.quantity) as total_in_transit'))
            ->get();

        foreach ($inTransitData as $row) {
            if (isset($groupedProducts[$row->product_id])) {
                $groupedProducts[$row->product_id]['in_transit'] = (int) $row->total_in_transit;
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
