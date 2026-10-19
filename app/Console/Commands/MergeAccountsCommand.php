<?php

namespace App\Console\Commands;

use App\Services\AccountMergeService;
use Illuminate\Console\Command;

class MergeAccountsCommand extends Command
{
    protected $signature = 'accounts:merge
        {source : Source account ID to merge from}
        {target : Target account ID to merge into}
        {--stock-policy=sum : Stock conflict policy (sum, keep_target, take_source)}
        {--no-dedupe-customers : Skip customer deduplication}
        {--no-rename-codes : Skip renaming overlapping order codes}
        {--keep-source-active : Leave source account active (statut=1)}
        {--apply : Execute the merge (default is dry-run preview)}';

    protected $description = 'Merge all data from a source account into a target account';

    public function handle(AccountMergeService $service): int
    {
        $sourceId = (int) $this->argument('source');
        $targetId = (int) $this->argument('target');

        if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
            $this->error('Please provide valid, distinct source and target account IDs.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->info("Inspecting Account #{$sourceId} -> Account #{$targetId}...");

        $inspectData = $service->inspect($sourceId, $targetId);
        $config = $inspectData['suggested_config'];
        $config['stock_policy'] = (string) $this->option('stock-policy');
        $config['options'] = [
            'merge_duplicate_customers' => ! $this->option('no-dedupe-customers'),
            'rename_conflicting_order_codes' => ! $this->option('no-rename-codes'),
            'deactivate_source_account' => ! $this->option('keep-source-active'),
        ];

        $preview = $service->preview($sourceId, $targetId, $config);

        $this->table(['Metric', 'Count / Setting'], [
            ['Source Account', "#{$inspectData['source_account']['id']} ({$inspectData['source_account']['name']})"],
            ['Target Account', "#{$inspectData['target_account']['id']} ({$inspectData['target_account']['name']})"],
            ['Orders to Move', $preview['orders_to_move']],
            ['Customers to Move', $preview['customers_to_move']],
            ['Conflicting Order Codes', $preview['conflicting_order_codes_count']],
            ['Shared Customer Phones', $preview['shared_phone_numbers_count']],
            ['Stock Policy', $preview['stock_policy']],
            ['Products Merged', $preview['summary']['products']['merged']],
            ['Products Transferred', $preview['summary']['products']['transferred']],
            ['PVAs Merged', $preview['summary']['products']['pvas_merged']],
            ['PVAs Added as New', $preview['summary']['products']['pvas_added_as_new']],
        ]);

        if (!empty($preview['warnings'])) {
            foreach ($preview['warnings'] as $w) {
                $this->warn("! {$w}");
            }
        }

        if (!$apply) {
            $this->comment('This was a DRY RUN. Pass --apply to execute the transactional merge.');
            return self::SUCCESS;
        }

        if (!$this->confirm("Are you sure you want to permanently merge Account #{$sourceId} into Account #{$targetId}?", true)) {
            $this->comment('Operation aborted.');
            return self::SUCCESS;
        }

        $this->info('Executing merge inside database transaction...');
        $result = $service->execute($sourceId, $targetId, $config);

        foreach ($result['steps_completed'] as $step) {
            $this->line(" - {$step}");
        }

        $this->info($result['message']);
        return self::SUCCESS;
    }
}
