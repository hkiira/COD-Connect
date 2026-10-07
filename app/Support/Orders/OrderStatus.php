<?php

namespace App\Support\Orders;

/**
 * order_statuses.id. Confirmed with the business; the frontend mirrors it in
 * `src/sections/order/constants.ts`.
 */
final class OrderStatus
{
    public const PENDING = 1;
    public const ABANDONED = 2;
    public const OUT_OF_STOCK = 3;
    public const CONFIRMED = 4;
    public const IN_PREPARATION = 5;
    public const IN_DELIVERY = 6;
    public const DELIVERED = 7;      // received by the customer, money not yet received
    public const CANCELLED = 8;
    public const IN_TROUBLE = 9;     // delivery problem
    public const PAID = 10;          // money received
    public const RETURNED = 11;
}
