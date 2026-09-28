<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();
        $schedule->command('passport:purge')->everyMinute();
        
        // Re-enable sync:orders with error handling
        //$schedule->command('sync:orders')->everyMinute()->withoutOverlapping();
        
        // Check for missing order comments every 4 hours
        $schedule->command('orders:check-comments')->everyFourHours();

        // Sync ASAP returns every hour
        $schedule->command('sync:returns')->hourly()->withoutOverlapping();

        // Sync WooCommerce processing orders every 5 minutes
        $schedule->command('wc:sync-processing-orders')->everyFiveMinutes()->withoutOverlapping();

        $schedule->call(function () {
            \App\Models\AccountCarrier::where('carrier_id', 26)->whereNotNull('username')->whereNotNull('password')
                ->each(function ($link) {
                    $running = \App\Models\AfraSyncRun::where('account_id', $link->account_id)
                        ->where('kind', 'statuses')->whereIn('status', ['queued', 'running'])->exists();
                    if ($running) return;
                    $actorId = \App\Models\AccountUser::where('account_id', $link->account_id)->value('id');
                    if (!$actorId) return;
                    $run = \App\Models\AfraSyncRun::create([
                        'account_id' => $link->account_id,
                        'account_user_id' => $actorId,
                        'kind' => 'statuses',
                        'status' => 'queued',
                    ]);
                    \App\Jobs\SyncAfraShippingJob::dispatch($run->id);
                });
        })->name('afra-status-sync')->everyFifteenMinutes()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
