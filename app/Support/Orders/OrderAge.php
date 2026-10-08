<?php

namespace App\Support\Orders;

/**
 * How long an order has been in its current status. There is no status date on orders: the first status
 * comment with that status (order_comment, the current history) is used, else the older status history,
 * else the order's last update. Shared by the dashboards and the order workspaces so both count the same days.
 */
final class OrderAge
{
    /** SQL expression: whole days since `orders` entered its current status. */
    public static function daysSql(): string
    {
        return 'DATEDIFF(NOW(), COALESCE(
            (SELECT MIN(oc.created_at) FROM order_comment oc
                WHERE oc.order_id = orders.id AND oc.order_status_id = orders.order_status_id AND oc.deleted_at IS NULL),
            (SELECT MAX(h.created_at) FROM account_user_order_status h
                WHERE h.order_id = orders.id AND h.order_status_id = orders.order_status_id AND h.deleted_at IS NULL),
            orders.updated_at))';
    }
}
