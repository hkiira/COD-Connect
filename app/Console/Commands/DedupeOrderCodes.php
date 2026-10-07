<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Orders of the same account that share a code. Before the counter became atomic, concurrent
 * requests handed out the same code. The oldest order keeps the code; later ones get a "-D2",
 * "-D3"... suffix so every code is unique again.
 *
 * Orders without a code and soft-deleted orders are ignored. Dry run by default; --apply writes.
 * --since limits the cleanup to orders created on or after a date (older history is left alone).
 */
class DedupeOrderCodes extends Command
{
    protected $signature = 'orders:dedupe-codes
        {--since=2025-01-01 : only orders created on or after this date are renamed}
        {--apply : Write the changes (default is a dry run)}';

    protected $description = 'Give duplicated order codes of the same account a unique suffix';

    public function handle(): int
    {
        $since = (string) $this->option('since');

        $groups = DB::table('orders')
            ->whereNull('deleted_at')->whereNotNull('code')->where('code', '!=', '')
            ->groupBy('account_id', 'code')->havingRaw('COUNT(*) > 1')
            ->get(['account_id', 'code']);

        $renames = [];
        foreach ($groups as $group) {
            $orders = DB::table('orders')
                ->where('account_id', $group->account_id)->where('code', $group->code)->whereNull('deleted_at')
                ->orderBy('id')->get(['id', 'created_at']);

            // the oldest keeps the code; only later ones created since the cut-off are renamed
            foreach ($orders->slice(1)->values() as $index => $order) {
                if ($order->created_at >= $since) {
                    $renames[] = ['id' => $order->id, 'account' => $group->account_id, 'old' => $group->code, 'new' => $group->code . '-D' . ($index + 2)];
                }
            }
        }

        $this->info(count($groups) . ' duplicated code group(s) in total; ' . count($renames) . " order(s) created since {$since} would be renamed.");
        $this->table(['id', 'account', 'old', 'new'], array_map(fn ($r) => [$r['id'], $r['account'], $r['old'], $r['new']], array_slice($renames, 0, 15)));

        if (! $this->option('apply')) {
            $this->comment('Dry run. Re-run with --apply to write the changes.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($renames) {
            foreach ($renames as $r) {
                DB::table('orders')->where('id', $r['id'])->update(['code' => $r['new'], 'updated_at' => now()]);
            }
        });

        $this->info('Renamed ' . count($renames) . ' order(s).');

        return self::SUCCESS;
    }
}
