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

        $totalConfirmed = (clone $baseQuery)->whereHas('orderComments', function ($q) {
            $q->where('order_status_id', 4);
        })->count();
        $prevTotalConfirmed = (clone $prevQuery)->whereHas('orderComments', function ($q) {
            $q->where('order_status_id', 4);
        })->count();

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

        // 2. Orders Overview Chart
        $ordersByDay = [];
        $current = $start->copy();
        while ($current <= $end) {
            $dateKey = $current->format('d M');
            $ordersByDay[$dateKey] = ['all' => 0, 'shipped' => 0, 'canceled' => 0];
            $current->addDay();
        }

        $allOrders = (clone $baseQuery)->get();
        foreach ($allOrders as $order) {
            $day = $order->created_at->format('d M');
            if (isset($ordersByDay[$day])) {
                $ordersByDay[$day]['all']++;
                if (in_array($order->order_status_id, [7, 10])) {
                    $ordersByDay[$day]['shipped']++;
                } elseif (in_array($order->order_status_id, [8, 11])) {
                    $ordersByDay[$day]['canceled']++;
                }
            }
        }

        $revenueOverview = [];
        foreach ($ordersByDay as $day => $data) {
            $revenueOverview[] = [
                'day' => $day,
                'all_orders' => $data['all'],
                'shipped_orders' => $data['shipped'],
                'canceled_orders' => $data['canceled'],
            ];
        }

        // 3. Delivery Status Chart
        $statusByDay = [];
        $current = $start->copy();
        while ($current <= $end) {
            $dateKey = $current->format('d M');
            $statusByDay[$dateKey] = ['total' => 0, 'delivered' => 0, 'returned' => 0, 'in_transit' => 0];
            $current->addDay();
        }

        foreach ($allOrders as $order) {
            $day = $order->created_at->format('d M');
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



        // Fetch all sources for the current account to filter dynamically
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

        // Helper to count orders for specific source IDs
        $countBySourceIds = function ($query, $sourceIds) {
            if (empty($sourceIds)) {
                return 0;
            }
            return (clone $query)->whereHas('brandSource', function ($q) use ($sourceIds) {
                $q->whereIn('source_id', $sourceIds);
            })->count();
        };

        $ordersWebsite = $countBySourceIds($baseQuery, $websiteSourceIds);
        $prevOrdersWebsite = $countBySourceIds($prevQuery, $websiteSourceIds);

        $ordersWhatsapp = $countBySourceIds($baseQuery, $whatsappSourceIds);
        $prevOrdersWhatsapp = $countBySourceIds($prevQuery, $whatsappSourceIds);

        $ordersTiktok = $countBySourceIds($baseQuery, $tiktokSourceIds);
        $prevOrdersTiktok = $countBySourceIds($prevQuery, $tiktokSourceIds);

        $ordersMagasin = $countBySourceIds($baseQuery, $magasinSourceIds);
        $prevOrdersMagasin = $countBySourceIds($prevQuery, $magasinSourceIds);

        // Helper to count orders in status [7, 10] for specific source IDs
        $countDeliveredBySourceIds = function ($query, $sourceIds) {
            if (empty($sourceIds)) {
                return 0;
            }
            return (clone $query)->whereIn('order_status_id', [7, 10])
                ->whereHas('brandSource', function ($q) use ($sourceIds) {
                    $q->whereIn('source_id', $sourceIds);
                })->count();
        };

        $deliveredWebsite = $countDeliveredBySourceIds($baseQuery, $websiteSourceIds);
        $deliveredWhatsapp = $countDeliveredBySourceIds($baseQuery, $whatsappSourceIds);
        $deliveredTiktok = $countDeliveredBySourceIds($baseQuery, $tiktokSourceIds);
        $deliveredMagasin = $countDeliveredBySourceIds($baseQuery, $magasinSourceIds);

        $websiteDeliveredRate = $ordersWebsite > 0 ? ($deliveredWebsite / $ordersWebsite) * 100 : 0;
        $whatsappDeliveredRate = $ordersWhatsapp > 0 ? ($deliveredWhatsapp / $ordersWhatsapp) * 100 : 0;
        $tiktokDeliveredRate = $ordersTiktok > 0 ? ($deliveredTiktok / $ordersTiktok) * 100 : 0;
        $magasinDeliveredRate = $ordersMagasin > 0 ? ($deliveredMagasin / $ordersMagasin) * 100 : 0;

        // Helper to count orders in status 4 in account_user_order_status
        $countConfirmedBySourceIds = function ($query, $sourceIds) {
            if (empty($sourceIds)) {
                return 0;
            }
            return (clone $query)->whereHas('brandSource', function ($q) use ($sourceIds) {
                $q->whereIn('source_id', $sourceIds);
            })->whereHas('orderComments', function ($q) {
                $q->where('order_status_id', 4);
            })->count();
        };

        $confirmedWebsite = $countConfirmedBySourceIds($baseQuery, $websiteSourceIds);
        $confirmedWhatsapp = $countConfirmedBySourceIds($baseQuery, $whatsappSourceIds);
        $confirmedTiktok = $countConfirmedBySourceIds($baseQuery, $tiktokSourceIds);
        $confirmedMagasin = $countConfirmedBySourceIds($baseQuery, $magasinSourceIds);

        $websiteConfirmedRate = $ordersWebsite > 0 ? ($confirmedWebsite / $ordersWebsite) * 100 : 0;
        $whatsappConfirmedRate = $ordersWhatsapp > 0 ? ($confirmedWhatsapp / $ordersWhatsapp) * 100 : 0;
        $tiktokConfirmedRate = $ordersTiktok > 0 ? ($confirmedTiktok / $ordersTiktok) * 100 : 0;
        $magasinConfirmedRate = $ordersMagasin > 0 ? ($confirmedMagasin / $ordersMagasin) * 100 : 0;

        // Helper to count orders has order_comment with comment_id = 34
        $countRefusedBySourceIds = function ($query, $sourceIds) {
            if (empty($sourceIds)) {
                return 0;
            }
            return (clone $query)->whereHas('brandSource', function ($q) use ($sourceIds) {
                $q->whereIn('source_id', $sourceIds);
            })->whereHas('orderComments', function ($q) {
                $q->where('comment_id', 34);
            })->count();
        };

        $refusedWebsite = $countRefusedBySourceIds($baseQuery, $websiteSourceIds);
        $refusedWhatsapp = $countRefusedBySourceIds($baseQuery, $whatsappSourceIds);
        $refusedTiktok = $countRefusedBySourceIds($baseQuery, $tiktokSourceIds);
        $refusedMagasin = $countRefusedBySourceIds($baseQuery, $magasinSourceIds);

        $websiteRefusedRate = $ordersWebsite > 0 ? ($refusedWebsite / $ordersWebsite) * 100 : 0;
        $whatsappRefusedRate = $ordersWhatsapp > 0 ? ($refusedWhatsapp / $ordersWhatsapp) * 100 : 0;
        $tiktokRefusedRate = $ordersTiktok > 0 ? ($refusedTiktok / $ordersTiktok) * 100 : 0;
        $magasinRefusedRate = $ordersMagasin > 0 ? ($refusedMagasin / $ordersMagasin) * 100 : 0;

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
            if ($index < 9) {
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
                        'trend' => $formatTrend($prevTotalDelivered, $totalDelivered)
                    ],
                    'total_confirmed' => [
                        'value' => number_format($totalConfirmed),
                        'trend' => $formatTrend($prevTotalConfirmed, $totalConfirmed)
                    ],
                    'total_in_transit' => [
                        'value' => number_format($totalInTransit),
                        'trend' => $formatTrend($prevTotalInTransit, $totalInTransit)
                    ],
                    'orders_website' => [
                        'value' => number_format($ordersWebsite),
                        'trend' => $formatTrend($prevOrdersWebsite, $ordersWebsite),
                        'delivered_count' => $deliveredWebsite,
                        'delivered_percentage' => number_format($websiteDeliveredRate, 1),
                        'confirmed_count' => $confirmedWebsite,
                        'confirmed_percentage' => number_format($websiteConfirmedRate, 1),
                        'refused_count' => $refusedWebsite,
                        'refused_percentage' => number_format($websiteRefusedRate, 1)
                    ],
                    'orders_whatsapp' => [
                        'value' => number_format($ordersWhatsapp),
                        'trend' => $formatTrend($prevOrdersWhatsapp, $ordersWhatsapp),
                        'delivered_count' => $deliveredWhatsapp,
                        'delivered_percentage' => number_format($whatsappDeliveredRate, 1),
                        'confirmed_count' => $confirmedWhatsapp,
                        'confirmed_percentage' => number_format($whatsappConfirmedRate, 1),
                        'refused_count' => $refusedWhatsapp,
                        'refused_percentage' => number_format($whatsappRefusedRate, 1)
                    ],
                    'orders_tiktok' => [
                        'value' => number_format($ordersTiktok),
                        'trend' => $formatTrend($prevOrdersTiktok, $ordersTiktok),
                        'delivered_count' => $deliveredTiktok,
                        'delivered_percentage' => number_format($tiktokDeliveredRate, 1),
                        'confirmed_count' => $confirmedTiktok,
                        'confirmed_percentage' => number_format($tiktokConfirmedRate, 1),
                        'refused_count' => $refusedTiktok,
                        'refused_percentage' => number_format($tiktokRefusedRate, 1)
                    ],
                    'orders_magasin' => [
                        'value' => number_format($ordersMagasin),
                        'trend' => $formatTrend($prevOrdersMagasin, $ordersMagasin),
                        'delivered_count' => $deliveredMagasin,
                        'delivered_percentage' => number_format($magasinDeliveredRate, 1),
                        'confirmed_count' => $confirmedMagasin,
                        'confirmed_percentage' => number_format($magasinConfirmedRate, 1),
                        'refused_count' => $refusedMagasin,
                        'refused_percentage' => number_format($magasinRefusedRate, 1)
                    ]
                ],
                'revenue_overview' => $revenueOverview,
                'delivery_status' => $deliveryStatus,
                'top_cities_delivered' => $topCitiesDelivered
            ]
        ]);
    }
}
