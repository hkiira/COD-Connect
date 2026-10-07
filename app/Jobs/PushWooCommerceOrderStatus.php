<?php

namespace App\Jobs;

use App\Models\WooCommerce\OrderLink;
use App\Services\WooCommerce\StatusPusher;
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
}
