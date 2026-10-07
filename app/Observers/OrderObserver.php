<?php

namespace App\Observers;

use App\Jobs\PushWooCommerceOrderStatus;
use App\Models\Order;
use App\Models\WooCommerce\OrderLink;
use Illuminate\Support\Facades\Log;

/**
 * When the status of an order that came from WooCommerce changes, queue a push to the store. Observing the model
 * (rather than hooking one controller) catches every writer: the screens, the pickups, Afra and ASAP.
 */
class OrderObserver
{
    /** Only after the transaction that changed the status has committed. */
    public bool $afterCommit = true;

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('order_status_id')) {
            return;
        }

        try {
            OrderLink::withoutGlobalScopes()->where('order_id', $order->id)->pluck('id')
                ->each(fn ($linkId) => PushWooCommerceOrderStatus::dispatch($linkId));
        } catch (\Throwable $e) {
            // queuing is a courtesy to the store: it must never fail the status change itself
            Log::warning("WooCommerce push of order {$order->id} could not be queued: " . $e->getMessage());
        }
    }
}
