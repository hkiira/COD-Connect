<?php

namespace App\Support;

/**
 * English labels for the reference data shown on the dashboards (overviews).
 *
 * The database stores these titles in French; the dashboards are English for now, so they are
 * translated here by id without touching the data. Unknown ids keep their stored title.
 * Full localization (en/fr/ar) will replace this later.
 */
class OverviewLabels
{
    private const ORDER_STATUSES = [
        1  => 'Pending',
        2  => 'Abandoned',
        3  => 'Out of stock',
        4  => 'Confirmed',
        5  => 'In preparation',
        6  => 'In delivery',
        7  => 'Delivered',
        8  => 'Cancelled',
        9  => 'In trouble',
        10 => 'Paid',
        11 => 'Returned',
    ];

    private const TRANSACTION_TYPES = [
        1 => 'Top-up',
        2 => 'Withdrawal',
        3 => 'Pending payment',
    ];

    private const EXPENSE_TYPES = [
        1 => 'Advertising',
        2 => 'Accommodation',
        3 => 'Subscription',
        4 => 'Transport',
        5 => 'Miscellaneous',
        6 => 'Housing',
    ];

    private const PRODUCT_TYPES = [
        1 => 'Product',
        2 => 'Virtual pack',
        3 => 'Locked pack',
        4 => 'Centralized pack',
        5 => 'Simple sneakers',
    ];

    public static function orderStatus(?int $id, ?string $fallback = null): ?string
    {
        return self::ORDER_STATUSES[$id] ?? $fallback;
    }

    public static function transactionType(?int $id, ?string $fallback = null): ?string
    {
        return self::TRANSACTION_TYPES[$id] ?? $fallback;
    }

    public static function expenseType(?int $id, ?string $fallback = null): ?string
    {
        return self::EXPENSE_TYPES[$id] ?? $fallback;
    }

    public static function productType(?int $id, ?string $fallback = null): ?string
    {
        return self::PRODUCT_TYPES[$id] ?? $fallback;
    }
}
