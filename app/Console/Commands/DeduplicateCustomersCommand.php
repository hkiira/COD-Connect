<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\CustomerIdentityService;
use Illuminate\Console\Command;

class DeduplicateCustomersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customers:deduplicate {--account_id= : Specific account ID to deduplicate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Normalize phone numbers and merge duplicate customer records sharing the same phone number.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $accountIdOption = $this->option('account_id');
        $accountIds = $accountIdOption
            ? [(int) $accountIdOption]
            : Account::pluck('id')->map(fn ($id) => (int) $id)->all();

        $totalNormalized = 0;
        $totalMerged = 0;
        $totalGroups = 0;

        foreach ($accountIds as $accountId) {
            $stats = CustomerIdentityService::deduplicateAccount($accountId);
            $totalNormalized += $stats['normalized_phones'];
            $totalMerged += $stats['merged_customers'];
            $totalGroups += $stats['groups_resolved'];

            $this->info("Account #{$accountId}: Normalized {$stats['normalized_phones']} phone(s), resolved {$stats['groups_resolved']} duplicate group(s), merged {$stats['merged_customers']} duplicate customer(s).");
        }

        $this->info("Done! Total normalized phones: {$totalNormalized}, total merged customers: {$totalMerged}.");

        return self::SUCCESS;
    }
}
