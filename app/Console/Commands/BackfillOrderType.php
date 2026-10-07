<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Return orders created before `type` became fillable were stored as 'sale'.
 * Return orders are recognisable by their code (suffix "-RT" on a child order, or the legacy
 * "RTRN" prefix). Dry run by default; --apply writes.
 */
class BackfillOrderType extends Command
{
    protected $signature = 'orders:backfill-type {--apply : Write the changes (default is a dry run)}';

    protected $description = "Mark existing return orders (code suffix -RT / prefix RTRN) with type='return'";

    public function handle(): int
    {
        $query = DB::table('orders')
            ->where('type', 'sale')
            ->where(function ($q) {
                $q->where(fn ($r) => $r->where('code', 'like', '%-RT')->whereNotNull('order_id'))
                    ->orWhere('code', 'like', 'RTRN%');
            });

        $rows = (clone $query)->orderBy('id')->get(['id', 'code', 'account_id', 'order_id']);

        $this->table(['id', 'code', 'account', 'parent order'], $rows->map(fn ($r) => [$r->id, $r->code, $r->account_id, $r->order_id])->all());
        $this->info($rows->count() . " order(s) would be marked as 'return'.");

        if (! $this->option('apply')) {
            $this->comment('Dry run. Re-run with --apply to write the changes.');

            return self::SUCCESS;
        }

        $updated = $query->update(['type' => 'return']);
        $this->info("Updated {$updated} order(s).");

        return self::SUCCESS;
    }
}
