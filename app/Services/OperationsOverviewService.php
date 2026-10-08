<?php

namespace App\Services;

use App\Support\OverviewLabels;
use App\Support\Orders\OrderAge;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Account-scoped figures for the logistics, procurement, finance and
 * administration dashboards. Every query is limited to the account's own
 * warehouses / account users, and every summary key is one the frontend reads.
 */
class OperationsOverviewService
{
    /** order_statuses.id */
    private const DELIVERED = [7, 10];
    private const RETURNED = [11];
    private const TROUBLE = [9];

    /** transaction_types.id: Alimentation, Retrait, En attente de payment */
    private const TX_INCOME = 1;
    private const TX_WITHDRAWAL = 2;
    private const TX_PENDING = 3;

    private const EXPENSE_MODEL = 'App\\Models\\Expense';
    private const SHIPMENT_MODEL = 'App\\Models\\Shipment';

    public function __construct(private readonly CatalogOverviewService $catalog)
    {
    }

    // region helpers

    private function accountUserIds(int $accountId): \Closure
    {
        return fn ($q) => $q->select('id')->from('account_user')->where('account_id', $accountId);
    }

    private function metric(float|int|null $current, float|int|null $previous = 0, int $decimals = 0): array
    {
        $current ??= 0;
        $previous ??= 0;
        $trend = $previous == 0 ? ($current > 0 ? 100.0 : 0.0) : round((($current - $previous) / $previous) * 100, 2);

        return ['value' => $decimals ? round($current, $decimals) : $current, 'trend' => $trend];
    }

    /** Metric whose value is already a ratio/percentage: trend is the point difference. */
    private function rateMetric(?float $current, ?float $previous): array
    {
        return [
            'value' => $current,
            'trend' => $current !== null && $previous !== null ? round($current - $previous, 2) : 0,
        ];
    }

    private function days(array $range): int
    {
        return max(1, $range[0]->diffInDays($range[1]) + 1);
    }

    // endregion

    // region logistics

    /** Order statuses still on the road (the carrier has them). */
    private const IN_TRANSIT = [6];

    /** Days an order may stay "In delivery" / "In trouble" before it is flagged as stuck. */
    public const DEFAULT_STUCK_DAYS = 7;

    private const EXCEPTION_LIMIT = 200;

    private const PICKUP_STATUSES = [0 => 'pending', 1 => 'collected', 2 => 'completed'];

    /** Keeps only the carrier / warehouse filters the dashboards send. */
    public static function logisticsFilters(array $query): array
    {
        return array_filter([
            'carrier_id'   => isset($query['carrier_id']) ? (int) $query['carrier_id'] : null,
            'warehouse_id' => isset($query['warehouse_id']) ? (int) $query['warehouse_id'] : null,
        ]);
    }

    public function logistics(array $dates, int $accountId, array $filters = []): array
    {
        $current = $this->logisticsFigures($dates['current'], $accountId, $filters);
        $previous = $this->logisticsFigures($dates['previous'], $accountId, $filters);
        $backlog = $this->logisticsBacklog($accountId, $filters);

        return [
            'summary' => [
                'total_pickups'          => $this->metric($current['pickups'], $previous['pickups']),
                'pending_pickups'        => $this->metric($current['pending_pickups'], $previous['pending_pickups']),
                'total_returns'          => $this->metric($current['returns'], $previous['returns']),
                'total_payments_carrier' => $this->metric($current['carrier_payments'], $previous['carrier_payments'], 2),
                'avg_delivery_time'      => $this->rateMetric($current['avg_delivery_days'], $previous['avg_delivery_days']),
                'return_rate_by_carrier' => $this->rateMetric($current['return_rate'], $previous['return_rate']),
                'active_carriers'        => $this->metric($current['active_carriers'], $previous['active_carriers']),
                'avg_shipping_cost'      => $this->rateMetric($current['avg_shipping_cost'], $previous['avg_shipping_cost']),
                // Snapshots of what is open right now: no previous period to compare with.
                'stuck_orders'           => ['value' => $backlog['stuck'], 'trend' => 0],
                'unpaid_cod'             => ['value' => $backlog['unpaid_amount'], 'trend' => 0],
                'unpaid_cod_orders'      => ['value' => $backlog['unpaid_orders'], 'trend' => 0],
                'returns_not_received'   => ['value' => $backlog['returns_pending'], 'trend' => 0],
            ],
            'pickups_by_status'     => $this->pickupsByStatus($dates['current'], $accountId, $filters),
            'shipments_by_day'      => $this->shipmentsByDay($dates['current'], $accountId, $filters),
            'deliveries_by_carrier' => $this->deliveriesByCarrier($dates['current'], $accountId, $filters),
            'recent_pickups'        => $this->recentPickups($accountId, $filters),
        ];
    }

    /**
     * Open problems, independent of the date range: parcels stuck at the carrier, delivered
     * orders the carrier has not paid for yet, and returns that never came back to a warehouse.
     */
    public function logisticsExceptions(int $accountId, array $filters = [], int $stuckDays = self::DEFAULT_STUCK_DAYS): array
    {
        $stuckDays = max(0, $stuckDays);

        $stuck = $this->exceptionQuery($accountId, $filters)
            ->whereIn('orders.order_status_id', [...self::IN_TRANSIT, ...self::TROUBLE])
            ->havingRaw('days >= ?', [$stuckDays])
            ->get();

        $unpaid = $this->exceptionQuery($accountId, $filters)
            ->where('orders.order_status_id', 7)->whereNull('orders.shipment_id')
            ->get();

        $returns = $this->exceptionQuery($accountId, $filters)
            ->whereIn('orders.order_status_id', self::RETURNED)->whereNull('orders.shipment_id')
            ->get();

        return [
            'stuck_days' => $stuckDays,
            'stuck'      => [
                'total'   => $stuck->count(),
                'buckets' => $this->ageBuckets($stuck),
                'rows'    => $this->exceptionRows($stuck),
            ],
            'receivables' => [
                'total'      => $unpaid->count(),
                'amount'     => round((float) $unpaid->sum('amount'), 2),
                'fees'       => round((float) $unpaid->sum('fee'), 2),
                'by_carrier' => $this->receivablesByCarrier($unpaid),
                'rows'       => $this->exceptionRows($unpaid),
            ],
            'returns_pending' => [
                'total'   => $returns->count(),
                'amount'  => round((float) $returns->sum('amount'), 2),
                'buckets' => $this->ageBuckets($returns),
                'rows'    => $this->exceptionRows($returns),
            ],
        ];
    }

    /** Delivery performance per city and carrier for the orders created in the range. */
    public function logisticsCities(array $range, int $accountId, array $filters = []): array
    {
        $delivered = implode(',', self::DELIVERED);
        $returned = implode(',', self::RETURNED);
        $trouble = implode(',', self::TROUBLE);
        $transit = implode(',', self::IN_TRANSIT);

        return $this->shippedOrders($accountId, $filters)
            ->whereBetween('orders.created_at', $range)
            ->join('cities', 'cities.id', '=', 'orders.city_id')
            ->groupBy('cities.id', 'cities.title', 'carriers.id', 'carriers.title')
            ->selectRaw("cities.id as city_id, cities.title as city, carriers.id as carrier_id, carriers.title as carrier,
                COUNT(*) as shipped,
                SUM(orders.order_status_id IN ($delivered)) as delivered,
                SUM(orders.order_status_id IN ($returned)) as returned,
                SUM(orders.order_status_id IN ($trouble)) as trouble,
                SUM(orders.order_status_id IN ($transit)) as in_transit,
                AVG(CASE WHEN orders.order_status_id IN ($delivered) AND orders.real_carrier_price > 0 THEN orders.real_carrier_price END) as avg_cost")
            ->orderByDesc('shipped')
            ->get()
            ->map(function ($r) {
                $closed = $r->delivered + $r->returned + $r->trouble;

                return [
                    'city_id'     => (int) $r->city_id,
                    'city'        => $r->city,
                    'carrier_id'  => (int) $r->carrier_id,
                    'carrier'     => $r->carrier,
                    'shipped'     => (int) $r->shipped,
                    'delivered'   => (int) $r->delivered,
                    'returned'    => (int) $r->returned,
                    'trouble'     => (int) $r->trouble,
                    'in_transit'  => (int) $r->in_transit,
                    'rate'        => $closed > 0 ? round($r->delivered / $closed * 100, 1) : null,
                    'return_rate' => $closed > 0 ? round($r->returned / $closed * 100, 1) : null,
                    'avg_cost'    => $r->avg_cost === null ? null : round((float) $r->avg_cost, 2),
                ];
            })
            ->all();
    }

    /**
     * Carrier fees billed vs the agreed tariff, for delivered and returned orders created in the range.
     * The tariff is the account's special price (account_carrier_city) when there is one, else the
     * carrier's default price (default_carriers); returned orders are checked against the return price.
     * Groups are (carrier, city, kind, tariff, billed fee) so one bad price shows as one line.
     */
    public function logisticsBilling(array $range, int $accountId, array $filters = []): array
    {
        $returned = implode(',', self::RETURNED);

        $tariff = "CASE WHEN orders.order_status_id IN ($returned)
            THEN COALESCE(acc.`return`, dc.`return`) ELSE COALESCE(acc.price, dc.price) END";

        $groups = $this->shippedOrders($accountId, $filters)
            ->whereBetween('orders.created_at', $range)
            ->whereIn('orders.order_status_id', [...self::DELIVERED, ...self::RETURNED])
            ->leftJoin('cities', 'cities.id', '=', 'orders.city_id')
            ->leftJoin('account_carrier as ac', function ($j) {
                $j->on('ac.carrier_id', '=', 'pickups.carrier_id')->on('ac.account_id', '=', 'orders.account_id')
                    ->whereNull('ac.deleted_at');
            })
            ->leftJoin('account_carrier_city as acc', function ($j) {
                $j->on('acc.account_carrier_id', '=', 'ac.id')->on('acc.city_id', '=', 'orders.city_id');
            })
            ->leftJoin('default_carriers as dc', function ($j) {
                $j->on('dc.carrier_id', '=', 'pickups.carrier_id')->on('dc.city_id', '=', 'orders.city_id')
                    ->whereNull('dc.deleted_at');
            })
            ->groupBy('carriers.id', 'carriers.title', 'cities.id', 'cities.title')
            ->groupByRaw("orders.order_status_id IN ($returned), $tariff, COALESCE(orders.real_carrier_price, 0)")
            ->selectRaw("carriers.id as carrier_id, carriers.title as carrier, cities.id as city_id, cities.title as city,
                orders.order_status_id IN ($returned) as is_return,
                $tariff as tariff, COALESCE(orders.real_carrier_price, 0) as billed,
                MAX(acc.id IS NOT NULL) as special_price, COUNT(*) as orders")
            ->get();

        $byCarrier = [];
        $rows = [];
        foreach ($groups as $g) {
            $c = &$byCarrier[$g->carrier_id];
            $c ??= ['carrier_id' => (int) $g->carrier_id, 'carrier' => $g->carrier, 'orders' => 0, 'billed' => 0.0,
                'expected' => 0.0, 'overbilled_orders' => 0, 'overbilled' => 0.0, 'underbilled_orders' => 0,
                'underbilled' => 0.0, 'no_tariff' => 0, 'not_billed' => 0];

            $orders = (int) $g->orders;
            $billed = (float) $g->billed;
            $c['orders'] += $orders;
            $c['billed'] += $billed * $orders;

            if ($g->tariff === null) {
                $c['no_tariff'] += $orders;
                $status = 'no_tariff';
            } elseif ($billed == 0) {
                $c['not_billed'] += $orders;
                $c['expected'] += (float) $g->tariff * $orders;
                $status = 'not_billed';
            } else {
                $diff = $billed - (float) $g->tariff;
                $c['expected'] += (float) $g->tariff * $orders;
                $status = abs($diff) < 0.5 ? 'ok' : ($diff > 0 ? 'over' : 'under');
                if ($status === 'over') {
                    $c['overbilled_orders'] += $orders;
                    $c['overbilled'] += $diff * $orders;
                } elseif ($status === 'under') {
                    $c['underbilled_orders'] += $orders;
                    $c['underbilled'] += -$diff * $orders;
                }
            }
            unset($c);

            if ($status !== 'ok') {
                $rows[] = [
                    'carrier_id'    => (int) $g->carrier_id,
                    'carrier'       => $g->carrier,
                    'city_id'       => $g->city_id === null ? null : (int) $g->city_id,
                    'city'          => $g->city,
                    'kind'          => $g->is_return ? 'return' : 'delivery',
                    'tariff'        => $g->tariff === null ? null : round((float) $g->tariff, 2),
                    'billed'        => round($billed, 2),
                    'special_price' => (bool) $g->special_price,
                    'orders'        => $orders,
                    'status'        => $status,
                    'impact'        => $g->tariff === null || $billed == 0 ? null : round(($billed - (float) $g->tariff) * $orders, 2),
                ];
            }
        }

        usort($rows, fn ($a, $b) => abs($b['impact'] ?? 0) <=> abs($a['impact'] ?? 0) ?: $b['orders'] <=> $a['orders']);

        return [
            'carriers' => array_values(array_map(fn ($c) => [
                ...$c,
                'billed'      => round($c['billed'], 2),
                'expected'    => round($c['expected'], 2),
                'overbilled'  => round($c['overbilled'], 2),
                'underbilled' => round($c['underbilled'], 2),
            ], $byCarrier)),
            'rows' => $rows,
        ];
    }

    private function pickupsQuery(array $range, int $accountId, array $filters = [])
    {
        return DB::table('pickups')
            ->join('warehouses', 'warehouses.id', '=', 'pickups.warehouse_id')
            ->where('warehouses.account_id', $accountId)
            ->whereNull('pickups.deleted_at')
            ->whereBetween('pickups.created_at', $range)
            ->when($filters['carrier_id'] ?? null, fn ($q, $id) => $q->where('pickups.carrier_id', $id))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('pickups.warehouse_id', $id));
    }

    /** The account's orders handed to a carrier (they belong to a pickup), with the carrier joined. */
    private function shippedOrders(int $accountId, array $filters = [])
    {
        return DB::table('orders')
            ->join('pickups', 'pickups.id', '=', 'orders.pickup_id')
            ->join('carriers', 'carriers.id', '=', 'pickups.carrier_id')
            ->where('orders.account_id', $accountId)
            ->whereNull('orders.deleted_at')
            ->when($filters['carrier_id'] ?? null, fn ($q, $id) => $q->where('pickups.carrier_id', $id))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('pickups.warehouse_id', $id));
    }

    /** Value of the order's active lines (same rule as Order::activePvas). */
    private function orderAmountSql(): string
    {
        return '(SELECT COALESCE(SUM(op.price * op.quantity), 0) FROM order_pva op
            WHERE op.order_id = orders.id AND op.deleted_at IS NULL AND op.order_status_id NOT IN (2, 3))';
    }

    /** Days since the order entered its current status (same rule as the order workspaces). */
    private function statusAgeSql(): string
    {
        return OrderAge::daysSql();
    }

    /** Shipped orders with what the exception lists show, oldest in their status first. */
    private function exceptionQuery(int $accountId, array $filters)
    {
        return $this->shippedOrders($accountId, $filters)
            ->selectRaw('orders.id, orders.code, orders.shipping_code, orders.order_status_id, orders.created_at,
                COALESCE(orders.real_carrier_price, 0) as fee, pickups.id as pickup_id, pickups.code as pickup_code,
                carriers.id as carrier_id, carriers.title as carrier,
                (SELECT title FROM cities WHERE cities.id = orders.city_id) as city,
                (SELECT name FROM customers WHERE customers.id = orders.customer_id) as customer')
            ->selectRaw($this->orderAmountSql() . ' as amount')
            ->selectRaw($this->statusAgeSql() . ' as days')
            ->orderByDesc('days');
    }

    private function exceptionRows($rows): array
    {
        return $rows->take(self::EXCEPTION_LIMIT)->map(fn ($r) => [
            'id'            => (int) $r->id,
            'code'          => $r->code,
            'shipping_code' => $r->shipping_code,
            'status'        => OverviewLabels::orderStatus((int) $r->order_status_id),
            'status_id'     => (int) $r->order_status_id,
            'customer'      => $r->customer,
            'city'          => $r->city,
            'carrier_id'    => (int) $r->carrier_id,
            'carrier'       => $r->carrier,
            'pickup_id'     => (int) $r->pickup_id,
            'pickup_code'   => $r->pickup_code,
            'amount'        => round((float) $r->amount, 2),
            'fee'           => round((float) $r->fee, 2),
            'days'          => (int) $r->days,
            'created_at'    => Carbon::parse($r->created_at)->toDateString(),
        ])->values()->all();
    }

    private function ageBuckets($rows): array
    {
        $buckets = ['0-7' => 0, '8-14' => 0, '15-30' => 0, '30+' => 0];
        foreach ($rows as $r) {
            $key = match (true) {
                $r->days <= 7  => '0-7',
                $r->days <= 14 => '8-14',
                $r->days <= 30 => '15-30',
                default        => '30+',
            };
            $buckets[$key]++;
        }

        return $buckets;
    }

    /** What each carrier still owes: cash collected on delivered orders, minus its fees. */
    private function receivablesByCarrier($rows): array
    {
        return $rows->groupBy('carrier_id')->map(fn ($group) => [
            'carrier_id'  => (int) $group->first()->carrier_id,
            'carrier'     => $group->first()->carrier,
            'orders'      => $group->count(),
            'amount'      => round((float) $group->sum('amount'), 2),
            'fees'        => round((float) $group->sum('fee'), 2),
            'net'         => round((float) $group->sum('amount') - (float) $group->sum('fee'), 2),
            'oldest_days' => (int) $group->max('days'),
        ])->sortByDesc('net')->values()->all();
    }

    /** Counts of the open backlog for the summary cards. */
    private function logisticsBacklog(int $accountId, array $filters): array
    {
        $stuckStatuses = implode(',', [...self::IN_TRANSIT, ...self::TROUBLE]);
        $returned = implode(',', self::RETURNED);
        $amount = $this->orderAmountSql();
        $age = $this->statusAgeSql();

        $counts = $this->shippedOrders($accountId, $filters)
            ->whereIn('orders.order_status_id', [...self::IN_TRANSIT, ...self::TROUBLE, ...self::RETURNED, 7])
            ->selectRaw("SUM(orders.order_status_id IN ($stuckStatuses) AND $age >= ?) as stuck,
                SUM(orders.order_status_id = 7 AND orders.shipment_id IS NULL) as unpaid_orders,
                SUM(CASE WHEN orders.order_status_id = 7 AND orders.shipment_id IS NULL THEN $amount ELSE 0 END) as unpaid_amount,
                SUM(orders.order_status_id IN ($returned) AND orders.shipment_id IS NULL) as returns_pending", [self::DEFAULT_STUCK_DAYS])
            ->first();

        return [
            'stuck'           => (int) $counts->stuck,
            'unpaid_orders'   => (int) $counts->unpaid_orders,
            'unpaid_amount'   => round((float) $counts->unpaid_amount, 2),
            'returns_pending' => (int) $counts->returns_pending,
        ];
    }

    private function logisticsFigures(array $range, int $accountId, array $filters = []): array
    {
        $returned = implode(',', self::RETURNED);
        $delivered = implode(',', self::DELIVERED);
        $trouble = implode(',', self::TROUBLE);

        $orders = $this->shippedOrders($accountId, $filters)
            ->whereBetween('orders.created_at', $range)
            ->selectRaw("SUM(orders.order_status_id IN ($delivered)) as delivered, SUM(orders.order_status_id IN ($returned)) as returned,
                SUM(orders.order_status_id IN ($trouble)) as trouble,
                AVG(CASE WHEN orders.order_status_id IN ($delivered) AND orders.real_carrier_price > 0 THEN orders.real_carrier_price END) as avg_cost")
            ->first();
        $closed = (int) $orders->delivered + (int) $orders->returned + (int) $orders->trouble;

        $returnSlips = DB::table('shipments')->whereIn('account_user_id', $this->accountUserIds($accountId))
            ->where('is_return', 1)->whereNull('deleted_at')->whereBetween('created_at', $range)
            ->when($filters['carrier_id'] ?? null, fn ($q, $id) => $q->where('carrier_id', $id))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id));

        $payments = $this->transactions($accountId, $range)
            ->where('transaction_type', self::SHIPMENT_MODEL)->where('transaction_type_id', self::TX_INCOME);
        if ($filters) {
            $payments->whereIn('transaction_id', fn ($q) => $q->select('id')->from('shipments')
                ->when($filters['carrier_id'] ?? null, fn ($q, $id) => $q->where('carrier_id', $id))
                ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id)));
        }

        return [
            'pickups'           => $this->pickupsQuery($range, $accountId, $filters)->count(),
            'pending_pickups'   => $this->pickupsQuery($range, $accountId, $filters)->where('pickups.statut', 0)->count(),
            'active_carriers'   => $this->pickupsQuery($range, $accountId, $filters)->distinct()->count('pickups.carrier_id'),
            'returns'           => $returnSlips->count(),
            'carrier_payments'  => (float) $payments->sum('amount'),
            'return_rate'       => $closed > 0 ? round((int) $orders->returned / $closed * 100, 2) : null,
            'avg_delivery_days' => $this->averageDeliveryDays($range, $accountId, $filters),
            'avg_shipping_cost' => $orders->avg_cost === null ? null : round((float) $orders->avg_cost, 2),
        ];
    }

    /** Average days between "En livraison" and "Livrée" in the order status history. */
    private function averageDeliveryDays(array $range, int $accountId, array $filters = []): ?float
    {
        $orderIds = fn ($q) => $q->select('orders.id')->from('orders')
            ->where('orders.account_id', $accountId)->whereNull('orders.deleted_at')->whereBetween('orders.created_at', $range)
            ->when($filters, fn ($q) => $q->join('pickups', 'pickups.id', '=', 'orders.pickup_id')
                ->when($filters['carrier_id'] ?? null, fn ($q, $id) => $q->where('pickups.carrier_id', $id))
                ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('pickups.warehouse_id', $id)));

        $first = fn (int $status) => DB::table('account_user_order_status')
            ->whereNull('deleted_at')->where('order_status_id', $status)->whereIn('order_id', $orderIds)
            ->groupBy('order_id')->selectRaw('order_id, MIN(created_at) as at');

        $hours = DB::query()
            ->fromSub($first(6), 'shipped')
            ->joinSub($first(7), 'delivered', 'delivered.order_id', '=', 'shipped.order_id')
            ->whereColumn('delivered.at', '>=', 'shipped.at')
            ->avg(DB::raw('TIMESTAMPDIFF(HOUR, shipped.at, delivered.at)'));

        return $hours === null ? null : round($hours / 24, 1);
    }

    private function pickupsByStatus(array $range, int $accountId, array $filters = [])
    {
        return $this->pickupsQuery($range, $accountId, $filters)
            ->groupBy('pickups.statut')
            ->selectRaw('pickups.statut as status, COUNT(*) as count')
            ->get()
            ->map(fn ($r) => ['status' => self::PICKUP_STATUSES[$r->status] ?? 'unknown', 'count' => (int) $r->count]);
    }

    /** Pickups and the orders they carried, per day of the range (days without pickups are 0). */
    private function shipmentsByDay(array $range, int $accountId, array $filters = []): array
    {
        $rows = $this->pickupsQuery($range, $accountId, $filters)
            ->groupByRaw('DATE(pickups.created_at)')
            ->selectRaw('DATE(pickups.created_at) as day, COUNT(*) as pickups,
                SUM((SELECT COUNT(*) FROM orders o WHERE o.pickup_id = pickups.id AND o.deleted_at IS NULL)) as orders')
            ->get()
            ->keyBy('day');

        $days = [];
        for ($day = $range[0]->copy()->startOfDay(); $day->lte($range[1]); $day->addDay()) {
            $row = $rows->get($day->toDateString());
            $days[] = [
                'date'    => $day->toDateString(),
                'pickups' => (int) ($row->pickups ?? 0),
                'orders'  => (int) ($row->orders ?? 0),
            ];
        }

        return $days;
    }

    private function deliveriesByCarrier(array $range, int $accountId, array $filters = [])
    {
        $delivered = implode(',', self::DELIVERED);
        $returned = implode(',', self::RETURNED);
        $trouble = implode(',', self::TROUBLE);

        return $this->shippedOrders($accountId, $filters)
            ->whereBetween('orders.created_at', $range)
            ->groupBy('carriers.id', 'carriers.title')
            ->selectRaw("carriers.id as carrier_id, carriers.title as carrier_name,
                SUM(orders.order_status_id IN ($delivered)) as total_delivered,
                SUM(orders.order_status_id IN ($returned)) as total_returned,
                SUM(orders.order_status_id IN ($trouble)) as total_trouble")
            ->get()
            ->map(function ($r) {
                $closed = $r->total_delivered + $r->total_returned + $r->total_trouble;
                $r->delivery_rate = $closed > 0 ? round($r->total_delivered / $closed * 100, 2) : 0;

                return $r;
            });
    }

    private function recentPickups(int $accountId, array $filters = [])
    {
        return DB::table('pickups')
            ->join('warehouses', 'warehouses.id', '=', 'pickups.warehouse_id')
            ->leftJoin('carriers', 'carriers.id', '=', 'pickups.carrier_id')
            ->where('warehouses.account_id', $accountId)->whereNull('pickups.deleted_at')
            ->when($filters['carrier_id'] ?? null, fn ($q, $id) => $q->where('pickups.carrier_id', $id))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('pickups.warehouse_id', $id))
            ->orderByDesc('pickups.created_at')->limit(5)
            ->selectRaw('pickups.id, pickups.code, pickups.statut, pickups.created_at, carriers.title as carrier,
                warehouses.title as warehouse,
                (SELECT COUNT(*) FROM orders o WHERE o.pickup_id = pickups.id AND o.deleted_at IS NULL) as orders_count')
            ->get()
            ->map(fn ($p) => [
                'id'           => $p->id,
                'code'         => $p->code,
                'carrier'      => $p->carrier ?? 'N/A',
                'warehouse'    => $p->warehouse,
                'orders_count' => (int) $p->orders_count,
                'status'       => self::PICKUP_STATUSES[$p->statut] ?? 'unknown',
                'created_at'   => Carbon::parse($p->created_at)->toDateString(),
            ]);
    }

    // endregion

    // region procurement

    public function procurement(array $dates, int $accountId): array
    {
        $current = $dates['current'];
        $previous = $dates['previous'];

        $suppliers = fn () => DB::table('suppliers')->where('account_id', $accountId)->whereNull('deleted_at');
        $orders = fn (array $range) => DB::table('supplier_orders')->whereIn('account_user_id', $this->accountUserIds($accountId))
            ->whereNull('deleted_at')->whereBetween('created_at', $range);
        $receipts = fn (array $range) => DB::table('supplier_receipts')->whereIn('account_user_id', $this->accountUserIds($accountId))
            ->whereNull('deleted_at')->whereBetween('created_at', $range);

        $stockUnits = (float) $this->stockQuery($accountId)->sum(DB::raw('GREATEST(warehouse_pva.quantity, 0)'));
        $deliveredUnits = $this->deliveredUnits($current, $accountId);
        $previousDeliveredUnits = $this->deliveredUnits($previous, $accountId);

        return [
            'summary' => [
                'total_suppliers'       => $this->metric($suppliers()->count(), $suppliers()->where('created_at', '<', $current[0])->count()),
                'total_purchase_orders' => $this->metric($orders($current)->count(), $orders($previous)->count()),
                'pending_orders'        => $this->metric($orders($current)->where('statut', 0)->count(), $orders($previous)->where('statut', 0)->count()),
                'total_receipts'        => $this->metric($receipts($current)->count(), $receipts($previous)->count()),
                'total_inventory_value' => $this->metric($this->inventoryValue($accountId), 0, 2),
                'inventory_value_at_cost' => $this->metric($this->inventoryValue($accountId, true), 0, 2),
                'stock_turnover_rate'   => $this->rateMetric(
                    $stockUnits > 0 ? round($deliveredUnits / $stockUnits, 2) : null,
                    $stockUnits > 0 ? round($previousDeliveredUnits / $stockUnits, 2) : null
                ),
                'supplier_lead_time'    => $this->rateMetric($this->supplierLeadDays($current, $accountId), $this->supplierLeadDays($previous, $accountId)),
            ],
            'purchase_orders_by_status' => $this->purchaseOrdersByStatus($current, $accountId),
            'stock_by_warehouse'        => $this->stockByWarehouse($accountId),
            'recent_movements'          => $this->recentMovements($accountId),
            'low_stock_alerts'          => $this->lowStockAlerts($accountId, 10),
        ];
    }

    private function stockQuery(int $accountId)
    {
        return DB::table('warehouse_pva')
            ->join('warehouses', 'warehouses.id', '=', 'warehouse_pva.warehouse_id')
            ->where('warehouses.account_id', $accountId)->whereNull('warehouses.deleted_at');
    }

    /** Value of positive stock: at retail (active offer price) or at cost (supplier price). */
    private function inventoryValue(int $accountId, bool $atCost = false): float
    {
        $unit = $this->unitPriceSql($atCost);

        return (float) $this->stockQuery($accountId)
            ->join('product_variation_attribute as pva', 'pva.id', '=', 'warehouse_pva.product_variation_attribute_id')
            ->sum(DB::raw("GREATEST(warehouse_pva.quantity, 0) * COALESCE($unit, 0)"));
    }

    /** Unit price of a variation (alias "pva" must be in scope): supplier cost or active retail offer. */
    private function unitPriceSql(bool $atCost): string
    {
        return $atCost
            ? '(SELECT AVG(sp.price) FROM supplier_pva sp WHERE sp.product_variation_attribute_id = pva.id AND sp.price > 0)'
            : "(SELECT MAX(o.price) FROM offerables ofr JOIN offers o ON o.id = ofr.offer_id
                WHERE ofr.offerable_type = 'App\\\\Models\\\\Product' AND ofr.offerable_id = pva.product_id
                AND ofr.deleted_at IS NULL AND o.deleted_at IS NULL AND o.offer_type_id = 1 AND o.statut = 1)";
    }

    private function deliveredUnits(array $range, int $accountId): float
    {
        return (float) DB::table('order_pva as op')
            ->join('orders as o', 'o.id', '=', 'op.order_id')
            ->where('o.account_id', $accountId)->whereNull('o.deleted_at')->whereNull('op.deleted_at')
            ->whereIn('o.order_status_id', self::DELIVERED)
            ->whereBetween('o.created_at', $range)
            ->sum('op.quantity');
    }

    /** Average days between a purchase order and the next receipt from the same supplier. */
    private function supplierLeadDays(array $range, int $accountId): ?float
    {
        $hours = DB::table('supplier_receipts as sr')
            ->whereIn('sr.account_user_id', $this->accountUserIds($accountId))
            ->whereNull('sr.deleted_at')->whereBetween('sr.created_at', $range)
            ->avg(DB::raw('TIMESTAMPDIFF(HOUR, (SELECT MAX(so.created_at) FROM supplier_orders so
                WHERE so.supplier_id = sr.supplier_id AND so.deleted_at IS NULL AND so.created_at <= sr.created_at), sr.created_at)'));

        return $hours === null ? null : round($hours / 24, 1);
    }

    private function purchaseOrdersByStatus(array $range, int $accountId)
    {
        $statusMap = [0 => 'draft', 1 => 'confirmed', 2 => 'received', 3 => 'cancelled'];

        return DB::table('supplier_orders')
            ->whereIn('account_user_id', $this->accountUserIds($accountId))
            ->whereNull('deleted_at')->whereBetween('created_at', $range)
            ->groupBy('statut')->selectRaw('statut as status, COUNT(*) as count')
            ->get()
            ->map(fn ($r) => ['status' => $statusMap[$r->status] ?? 'unknown', 'count' => $r->count]);
    }

    private function stockByWarehouse(int $accountId)
    {
        $unit = $this->unitPriceSql(false);

        return DB::table('warehouses')
            ->leftJoin('warehouse_pva', 'warehouses.id', '=', 'warehouse_pva.warehouse_id')
            ->leftJoin('product_variation_attribute as pva', 'pva.id', '=', 'warehouse_pva.product_variation_attribute_id')
            ->where('warehouses.account_id', $accountId)->whereNull('warehouses.deleted_at')
            ->groupBy('warehouses.id', 'warehouses.title')
            ->selectRaw("warehouses.id as warehouse_id, warehouses.title as warehouse_name,
                COALESCE(SUM(warehouse_pva.quantity), 0) as total_stock,
                COUNT(DISTINCT pva.product_id) as total_products,
                COALESCE(SUM(GREATEST(warehouse_pva.quantity, 0) * COALESCE($unit, 0)), 0) as total_value")
            ->get();
    }

    private function recentMovements(int $accountId)
    {
        return DB::table('mouvements as m')
            ->leftJoin('warehouses as w', 'w.id', '=', 'm.to_warehouse')
            ->whereIn('m.account_user_id', $this->accountUserIds($accountId))->whereNull('m.deleted_at')
            ->orderByDesc('m.created_at')->limit(5)
            ->selectRaw('m.id, m.mouvement_type_id, m.created_at, w.title as warehouse,
                (SELECT COALESCE(SUM(mp.quantity), 0) FROM mouvement_pva mp WHERE mp.mouvement_id = m.id AND mp.deleted_at IS NULL) as quantity,
                (SELECT COUNT(DISTINCT pv.product_id) FROM mouvement_pva mp JOIN product_variation_attribute pv ON pv.id = mp.product_variation_attribute_id
                    WHERE mp.mouvement_id = m.id AND mp.deleted_at IS NULL) as products_count,
                (SELECT p.title FROM mouvement_pva mp JOIN product_variation_attribute pv ON pv.id = mp.product_variation_attribute_id
                    JOIN products p ON p.id = pv.product_id WHERE mp.mouvement_id = m.id AND mp.deleted_at IS NULL LIMIT 1) as first_product')
            ->get()
            ->map(fn ($m) => [
                'id'           => $m->id,
                'product_name' => $m->first_product
                    ? $m->first_product . ($m->products_count > 1 ? ' +' . ($m->products_count - 1) : '')
                    : 'N/A',
                'type'       => [1 => 'move', 2 => 'load', 3 => 'return', 4 => 'transfer', 5 => 'receipt', 6 => 'exit'][$m->mouvement_type_id] ?? 'movement',
                'quantity'   => (float) $m->quantity,
                'warehouse'  => $m->warehouse ?? 'N/A',
                'created_at' => Carbon::parse($m->created_at)->toDateString(),
            ]);
    }

    private function lowStockAlerts(int $accountId, int $threshold)
    {
        return $this->stockQuery($accountId)
            ->join('product_variation_attribute as pva', 'pva.id', '=', 'warehouse_pva.product_variation_attribute_id')
            ->join('products', 'products.id', '=', 'pva.product_id')
            ->whereNull('pva.deleted_at')->whereNull('products.deleted_at')
            ->where('warehouse_pva.quantity', '<', $threshold)
            ->orderBy('warehouse_pva.quantity')->limit(5)
            ->get(['products.id', 'products.id as product_id', 'products.title as product_name', 'warehouses.title as warehouse', 'warehouse_pva.quantity as current_stock'])
            ->map(function ($row) {
                $row->status = $row->current_stock < 0 ? 'Negative stock' : ($row->current_stock == 0 ? 'Out of stock' : 'Low stock');

                return $row;
            });
    }

    // endregion

    // region finance

    private function transactions(int $accountId, array $range)
    {
        return DB::table('transactions')
            ->whereIn('transactions.account_user_id', $this->accountUserIds($accountId))
            ->whereNull('transactions.deleted_at')
            ->whereBetween('transactions.created_at', $range);
    }

    private function sumType(int $accountId, array $range, int $typeId, ?string $model = null): float
    {
        $query = $this->transactions($accountId, $range)->where('transaction_type_id', $typeId);
        if ($model !== null) {
            $query->where('transaction_type', $model);
        }

        return (float) $query->sum('amount');
    }

    public function finance(array $dates, int $accountId): array
    {
        $figures = fn (array $range) => [
            'income'      => $this->sumType($accountId, $range, self::TX_INCOME),
            'withdrawals' => $this->sumType($accountId, $range, self::TX_WITHDRAWAL),
            'pending'     => $this->sumType($accountId, $range, self::TX_PENDING),
            'expenses'    => (float) $this->transactions($accountId, $range)->where('transaction_type', self::EXPENSE_MODEL)->sum('amount'),
        ];

        $current = $figures($dates['current']);
        $previous = $figures($dates['previous']);

        $burnRange = fn (array $range) => [$range[1]->copy()->subDays(89)->startOfDay(), $range[1]];
        $burn = fn (array $range) => round($this->sumType($accountId, $burnRange($range), self::TX_WITHDRAWAL) / 3, 2);

        $net = fn (array $f) => $f['income'] - $f['withdrawals'];
        $days = $this->days($dates['current']);

        return [
            'summary' => [
                'total_transactions_amount' => $this->metric($current['income'] + $current['withdrawals'], $previous['income'] + $previous['withdrawals'], 2),
                'total_income'              => $this->metric($current['income'], $previous['income'], 2),
                'total_withdrawals'         => $this->metric($current['withdrawals'], $previous['withdrawals'], 2),
                'total_expenses'            => $this->metric($current['expenses'], $previous['expenses'], 2),
                'pending_payments'          => $this->metric($current['pending'], $previous['pending'], 2),
                'net_balance'               => $this->metric($net($current), $net($previous), 2),
                'monthly_burn_rate'         => $this->metric($burn($dates['current']), $burn($dates['previous']), 2),
                'net_profit_margin'         => $this->netProfitMargin($dates, $accountId, $current['expenses'], $previous['expenses']),
                'projected_30d_cash_flow'   => $this->metric(round($net($current) / $days * 30, 2), round($net($previous) / $this->days($dates['previous']) * 30, 2), 2),
            ],
            'transactions_by_month' => $this->transactionsByMonth($dates['current'], $accountId),
            'expenses_breakdown'    => $this->expensesBreakdown($dates['current'], $accountId),
            'recent_transactions'   => $this->recentTransactions($accountId),
            'transactions_by_user'  => $this->transactionsByUser($dates['current'], $accountId),
        ];
    }

    /** (delivered revenue - cost of goods - expenses) / delivered revenue. */
    private function netProfitMargin(array $dates, int $accountId, float $expenses, float $previousExpenses): array
    {
        $margin = function (array $range, float $expenses) use ($accountId) {
            $profit = $this->catalog->grossProfit($range, $accountId);

            return $profit['revenue'] > 0
                ? round((($profit['revenue'] - $profit['cost'] - $expenses) / $profit['revenue']) * 100, 2)
                : null;
        };

        return $this->rateMetric($margin($dates['current'], $expenses), $margin($dates['previous'], $previousExpenses));
    }

    private function transactionsByMonth(array $range, int $accountId)
    {
        return $this->transactions($accountId, $range)
            ->whereIn('transaction_type_id', [self::TX_INCOME, self::TX_WITHDRAWAL])
            ->groupBy('month')->orderBy('month')
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month,
                SUM(CASE WHEN transaction_type_id = " . self::TX_INCOME . " THEN amount ELSE 0 END) as income,
                SUM(CASE WHEN transaction_type_id = " . self::TX_WITHDRAWAL . " THEN amount ELSE 0 END) as expense")
            ->get();
    }

    private function expensesBreakdown(array $range, int $accountId)
    {
        $rows = $this->transactions($accountId, $range)
            ->where('transaction_type_id', self::TX_WITHDRAWAL)
            ->leftJoin('expenses', function ($j) {
                $j->on('expenses.id', '=', 'transactions.transaction_id')
                    ->where('transactions.transaction_type', self::EXPENSE_MODEL);
            })
            ->leftJoin('expense_types', 'expense_types.id', '=', 'expenses.expense_type_id')
            ->groupBy('expense_types.id', 'expense_types.title')
            ->selectRaw('expense_types.id as expense_type_id, expense_types.title as expense_title, SUM(transactions.amount) as amount')
            ->get();

        $total = (float) $rows->sum('amount');

        return $rows->map(function ($r) use ($total) {
            $r = (object) [
                // withdrawals that are not an expense are payouts to the carriers' account
                'category' => $r->expense_type_id ? OverviewLabels::expenseType($r->expense_type_id, $r->expense_title) : 'Carrier payouts',
                'amount'   => $r->amount,
            ];
            $r->percentage = $total > 0 ? round($r->amount / $total * 100, 2) : 0;

            return $r;
        });
    }

    private function recentTransactions(int $accountId)
    {
        return DB::table('transactions as t')
            ->leftJoin('transaction_types as tt', 'tt.id', '=', 't.transaction_type_id')
            ->whereIn('t.account_user_id', $this->accountUserIds($accountId))->whereNull('t.deleted_at')
            ->orderByDesc('t.created_at')->limit(5)
            ->get(['t.id', 't.code', 't.amount', 't.statut', 't.created_at', 't.transaction_type_id', 'tt.title as type_title'])
            ->map(fn ($t) => [
                'id'          => $t->id,
                'description' => trim((OverviewLabels::transactionType($t->transaction_type_id, $t->type_title) ?? 'Transaction') . ' ' . $t->code),
                'amount'      => (float) $t->amount,
                'type'        => $t->transaction_type_id == self::TX_INCOME ? 'income' : ($t->transaction_type_id == self::TX_PENDING ? 'pending' : 'expense'),
                'status'      => $t->statut == 1 ? 'completed' : 'pending',
                'created_at'  => Carbon::parse($t->created_at)->toDateString(),
            ]);
    }

    private function transactionsByUser(array $range, int $accountId)
    {
        return DB::table('transactions as t')
            ->join('account_user as au', 'au.id', '=', 't.account_user_id')
            ->join('users as u', 'u.id', '=', 'au.user_id')
            ->where('au.account_id', $accountId)->whereNull('t.deleted_at')
            ->whereBetween('t.created_at', $range)
            ->groupBy('au.id', 'u.firstname', 'u.lastname')
            ->selectRaw("au.id as user_id, CONCAT(u.firstname, ' ', u.lastname) as user_name,
                SUM(CASE WHEN t.transaction_type_id = " . self::TX_INCOME . " THEN t.amount ELSE 0 END) as total_income,
                SUM(CASE WHEN t.transaction_type_id = " . self::TX_WITHDRAWAL . " THEN t.amount ELSE 0 END) as total_withdrawals")
            ->get();
    }

    // endregion

    // region administration

    public function administration(array $dates, int $accountId): array
    {
        $users = fn () => DB::table('account_user as au')
            ->join('users as u', 'u.id', '=', 'au.user_id')
            ->where('au.account_id', $accountId)->whereNull('u.deleted_at');

        $monthStart = now()->startOfMonth();
        $previousMonthStart = now()->subMonthNoOverflow()->startOfMonth();

        $total = $users()->distinct()->count('u.id');
        $active = $users()->where('u.statut', 1)->distinct()->count('u.id');

        return [
            'summary' => [
                'total_users'          => $this->metric($total, $users()->where('u.created_at', '<', $dates['current'][0])->distinct()->count('u.id')),
                'active_users'         => ['value' => $active, 'trend' => 0],
                'total_roles'          => ['value' => $this->usersByRole($accountId)->count(), 'trend' => 0],
                'new_users_month'      => $this->metric(
                    $users()->where('u.created_at', '>=', $monthStart)->distinct()->count('u.id'),
                    $users()->whereBetween('u.created_at', [$previousMonthStart, $monthStart->copy()->subSecond()])->distinct()->count('u.id')
                ),
                'user_activity_score'  => $this->rateMetric(
                    $this->activityScore($dates['current'], $accountId, $total),
                    $this->activityScore($dates['previous'], $accountId, $total)
                ),
            ],
            'users_by_role'  => $this->usersByRole($accountId),
            'user_activity'  => $this->userActivity($dates['current'], $accountId),
            'recent_users'   => $this->recentUsers($accountId),
            'top_performers' => $this->topPerformers($dates['current'], $accountId),
        ];
    }

    /** % of the account's users who handled at least one order in the period. */
    private function activityScore(array $range, int $accountId, int $totalUsers): ?float
    {
        if ($totalUsers === 0) {
            return null;
        }

        $active = DB::table('account_user_order_status as s')
            ->whereIn('s.account_user_id', $this->accountUserIds($accountId))
            ->whereNull('s.deleted_at')->whereBetween('s.created_at', $range)
            ->distinct()->count('s.account_user_id');

        return round($active / $totalUsers * 100, 1);
    }

    /** Order-handling actions per day (the only user activity the system records). */
    private function userActivity(array $range, int $accountId)
    {
        return DB::table('account_user_order_status as s')
            ->whereIn('s.account_user_id', $this->accountUserIds($accountId))
            ->whereNull('s.deleted_at')->whereBetween('s.created_at', $range)
            ->groupBy('date')->orderBy('date')
            ->selectRaw('DATE(s.created_at) as date, COUNT(*) as actions, COUNT(DISTINCT s.account_user_id) as users')
            ->get();
    }

    private function usersByRole(int $accountId)
    {
        return DB::table('model_has_roles as mr')
            ->join('roles', 'roles.id', '=', 'mr.role_id')
            ->where('mr.model_type', 'App\\Models\\AccountUser')
            ->whereIn('mr.model_id', $this->accountUserIds($accountId))
            ->groupBy('roles.id', 'roles.name')
            ->orderByDesc('count')
            ->selectRaw('roles.name as role_name, COUNT(mr.model_id) as count')
            ->get();
    }

    private function recentUsers(int $accountId)
    {
        return DB::table('account_user as au')
            ->join('users as u', 'u.id', '=', 'au.user_id')
            ->where('au.account_id', $accountId)->whereNull('u.deleted_at')
            ->orderByDesc('u.created_at')->limit(5)
            ->selectRaw("u.id, CONCAT(u.firstname, ' ', u.lastname) as name, u.email, u.statut, u.created_at,
                (SELECT r.name FROM model_has_roles mr JOIN roles r ON r.id = mr.role_id
                 WHERE mr.model_type = 'App\\\\Models\\\\AccountUser' AND mr.model_id = au.id LIMIT 1) as role")
            ->get()
            ->map(fn ($u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'role'       => $u->role ?? 'N/A',
                'status'     => $u->statut == 1 ? 'active' : 'inactive',
                'created_at' => Carbon::parse($u->created_at)->toDateString(),
            ]);
    }

    private function topPerformers(array $range, int $accountId)
    {
        // One row per (user, order): an order has several status-history rows per user.
        $handled = DB::table('account_user_order_status as s')
            ->join('account_user as a', 'a.id', '=', 's.account_user_id')
            ->join('orders as o', 'o.id', '=', 's.order_id')
            ->where('a.account_id', $accountId)->whereNull('s.deleted_at')->whereNull('o.deleted_at')
            ->whereBetween('o.created_at', $range)
            ->whereNotIn('o.order_status_id', [2, 3])
            ->select('s.account_user_id as au_id', 'o.id as order_id')
            ->distinct();

        return DB::query()->fromSub($handled, 'x')
            ->join('account_user as au', 'au.id', '=', 'x.au_id')
            ->join('users as u', 'u.id', '=', 'au.user_id')
            ->groupBy('au.id', 'u.firstname', 'u.lastname')
            ->orderByDesc('orders_count')->limit(5)
            ->selectRaw("au.id as user_id, CONCAT(u.firstname, ' ', u.lastname) as user_name,
                COUNT(*) as orders_count,
                COALESCE(SUM((SELECT SUM(op.price * op.quantity) FROM order_pva op WHERE op.order_id = x.order_id AND op.deleted_at IS NULL)), 0) as revenue")
            ->get();
    }

    // endregion
}
