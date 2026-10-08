<?php

namespace App\Services\WooCommerce;

use App\Models\AccountUser;
use App\Models\WooCommerce\Store;
use App\Notifications\AppAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Bell notifications for WooCommerce sync problems, sent to the active users of the store's account.
 * The sync runs every five minutes, so each problem is announced once: a store when it stops answering
 * (and again when it is back), an order the first time it fails or waits for unlinked products.
 */
class SyncAlerts
{
    /** An order that keeps failing is announced again after this many days. */
    private const REMIND_AFTER_DAYS = 7;

    private const CATEGORY = 'WooCommerce';

    /** The store could not be read. Announced only when it was fine before, not at every failed run. */
    public function storeFailed(Store $store, string $error, ?string $previousError): void
    {
        if ($previousError !== null) {
            return;
        }

        $this->send(
            $store,
            "WooCommerce sync stopped: {$store->name}",
            'New orders are not coming in from this store. ' . mb_substr($error, 0, 200),
            "/dashboard/woocommerce/stores?store={$store->id}"
        );
    }

    /** The store answers again after a failure. */
    public function storeRecovered(Store $store): void
    {
        $this->send(
            $store,
            "WooCommerce sync is back: {$store->name}",
            'The store answers again; its new orders are imported.',
            "/dashboard/woocommerce/activity?store={$store->id}&direction=import"
        );
    }

    /**
     * Orders the import refused (not the ones skipped on purpose).
     *
     * @param array<int, array<string, mixed>> $results what OrderService::import returned
     */
    public function importFailed(Store $store, array $results): void
    {
        $failed = collect($results)
            ->filter(fn ($result) => ! $result['success'] && ! ($result['skipped'] ?? false))
            ->filter(fn ($result) => $this->firstTime($store, 'failed', (int) $result['wc_order_id']));

        if ($failed->isEmpty()) {
            return;
        }

        $numbers = $failed->pluck('wc_order_id')->map(fn ($id) => "#{$id}");
        $this->send(
            $store,
            $failed->count() === 1
                ? "WooCommerce order {$numbers->first()} was not imported"
                : "{$failed->count()} WooCommerce orders were not imported",
            "{$store->name}: " . $this->list($numbers) . ' — ' . mb_substr((string) $failed->first()['message'], 0, 160),
            "/dashboard/woocommerce/activity?store={$store->id}&direction=import&status=failed"
        );
    }

    /**
     * New orders that wait because some of their products are not linked to a product of the app.
     *
     * @param int[] $wcOrderIds
     */
    public function ordersWaitingForProducts(Store $store, array $wcOrderIds): void
    {
        $waiting = collect($wcOrderIds)->filter(fn ($id) => $this->firstTime($store, 'unmatched', (int) $id))->values();

        if ($waiting->isEmpty()) {
            return;
        }

        $numbers = $waiting->map(fn ($id) => "#{$id}");
        $this->send(
            $store,
            $waiting->count() === 1
                ? "WooCommerce order {$numbers->first()} is waiting"
                : "{$waiting->count()} WooCommerce orders are waiting",
            "{$store->name}: " . $this->list($numbers) . ' — some products are not linked. Link them and the orders come in at the next sync.',
            "/dashboard/woocommerce/products?store={$store->id}"
        );
    }

    /** A status change could not be sent to WooCommerce, after every retry. */
    public function pushFailed(Store $store, int $wcOrderId, string $error): void
    {
        if (! $this->firstTime($store, 'push', $wcOrderId)) {
            return;
        }

        $this->send(
            $store,
            "WooCommerce order #{$wcOrderId}: status not updated",
            "{$store->name}: " . mb_substr($error, 0, 200),
            "/dashboard/woocommerce/activity?store={$store->id}&direction=push&status=failed"
        );
    }

    /** True the first time a problem is seen for this order (then false until the reminder delay is over). */
    private function firstTime(Store $store, string $kind, int $wcOrderId): bool
    {
        return Cache::add("wc-alert:{$store->id}:{$kind}:{$wcOrderId}", true, now()->addDays(self::REMIND_AFTER_DAYS));
    }

    private function list($numbers): string
    {
        $shown = $numbers->take(5)->implode(', ');

        return $numbers->count() > 5 ? $shown . ' and ' . ($numbers->count() - 5) . ' more' : $shown;
    }

    private function send(Store $store, string $title, string $message, string $link): void
    {
        $users = AccountUser::where('account_id', $store->account_id)->where('statut', 1)->get();

        Notification::send($users, new AppAlert($title, $message, $link, self::CATEGORY, 'order'));
    }
}
