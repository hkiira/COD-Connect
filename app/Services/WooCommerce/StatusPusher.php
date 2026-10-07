<?php

namespace App\Services\WooCommerce;

use App\Models\Order;
use App\Models\WooCommerce\OrderLink;
use App\Models\WooCommerce\StatusMapping;
use App\Models\WooCommerce\Store;
use App\Models\WooCommerce\SyncLog;

/**
 * Sends the status of a CodConnect order to the WooCommerce order it was imported from, through the
 * store's mapping (CodConnect status -> WooCommerce status). Pushing is idempotent: a status the store is
 * already known to have is not sent again. Every outcome is written to the activity log.
 */
class StatusPusher
{
    public const SUCCESS = 'success';
    public const SKIPPED = 'skipped';

    /**
     * @param string|null $explicitStatus a WooCommerce status chosen by the user; null follows the mapping
     * @param bool $quiet automatic pushes do not log what they skip (most status changes have no mapping)
     * @return array{status:string, message:string, wc_status:?string}
     * @throws WooCommerceException when the store refuses or cannot be reached (already logged)
     */
    public function push(OrderLink $link, ?string $explicitStatus = null, bool $quiet = false): array
    {
        $store = Store::withoutGlobalScopes()->find($link->store_id);
        $order = Order::withoutGlobalScopes()->find($link->order_id);

        if (! $store || ! $order) {
            return $this->skipped($store, $link, 'The store or the order no longer exists.', null, $quiet);
        }
        if (! $store->is_active) {
            return $this->skipped($store, $link, 'The store is inactive.', null, $quiet);
        }

        $target = $explicitStatus ?? $this->mappedStatus($store, (int) $order->order_status_id);
        if ($target === null) {
            return $this->skipped($store, $link, 'No enabled mapping for this order status.', null, $quiet);
        }
        if ($explicitStatus === null && $link->last_wc_status === $target) {
            return $this->skipped($store, $link, "WooCommerce is already {$target}.", $target, $quiet);
        }

        try {
            WooCommerceClient::fromStore($store)->updateOrderStatus((int) $link->wc_order_id, $target);
        } catch (WooCommerceException $e) {
            SyncLog::record($store, 'push', 'order', 'failed', "Could not set {$target}: " . $e->getMessage(), [
                'entity_id' => $link->order_id,
                'wc_id' => $link->wc_order_id,
                'payload' => ['wc_status' => $target],
            ]);

            throw $e;
        }

        $link->update(['last_wc_status' => $target, 'last_pushed_status' => $target, 'last_pushed_at' => now()]);
        SyncLog::record($store, 'push', 'order', 'success', "WooCommerce set to {$target}.", [
            'entity_id' => $link->order_id,
            'wc_id' => $link->wc_order_id,
            'payload' => ['wc_status' => $target, 'order_status_id' => $order->order_status_id],
        ]);

        return ['status' => self::SUCCESS, 'message' => "WooCommerce set to {$target}.", 'wc_status' => $target];
    }

    /** The WooCommerce status of an order status, when pushing it is enabled for the store. */
    public function mappedStatus(Store $store, int $orderStatusId): ?string
    {
        $mapping = StatusMapping::where('store_id', $store->id)->where('order_status_id', $orderStatusId)->first();

        return $mapping && $mapping->is_enabled ? $mapping->wc_status : null;
    }

    private function skipped(?Store $store, OrderLink $link, string $message, ?string $wcStatus = null, bool $quiet = false): array
    {
        if ($store && ! $quiet) {
            SyncLog::record($store, 'push', 'order', 'skipped', $message, ['entity_id' => $link->order_id, 'wc_id' => $link->wc_order_id]);
        }

        return ['status' => self::SKIPPED, 'message' => $message, 'wc_status' => $wcStatus];
    }
}
