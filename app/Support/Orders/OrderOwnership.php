<?php

namespace App\Support\Orders;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Ownership checks for the ids an order payload refers to. Each returns true when the record
 * belongs to the current account. Without an authenticated user (console, jobs, webhooks) there
 * is no account to compare with, so the check passes and the caller's own rules apply.
 */
class OrderOwnership
{
    private static function accountId(): ?int
    {
        $accountUser = function_exists('getAccountUser') ? getAccountUser() : null;

        return $accountUser ? (int) $accountUser->account_id : null;
    }

    private static function accountUserIds(int $accountId)
    {
        return fn ($q) => $q->select('id')->from('account_user')->where('account_id', $accountId);
    }

    /** Order (the BelongsToAccount scope already limits the lookup to the current account). */
    public static function ownsOrder(int|string|null $orderId): bool
    {
        return $orderId && is_numeric($orderId) && Order::whereKey($orderId)->exists();
    }

    public static function ownsCustomer(int|string|null $customerId): bool
    {
        return $customerId && is_numeric($customerId) && Customer::whereKey($customerId)->exists();
    }

    /** An order line (order_pva row) of an order of the current account. */
    public static function ownsOrderLine(int|string|null $lineId): bool
    {
        if (! $lineId || ! is_numeric($lineId)) {
            return false;
        }

        $orderId = DB::table('order_pva')->where('id', $lineId)->value('order_id');

        return $orderId && self::ownsOrder($orderId);
    }

    /** A product variation (product_variation_attribute row) whose product belongs to the current account. */
    public static function ownsProductVariation(int|string|null $pvaId): bool
    {
        if (! $pvaId || ! is_numeric($pvaId)) {
            return false;
        }

        $productId = DB::table('product_variation_attribute')->where('id', $pvaId)->value('product_id');

        return $productId && \App\Support\ProductOwnership::owns($productId);
    }

    /** An order line must belong to the order named next to it in the same payload entry. */
    public static function lineBelongsToOrder(int|string|null $lineId, int|string|null $orderId): bool
    {
        if (! $lineId || ! $orderId || ! is_numeric($lineId) || ! is_numeric($orderId)) {
            return false;
        }

        return DB::table('order_pva')->where('id', $lineId)->where('order_id', $orderId)->exists()
            && self::ownsOrder($orderId);
    }

    public static function ownsPickup(int|string|null $pickupId): bool
    {
        $accountId = self::accountId();
        if ($accountId === null) {
            return true;
        }

        return $pickupId && is_numeric($pickupId) && DB::table('pickups')
            ->where('id', $pickupId)->whereNull('deleted_at')
            ->where(fn ($q) => $q
                ->whereIn('account_user_id', self::accountUserIds($accountId))
                ->orWhereIn('warehouse_id', fn ($w) => $w->select('id')->from('warehouses')->where('account_id', $accountId)))
            ->exists();
    }

    public static function ownsShipment(int|string|null $shipmentId): bool
    {
        $accountId = self::accountId();
        if ($accountId === null) {
            return true;
        }

        return $shipmentId && is_numeric($shipmentId) && DB::table('shipments')
            ->where('id', $shipmentId)->whereNull('deleted_at')
            ->where(fn ($q) => $q
                ->whereIn('account_user_id', self::accountUserIds($accountId))
                ->orWhereIn('warehouse_id', fn ($w) => $w->select('id')->from('warehouses')->where('account_id', $accountId)))
            ->exists();
    }

    public static function ownsBrandSource(int|string|null $brandSourceId): bool
    {
        $accountId = self::accountId();
        if ($accountId === null) {
            return true;
        }

        return $brandSourceId && is_numeric($brandSourceId)
            && DB::table('brand_source')->where('id', $brandSourceId)->where('account_id', $accountId)->exists();
    }
}
