<?php

namespace App\Support\Orders;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

/**
 * Order status changes go through here. In 'log' mode a change outside config('orders.transitions')
 * is only recorded; in 'enforce' mode it is refused.
 */
class StatusTransitions
{
    public static function isAllowed(?int $from, ?int $to): bool
    {
        if ($from === null || $to === null || $from === $to) {
            return true;
        }

        return in_array($to, config('orders.transitions.' . $from, []), true);
    }

    public static function guard($order, ?int $to, array $context = []): void
    {
        $from = $order->order_status_id !== null ? (int) $order->order_status_id : null;

        if (self::isAllowed($from, $to)) {
            return;
        }

        $enforce = config('orders.enforce_transitions') === 'enforce';

        Log::channel('order_transitions')->warning($enforce ? 'transition refused' : 'transition outside the map', [
            'order_id' => $order->id,
            'account_id' => $order->account_id,
            'from' => $from,
            'to' => $to,
        ] + $context);

        if ($enforce) {
            throw new HttpResponseException(response()->json([
                'statut' => 0,
                'data' => ['status' => ["The order cannot move from status {$from} to {$to}."]],
            ], 422));
        }
    }
}
