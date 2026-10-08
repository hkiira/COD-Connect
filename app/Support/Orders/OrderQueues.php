<?php

namespace App\Support\Orders;

use App\Services\OperationsOverviewService;
use Illuminate\Database\Eloquent\Builder;

/**
 * The work queues of the order workspaces (confirmation, tracking, recovery). A queue is a filter on the
 * orders query: the list and the tab counters both go through apply(), so a badge always matches its rows.
 * Tracking and recovery queues follow the logistics exceptions rules (shipped = on a pickup).
 */
final class OrderQueues
{
    /** Queues of each workspace, in tab order. */
    public const WORKSPACES = [
        'confirmation' => ['new', 'callbacks_due', 'scheduled', 'no_answer', 'out_of_stock', 'abandoned_recent'],
        'tracking'     => ['to_ship', 'in_delivery', 'in_trouble', 'stuck', 'no_tracking'],
        'recovery'     => ['unpaid', 'returns_pending', 'paid'],
    ];

    /** An abandoned order is still worth a call back for this many days. */
    public const ABANDONED_RECENT_DAYS = 7;

    /** Statuses a callback can be set in: the customer has not confirmed yet. */
    public const CALLBACK_STATUSES = [OrderStatus::PENDING, OrderStatus::OUT_OF_STOCK];

    public static function exists(?string $key): bool
    {
        if (! is_string($key) || ! str_contains($key, '.')) {
            return false;
        }
        [$workspace, $queue] = explode('.', $key, 2);

        return in_array($queue, self::WORKSPACES[$workspace] ?? [], true);
    }

    /** @return string[] the queue keys of a workspace, e.g. "confirmation.new" */
    public static function keys(string $workspace): array
    {
        return array_map(fn ($queue) => "{$workspace}.{$queue}", self::WORKSPACES[$workspace] ?? []);
    }

    /**
     * Restricts an orders query to a queue. `min_age_days` / `max_age_days` filter on the days spent in the
     * current status (the stuck threshold, the age buckets of unpaid deliveries).
     *
     * @param  array{min_age_days?: int|null, max_age_days?: int|null}  $options
     */
    public static function apply(Builder $query, string $key, array $options = []): Builder
    {
        $now = now();

        match ($key) {
            'confirmation.new' => $query->where('orders.order_status_id', OrderStatus::PENDING)
                ->whereNull('orders.callback_at')
                ->whereDoesntHave('calls'),
            'confirmation.callbacks_due' => $query->whereIn('orders.order_status_id', self::CALLBACK_STATUSES)
                ->where('orders.callback_at', '<=', $now),
            'confirmation.scheduled' => $query->whereIn('orders.order_status_id', self::CALLBACK_STATUSES)
                ->where('orders.callback_at', '>', $now),
            'confirmation.no_answer' => $query->where('orders.order_status_id', OrderStatus::PENDING)
                ->whereNull('orders.callback_at')
                ->whereHas('calls'),
            'confirmation.out_of_stock' => $query->where('orders.order_status_id', OrderStatus::OUT_OF_STOCK)
                ->whereNull('orders.callback_at'),
            'confirmation.abandoned_recent' => $query->where('orders.order_status_id', OrderStatus::ABANDONED)
                ->where('orders.created_at', '>=', $now->copy()->subDays(self::ABANDONED_RECENT_DAYS)),

            'tracking.to_ship' => $query->whereIn('orders.order_status_id', [OrderStatus::CONFIRMED, OrderStatus::IN_PREPARATION]),
            'tracking.in_delivery' => $query->where('orders.order_status_id', OrderStatus::IN_DELIVERY),
            'tracking.in_trouble' => $query->where('orders.order_status_id', OrderStatus::IN_TROUBLE),
            'tracking.stuck' => $query->whereIn('orders.order_status_id', [OrderStatus::IN_DELIVERY, OrderStatus::IN_TROUBLE])
                ->whereNotNull('orders.pickup_id'),
            'tracking.no_tracking' => $query->where('orders.order_status_id', OrderStatus::IN_DELIVERY)
                ->where(fn ($q) => $q->whereNull('orders.shipping_code')->orWhere('orders.shipping_code', '')),

            'recovery.unpaid' => $query->where('orders.order_status_id', OrderStatus::DELIVERED)
                ->whereNull('orders.shipment_id')
                ->whereNotNull('orders.pickup_id'),
            'recovery.returns_pending' => $query->where('orders.order_status_id', OrderStatus::RETURNED)
                ->whereNull('orders.shipment_id')
                ->whereNotNull('orders.pickup_id'),
            'recovery.paid' => $query->where('orders.order_status_id', OrderStatus::PAID),
        };

        $minAge = $options['min_age_days'] ?? null;
        if ($key === 'tracking.stuck' && $minAge === null) {
            $minAge = OperationsOverviewService::DEFAULT_STUCK_DAYS;
        }
        if ($minAge !== null) {
            $query->whereRaw(OrderAge::daysSql() . ' >= ?', [(int) $minAge]);
        }
        if (($options['max_age_days'] ?? null) !== null) {
            $query->whereRaw(OrderAge::daysSql() . ' <= ?', [(int) $options['max_age_days']]);
        }

        return $query;
    }
}
