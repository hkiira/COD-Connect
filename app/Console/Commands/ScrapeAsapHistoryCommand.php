<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ProcessAsapHistoryJob;
use App\Traits\ScrapesAsapHistory;

class ScrapeAsapHistoryCommand extends Command
{
    use ScrapesAsapHistory;

    protected $signature = 'asap:scrape-history {count=1000} {--start= : Optional manual start ID}';
    protected $description = 'Dispatch jobs to scrape ASAP delivery histories automatically starting from the last known ID';

    public function handle()
    {
        $count = (int) $this->argument('count');
        $manualStart = $this->option('start');
        
        if ($manualStart) {
            $startId = (int) $manualStart;
        } else {
            // Find the highest ID we have actually saved in the DB
            $lastSaved = \App\Models\AsapColisMeta::max('colis_id') ?? 0;
            // Find the highest ID we have ever dispatched to the queue (to avoid overlapping if cron runs fast)
            $lastDispatched = \Illuminate\Support\Facades\Cache::get('asap_last_dispatched_id', 0);
            
            // Start from the highest known ID + 1
            $startId = max($lastSaved, $lastDispatched) + 1;
        }

        $this->info("Starting ASAP scrape queue for {$count} records starting from ID {$startId}");

        $sessionId = $this->login();
        if (!$sessionId) {
            $this->error("Failed to obtain session ID.");
            return;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $currentId = $startId;
        for ($i = 0; $i < $count; $i++) {
            $currentId = $startId + $i;
            ProcessAsapHistoryJob::dispatch($currentId, $sessionId);
            $bar->advance();
        }

        // Update the cache with the highest ID we just queued so the next cron run knows where to start
        \Illuminate\Support\Facades\Cache::put('asap_last_dispatched_id', $currentId);

        $bar->finish();
        $this->info("\nSuccessfully dispatched {$count} jobs.");
    }
}
