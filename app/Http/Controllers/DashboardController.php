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

        $totalDelivered = $deliveredOrders->count();
        $prevTotalDelivered = $prevDeliveredOrders->count();

        $totalPickup = (clone $baseQuery)->whereNotNull('pickup_id')->count();

        $totalConfirmed = (clone $baseQuery)->whereHas('orderComments', function ($q) {
            $q->where('order_status_id', 4);
        })->where('order_status_id', '!=', 2)->count();
        $prevTotalConfirmed = (clone $prevQuery)->whereHas('orderComments', function ($q) {
            $q->where('order_status_id', 4);
        })->where('order_status_id', '!=', 2)->count();

        $totalInTransit = (clone $baseQuery)->whereIn('order_status_id', [4, 5, 6, 9])->count();
        $prevTotalInTransit = (clone $prevQuery)->whereIn('order_status_id', [4, 5, 6, 9])->count();

        // Helper function for trend
        $formatTrend = function ($old, $new) {
            if ($old == 0) {
                return $new > 0 ? '+100%' : '0%';
            }
            $diff = (($new - $old) / $old) * 100;
            $sign = $diff > 0 ? '+' : '';
            return $sign . round($diff, 1) . '%';
        };

        // 2. Orders Overview Chart — use aggregate SQL instead of loading all models
        $diffInDays = $start->diffInDays($end) + 1;
        
        if ($diffInDays > 365) {
            $sqlGroupExpr = "YEAR(created_at)";
            $addFunction = 'addYear';
            $startRange = $start->copy()->startOfYear();
            $getKey = function ($date) { return $date->format('Y'); };
        } elseif ($diffInDays > 90) {
            $sqlGroupExpr = "DATE_FORMAT(created_at, '%b %y')";
            $addFunction = 'addMonth';
            $startRange = $start->copy()->startOfMonth();
            $getKey = function ($date) { return $date->format('M y'); };
        } elseif ($diffInDays > 30) {
            $sqlGroupExpr = "CONCAT('W', LPAD(WEEK(created_at, 1), 2, '0'))";
            $addFunction = 'addWeek';
            $startRange = $start->copy()->startOfWeek();
            $getKey = function ($date) {
                return 'W' . str_pad($date->format('W'), 2, '0', STR_PAD_LEFT);
            };
        } else {
            $sqlGroupExpr = "DATE_FORMAT(created_at, '%d %b')";
            $addFunction = 'addDay';
            $startRange = $start->copy();
            $getKey = function ($date) { return $date->format('d M'); };
        }

        // Build ordered period keys
        $periodKeys = [];
        $current = $startRange->copy();
        while ($current <= $end) {
            $periodKeys[] = $getKey($current);
            $current->{$addFunction}();
        }

        // Aggregate orders overview with a single SQL query
        $carrierJoin = '';
        $carrierWhere = '';
        if ($carrier !== 'All Carriers' && $carrier !== '0' && !empty($carrier)) {
            $carrierJoin = "INNER JOIN pickups ON orders.pickup_id = pickups.id";
            $carrierWhere = "AND pickups.carrier_id = " . intval($carrier);
        }

        $chartRows = DB::select("
            SELECT 
                {$sqlGroupExpr} as period_key,
                COUNT(*) as all_orders,
                SUM(CASE WHEN order_status_id IN (7, 10) THEN 1 ELSE 0 END) as shipped_orders,
                SUM(CASE WHEN order_status_id IN (8, 11) THEN 1 ELSE 0 END) as canceled_orders,
                SUM(CASE WHEN order_status_id IN (4, 5, 6, 9) THEN 1 ELSE 0 END) as in_transit_orders
            FROM orders
            {$carrierJoin}
            WHERE account_id = ?
              AND created_at BETWEEN ? AND ?
              {$carrierWhere}
            GROUP BY period_key
        ", [$accountId, $start, $end]);

        // Map SQL results to keyed array
        $chartData = [];
        foreach ($chartRows as $row) {
            $chartData[$row->period_key] = $row;
        }

        $revenueOverview = [];
        foreach ($periodKeys as $key) {
            $row = $chartData[$key] ?? null;
            $revenueOverview[] = [
                'day' => $key,
                'all_orders' => $row ? (int)$row->all_orders : 0,
                'shipped_orders' => $row ? (int)$row->shipped_orders : 0,
                'canceled_orders' => $row ? (int)$row->canceled_orders : 0,
                'in_transit_orders' => $row ? (int)$row->in_transit_orders : 0,
            ];
        }

        // 3. Delivery Status Chart — aggregate SQL
        $statusRows = DB::select("
            SELECT 
                {$sqlGroupExpr} as period_key,
                COUNT(*) as total,
                SUM(CASE WHEN order_status_id IN (7, 10) THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN order_status_id IN (11, 8, 3, 2) THEN 1 ELSE 0 END) as returned,
                SUM(CASE WHEN order_status_id IN (4, 5, 6, 9) THEN 1 ELSE 0 END) as in_transit
            FROM orders
            {$carrierJoin}
            WHERE account_id = ?
              AND created_at BETWEEN ? AND ?
              {$carrierWhere}
            GROUP BY period_key
        ", [$accountId, $start, $end]);

        $statusData = [];
        foreach ($statusRows as $row) {
            $statusData[$row->period_key] = $row;
        }

        $deliveryStatus = [];
        foreach ($periodKeys as $key) {
            $row = $statusData[$key] ?? null;
            $total = $row ? max((int)$row->total, 1) : 1;
            $deliveryStatus[] = [
                'day' => $key,
                'delivered' => $row ? round((int)$row->delivered / $total, 2) : 0,
                'returned' => $row ? round((int)$row->returned / $total, 2) : 0,
                'in_transit' => $row ? round((int)$row->in_transit / $total, 2) : 0,
            ];
        }

        // 4. Per-source statistics — consolidated into a single query
        $accountSources = \App\Models\Source::where('account_id', $accountId)->get();

        $getIdsForType = function ($accountSources, $keywords) {
            return $accountSources->filter(function ($source) use ($keywords) {
                $title = strtolower($source->title);
                foreach ($keywords as $keyword) {
                    if (str_contains($title, $keyword)) {
                        return true;
                    }
                }
                return false;
            })->pluck('id')->toArray();
        };

        $websiteSourceIds = $getIdsForType($accountSources, ['site', 'web']);
        $whatsappSourceIds = $getIdsForType($accountSources, ['whatsapp', 'wtsp']);
        $tiktokSourceIds = $getIdsForType($accountSources, ['tiktok']);
        $magasinSourceIds = $getIdsForType($accountSources, ['magasin']);

        // Build a single aggregate query for all sources at once
        $allSourceIds = array_merge($websiteSourceIds, $whatsappSourceIds, $tiktokSourceIds, $magasinSourceIds);
        
        $sourceStats = [];
        if (!empty($allSourceIds)) {
            $placeholders = implode(',', array_fill(0, count($allSourceIds), '?'));
            $sourceRows = DB::select("
                SELECT 
                    bs.source_id,
                    COUNT(*) as total_count,
                    SUM(CASE WHEN o.order_status_id IN (7, 10) THEN 1 ELSE 0 END) as delivered_count,
                    SUM(CASE WHEN o.order_status_id != 2 AND EXISTS (
                        SELECT 1 FROM order_comment oc WHERE oc.order_id = o.id AND oc.order_status_id = 4
                    ) THEN 1 ELSE 0 END) as confirmed_count,
                    SUM(CASE WHEN EXISTS (
                        SELECT 1 FROM order_comment oc WHERE oc.order_id = o.id AND oc.comment_id = 34
                    ) THEN 1 ELSE 0 END) as refused_count,
                    SUM(CASE WHEN EXISTS (
                        SELECT 1 FROM reviews r WHERE r.order_id = o.id
                    ) THEN 1 ELSE 0 END) as reviewed_count,
                    SUM(CASE WHEN o.pickup_id IS NOT NULL THEN 1 ELSE 0 END) as pickup_count
                FROM orders o
                INNER JOIN brand_source bs ON o.brand_source_id = bs.id
                WHERE o.account_id = ?
                  AND o.created_at BETWEEN ? AND ?
                  AND bs.source_id IN ({$placeholders})
                GROUP BY bs.source_id
            ", array_merge([$accountId, $start, $end], $allSourceIds));

            foreach ($sourceRows as $row) {
                $sourceStats[$row->source_id] = $row;
            }
        }

        // Aggregate stats per source type
        $aggregateSource = function ($sourceIds) use ($sourceStats) {
            $total = 0; $delivered = 0; $confirmed = 0; $refused = 0; $pickup = 0; $reviewed = 0;
            foreach ($sourceIds as $id) {
                if (isset($sourceStats[$id])) {
                    $row = $sourceStats[$id];
                    $total += (int)$row->total_count;
                    $delivered += (int)$row->delivered_count;
                    $confirmed += (int)$row->confirmed_count;
                    $refused += (int)$row->refused_count;
                    $pickup += (int)$row->pickup_count;
                    $reviewed += (int)$row->reviewed_count;
                }
            }
            return compact('total', 'delivered', 'confirmed', 'refused', 'pickup', 'reviewed');
        };

        $websiteAgg = $aggregateSource($websiteSourceIds);
        $whatsappAgg = $aggregateSource($whatsappSourceIds);
        $tiktokAgg = $aggregateSource($tiktokSourceIds);
        $magasinAgg = $aggregateSource($magasinSourceIds);

        // Previous period source counts (single query)
        $prevSourceStats = [];
        if (!empty($allSourceIds)) {
            $prevSourceRows = DB::select("
                SELECT 
                    bs.source_id,
                    COUNT(*) as total_count
                FROM orders o
                INNER JOIN brand_source bs ON o.brand_source_id = bs.id
                WHERE o.account_id = ?
                  AND o.created_at BETWEEN ? AND ?
                  AND bs.source_id IN ({$placeholders})
                GROUP BY bs.source_id
            ", array_merge([$accountId, $prevStart, $prevEnd], $allSourceIds));

            foreach ($prevSourceRows as $row) {
                $prevSourceStats[$row->source_id] = (int)$row->total_count;
            }
        }

        $prevAggregateSource = function ($sourceIds) use ($prevSourceStats) {
            $total = 0;
            foreach ($sourceIds as $id) {
                $total += $prevSourceStats[$id] ?? 0;
            }
            return $total;
        };

        $prevOrdersWebsite = $prevAggregateSource($websiteSourceIds);
        $prevOrdersWhatsapp = $prevAggregateSource($whatsappSourceIds);
        $prevOrdersTiktok = $prevAggregateSource($tiktokSourceIds);
        $prevOrdersMagasin = $prevAggregateSource($magasinSourceIds);

        $calcRate = function ($count, $total) {
            return $total > 0 ? round(($count / $total) * 100, 1) : 0;
        };

        // 5. Top cities delivered
        $deliveredOrdersByCity = (clone $baseQuery)
            ->whereIn('order_status_id', [7, 10])
            ->whereNotNull('city_id')
            ->select('city_id', DB::raw('count(*) as count'))
            ->groupBy('city_id')
            ->orderByDesc('count')
            ->with('city')
            ->get();

        $topCitiesDelivered = [];
        $othersCount = 0;

        foreach ($deliveredOrdersByCity as $index => $item) {
            if ($index < 14) {
                $cityName = $item->city ? $item->city->title : 'Unknown';
                $topCitiesDelivered[] = [
                    'city' => $cityName,
                    'count' => (int)$item->count,
                ];
            } else {
                $othersCount += (int)$item->count;
            }
        }

        if ($othersCount > 0) {
            $topCitiesDelivered[] = [
                'city' => 'Others',
                'count' => $othersCount,
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'summary' => [
                    'total_orders' => [
                        'value' => number_format($totalOrders),
                        'trend' => $formatTrend($prevTotalOrders, $totalOrders)
                    ],
                    'total_delivered' => [
                        'value' => number_format($totalDelivered),
                        'trend' => $formatTrend($prevTotalDelivered, $totalDelivered),
                        'index' => $totalPickup > 0 ? round(($totalDelivered / $totalPickup) * 100, 1) : 0
                    ],
                    'total_confirmed' => [
                        'value' => number_format($totalConfirmed),
                        'trend' => $formatTrend($prevTotalConfirmed, $totalConfirmed),
                        'index' => $totalOrders > 0 ? round(($totalConfirmed / $totalOrders) * 100, 1) : 0
                    ],
                    'total_in_transit' => [
                        'value' => number_format($totalInTransit),
                        'trend' => $formatTrend($prevTotalInTransit, $totalInTransit)
                    ],
                    'orders_website' => [
                        'value' => number_format($websiteAgg['total']),
                        'trend' => $formatTrend($prevOrdersWebsite, $websiteAgg['total']),
                        'delivered_count' => $websiteAgg['delivered'],
                        'delivered_percentage' => number_format($calcRate($websiteAgg['delivered'], $websiteAgg['total']), 1),
                        'delivered_percentage_pickup' => number_format($calcRate($websiteAgg['delivered'], $websiteAgg['pickup']), 1),
                        'confirmed_count' => $websiteAgg['confirmed'],
                        'confirmed_percentage' => number_format($calcRate($websiteAgg['confirmed'], $websiteAgg['total']), 1),
                        'refused_count' => $websiteAgg['refused'],
                        'refused_percentage' => number_format($calcRate($websiteAgg['refused'], $websiteAgg['total']), 1),
                        'reviewed_count' => $websiteAgg['reviewed'],
                        'reviewed_percentage' => number_format($calcRate($websiteAgg['reviewed'], $websiteAgg['delivered']), 1)
                    ],
                    'orders_whatsapp' => [
                        'value' => number_format($whatsappAgg['total']),
                        'trend' => $formatTrend($prevOrdersWhatsapp, $whatsappAgg['total']),
                        'delivered_count' => $whatsappAgg['delivered'],
                        'delivered_percentage' => number_format($calcRate($whatsappAgg['delivered'], $whatsappAgg['total']), 1),
                        'delivered_percentage_pickup' => number_format($calcRate($whatsappAgg['delivered'], $whatsappAgg['pickup']), 1),
                        'confirmed_count' => $whatsappAgg['confirmed'],
                        'confirmed_percentage' => number_format($calcRate($whatsappAgg['confirmed'], $whatsappAgg['total']), 1),
                        'refused_count' => $whatsappAgg['refused'],
                        'refused_percentage' => number_format($calcRate($whatsappAgg['refused'], $whatsappAgg['total']), 1),
                        'reviewed_count' => $whatsappAgg['reviewed'],
                        'reviewed_percentage' => number_format($calcRate($whatsappAgg['reviewed'], $whatsappAgg['delivered']), 1)
                    ],
                    'orders_tiktok' => [
                        'value' => number_format($tiktokAgg['total']),
                        'trend' => $formatTrend($prevOrdersTiktok, $tiktokAgg['total']),
                        'delivered_count' => $tiktokAgg['delivered'],
                        'delivered_percentage' => number_format($calcRate($tiktokAgg['delivered'], $tiktokAgg['total']), 1),
                        'delivered_percentage_pickup' => number_format($calcRate($tiktokAgg['delivered'], $tiktokAgg['pickup']), 1),
                        'confirmed_count' => $tiktokAgg['confirmed'],
                        'confirmed_percentage' => number_format($calcRate($tiktokAgg['confirmed'], $tiktokAgg['total']), 1),
                        'refused_count' => $tiktokAgg['refused'],
                        'refused_percentage' => number_format($calcRate($tiktokAgg['refused'], $tiktokAgg['total']), 1),
                        'reviewed_count' => $tiktokAgg['reviewed'],
                        'reviewed_percentage' => number_format($calcRate($tiktokAgg['reviewed'], $tiktokAgg['delivered']), 1)
                    ],
                    'orders_magasin' => [
                        'value' => number_format($magasinAgg['total']),
                        'trend' => $formatTrend($prevOrdersMagasin, $magasinAgg['total']),
                        'delivered_count' => $magasinAgg['delivered'],
                        'delivered_percentage' => number_format($calcRate($magasinAgg['delivered'], $magasinAgg['total']), 1),
                        'delivered_percentage_pickup' => number_format($calcRate($magasinAgg['delivered'], $magasinAgg['pickup']), 1),
                        'confirmed_count' => $magasinAgg['confirmed'],
                        'confirmed_percentage' => number_format($calcRate($magasinAgg['confirmed'], $magasinAgg['total']), 1),
                        'refused_count' => $magasinAgg['refused'],
                        'refused_percentage' => number_format($calcRate($magasinAgg['refused'], $magasinAgg['total']), 1),
                        'reviewed_count' => $magasinAgg['reviewed'],
                        'reviewed_percentage' => number_format($calcRate($magasinAgg['reviewed'], $magasinAgg['delivered']), 1)
                    ]
                ],
                'revenue_overview' => $revenueOverview,
                'delivery_status' => $deliveryStatus,
                'top_cities_delivered' => $topCitiesDelivered
            ]
        ]);
    }
}