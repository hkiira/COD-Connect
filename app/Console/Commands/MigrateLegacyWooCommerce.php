<?php

namespace App\Console\Commands;

use App\Models\WooCommerce\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Moves the single store that lived in .env and the ids hidden in JSON / string columns into the multi-store
 * tables: a store row, woocommerce_variation_links (from product_variation_attribute.meta) and
 * woocommerce_order_links (from orders.meta). The old columns are left untouched. Dry run unless --apply.
 */
class MigrateLegacyWooCommerce extends Command
{
    protected $signature = 'woocommerce:migrate-legacy {--account= : account id that owns the store configured in .env} {--apply : write the changes}';

    protected $description = 'Create a store from the .env keys and copy the legacy WooCommerce ids into the link tables (dry run by default)';

    public function handle(): int
    {
        $accountId = (int) $this->option('account');
        if (! $accountId || ! DB::table('accounts')->where('id', $accountId)->exists()) {
            $this->error('Pass --account=<id> of the account that owns the store configured in .env.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $baseUrl = rtrim((string) config('services.woocommerce.base_url'), '/');
        $key = (string) config('services.woocommerce.consumer_key');
        $secret = (string) config('services.woocommerce.consumer_secret');

        if (! $baseUrl || ! $key || ! $secret) {
            $this->error('WOOCOMMERCE_BASE_URL, WOOCOMMERCE_CONSUMER_KEY and WOOCOMMERCE_CONSUMER_SECRET are not set in .env.');

            return self::FAILURE;
        }

        $store = Store::withoutGlobalScopes()->where('account_id', $accountId)->where('base_url', $baseUrl)->first();
        $this->line($store ? "Store already exists: #{$store->id} {$store->name}" : "Would create a store for {$baseUrl} on account {$accountId}.");

        if (! $store && $apply) {
            $store = Store::withoutGlobalScopes()->create([
                'account_id' => $accountId,
                'name' => parse_url($baseUrl, PHP_URL_HOST) ?: 'WooCommerce',
                'base_url' => $baseUrl,
                'consumer_key' => $key,
                'consumer_secret' => $secret,
                'verify_ssl' => (bool) config('services.woocommerce.verify_ssl', true),
                'import_statuses' => ['processing'],
            ]);
            $this->info("Created store #{$store->id}.");
        }

        if ($apply && $store) {
            $store->seedDefaultMappings();
        }

        $variations = $this->variationRows($accountId);
        $orders = $this->orderRows($accountId);
        $this->line(count($variations) . ' variation link(s) and ' . count($orders) . ' order link(s) found in the legacy columns.');

        if (! $apply) {
            $this->warn('Dry run. Re-run with --apply to write the changes.');

            return self::SUCCESS;
        }

        $now = now();
        foreach (array_chunk($variations, 500) as $chunk) {
            DB::table('woocommerce_variation_links')->insertOrIgnore(array_map(
                fn ($row) => $row + ['store_id' => $store->id, 'account_id' => $accountId, 'source' => 'legacy', 'created_at' => $now, 'updated_at' => $now],
                $chunk
            ));
        }
        foreach (array_chunk($orders, 500) as $chunk) {
            DB::table('woocommerce_order_links')->insertOrIgnore(array_map(
                fn ($row) => $row + ['store_id' => $store->id, 'account_id' => $accountId, 'created_at' => $now, 'updated_at' => $now],
                $chunk
            ));
        }

        $this->info('Done: ' . DB::table('woocommerce_variation_links')->where('store_id', $store->id)->count() . ' variation link(s), '
            . DB::table('woocommerce_order_links')->where('store_id', $store->id)->count() . ' order link(s) in the store.');

        return self::SUCCESS;
    }

    /** @return array<int, array{wc_product_id:int, wc_variation_id:int, product_variation_attribute_id:int}> */
    private function variationRows(int $accountId): array
    {
        $rows = [];

        DB::table('product_variation_attribute as pva')
            ->join('products as p', 'p.id', '=', 'pva.product_id')
            ->where('pva.account_id', $accountId)
            ->whereNotNull('pva.meta')
            ->select('pva.id', 'pva.meta', 'p.meta as product_meta')
            ->orderBy('pva.id')
            ->chunk(1000, function ($chunk) use (&$rows) {
                foreach ($chunk as $pva) {
                    $wcProductId = (int) ((json_decode((string) $pva->product_meta, true)[0]['id'] ?? 0));

                    foreach ((array) json_decode((string) $pva->meta, true) as $item) {
                        $wcId = (int) (is_array($item) ? ($item['id'] ?? 0) : $item);
                        if ($wcId > 0) {
                            $rows["{$wcProductId}:{$wcId}"] = [
                                'wc_product_id' => $wcProductId,
                                'wc_variation_id' => $wcId,
                                'product_variation_attribute_id' => (int) $pva->id,
                            ];
                        }
                    }
                }
            });

        return array_values($rows);
    }

    /** @return array<int, array{wc_order_id:int, order_id:int, imported_at:?string}> orders whose meta is a bare WooCommerce order id */
    private function orderRows(int $accountId): array
    {
        return DB::table('orders')
            ->where('account_id', $accountId)
            ->whereNotNull('meta')
            ->whereRaw("meta REGEXP '^[0-9]+$'")
            ->select('id', 'meta', 'created_at')
            ->get()
            // the order was imported when it was created, not when this command runs
            ->map(fn ($order) => ['wc_order_id' => (int) $order->meta, 'order_id' => (int) $order->id, 'imported_at' => $order->created_at])
            ->unique('wc_order_id')
            ->values()
            ->all();
    }
}
