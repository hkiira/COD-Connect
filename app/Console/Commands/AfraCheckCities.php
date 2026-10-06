<?php

namespace App\Console\Commands;

use App\Services\AfraCityWatcher;
use Illuminate\Console\Command;

/** Compares Afra's city list with the last copy and records / announces the changes. */
class AfraCheckCities extends Command
{
    protected $signature = 'afra:check-cities';

    protected $description = 'Detect new, renamed, re-priced and removed Afra cities';

    public function handle(AfraCityWatcher $watcher): int
    {
        try {
            $counts = $watcher->check();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($counts['first_run']
            ? 'First copy of the Afra city list saved.'
            : sprintf('added %d (linked %d), renamed %d, price %d, removed %d',
                $counts['added'], $counts['linked'], $counts['renamed'], $counts['price'], $counts['removed']));

        return self::SUCCESS;
    }
}
