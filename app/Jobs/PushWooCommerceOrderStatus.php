<?php

namespace App\Jobs;

use App\Models\WooCommerce\OrderLink;
use App\Models\WooCommerce\Store;
use App\Services\WooCommerce\StatusPusher;
use App\Services\WooCommerce\SyncAlerts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pushes the current status of a CodConnect order to its WooCommerce order. It runs on the "woocommerce" queue
 * (worked by the scheduler, like the Afra jobs) so a slow store never slows the screen that changed the status,
 * and it reads the status when it runs, not when it was queued: several quick changes end in one correct push.
 */
class PushWooCommerceOrderStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $orderLinkId)
    {
        $this->onConnection('database');
        $this->onQueue('woocommerce');
    }

    /** Seconds to wait before the 2nd and the 3rd attempt. */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(StatusPusher $pusher): void
    {
        $link = OrderLink::withoutGlobalScopes()->find($this->orderLinkId);

        if ($link) {
            $pusher->push($link, null, quiet: true);
        }
    }

    /** Every attempt failed: the bell says which order WooCommerce still shows with its old status. */
    public function failed(\Throwable $e): void
    {
        $link = OrderLink::withoutGlobalScopes()->find($this->orderLinkId);
        $store = $link ? Store::withoutGlobalScopes()->find($link->store_id) : null;

        if ($store) {
            app(SyncAlerts::class)->pushFailed($store, (int) $link->wc_order_id, $e->getMessage());
        }
    }
}
