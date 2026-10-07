<?php

use App\Support\Orders\OrderStatus as S;

return [
    /*
    | 'log'     : a status change outside the map is written to the order_transitions log and still applied.
    | 'enforce' : it is refused with a 422.
    | Keep 'log' for ~2 weeks, read storage/logs/order_transitions-*.log, complete the map, then switch.
    */
    'enforce_transitions' => env('ORDER_TRANSITIONS', 'log'),

    // A second order for the same phone, products and quantities inside this window is refused.
    'duplicate_window_minutes' => env('ORDER_DUPLICATE_WINDOW_MINUTES', 5),

    /*
    | Allowed changes: from => [to, ...]. Built from the transitions observed in order_comment in 2026
    | (every edge seen at least ~20 times). Staying on the same status is always allowed.
    */
    'transitions' => [
        S::PENDING => [S::ABANDONED, S::OUT_OF_STOCK, S::CONFIRMED, S::IN_DELIVERY, S::PAID],
        S::ABANDONED => [S::PENDING, S::CONFIRMED, S::IN_DELIVERY],
        S::OUT_OF_STOCK => [S::ABANDONED],
        S::CONFIRMED => [S::PENDING, S::ABANDONED, S::OUT_OF_STOCK, S::IN_PREPARATION, S::IN_DELIVERY, S::DELIVERED, S::PAID, S::RETURNED],
        S::IN_PREPARATION => [S::ABANDONED, S::IN_DELIVERY, S::PAID, S::RETURNED],
        S::IN_DELIVERY => [S::PENDING, S::ABANDONED, S::OUT_OF_STOCK, S::CONFIRMED, S::DELIVERED, S::IN_TROUBLE, S::PAID, S::RETURNED],
        S::DELIVERED => [S::IN_DELIVERY, S::PAID, S::RETURNED],
        S::CANCELLED => [],
        S::IN_TROUBLE => [S::IN_DELIVERY, S::PAID, S::RETURNED],
        S::PAID => [S::PENDING, S::IN_DELIVERY, S::RETURNED],
        S::RETURNED => [S::IN_DELIVERY],
    ],
];
