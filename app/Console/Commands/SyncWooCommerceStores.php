<?php

namespace App\Console\Commands;

use App\Models\AccountUser;
use App\Models\WooCommerce\OrderLink;
use App\Models\WooCommerce\Store;
use App\Models\WooCommerce\SyncLog;
use App\Services\WooCommerce\OrderService;
use App\Support\AccountContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Imports the new orders of every store that has "auto-import" on: the orders in its import statuses whose
 * items are all matched and that are not imported yet. Replaces the old wc:sync-processing-orders, which was
 * tied to one store, one user and one warehouse.
 */
class SyncWooCommerceStores extends Command
{
    protected $signature = 'wc:sync-stores {--store= : only this store id} {--dry-run : list what would be imported}';

    protected $description = 'Import the new WooCommerce orders of the stores that have auto-import on';

    private const PAGES_PER_STATUS = 3;
    private const PER_PAGE = 50;
    private const BATCH = 20;

    public function handle(): int
    {
        $stores = Store::withoutGlobalScopes()
            ->where('is_active', true)->where('auto_import', true)
            ->when($this->option('store'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        foreach ($stores as $store) {
            try {
                $this->syncStore($store);
            } catch (\Throwable $e) {
                $store->update(['last_checked_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
                $this->error("Store #{$store->id} {$store->name}: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function syncStore(Store $store): void
    {
        $actor = $this->actorFor((int) $store->account_id);
        if (! $actor) {
            throw new \RuntimeException('No user can act for this account.');
        }

        Auth::setUser($actor);

        $imported = AccountContext::run((int) $store->account_id, function () use ($store) {
            $service = new OrderService($store);
            $ready = [];

            foreach ($store->import_statuses ?: ['processing'] as $status) {
                for ($page = 1; $page <= self::PAGES_PER_STATUS; $page++) {
                    $result = $service->list($status, self::PER_PAGE, $page);

                    foreach ($result['data'] as $order) {
                        if (! $order['already_imported'] && $order['unmatched_items'] === 0 && count($order['line_items']) > 0) {
                            $ready[$order['wc_order_id']] = ['wc_order_id' => $order['wc_order_id']];
                        }
                    }

                    if ($page * self::PER_PAGE >= $result['total']) {
                        break;
                    }
                }
            }

            if ($this->option('dry-run')) {
                $this->line("Store #{$store->id}: would import " . count($ready) . ' order(s).');

                return 0;
            }

            $count = 0;
            foreach (array_chunk(array_values($ready), self::BATCH) as $batch) {
                $count += collect($service->import($batch))->where('success', true)->count();
            }

            return $count;
        });

        $store->update(['last_checked_at' => now(), 'last_error' => null]);
        $this->info("Store #{$store->id} {$store->name}: {$imported} order(s) imported.");
    }

    /** A user whose own account (the first one, which is what getAccountUser() returns) is the store's account. */
    private function actorFor(int $accountId)
    {
        return AccountUser::where('account_id', $accountId)->with('user.accountUsers')->get()
            ->map->user
            ->filter()
            ->first(fn ($user) => (int) $user->accountUsers->sortBy('id')->first()?->account_id === $accountId);
    }
}
