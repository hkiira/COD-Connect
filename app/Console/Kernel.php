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

        // WooCommerce: import the new orders of every store that has auto-import on, per store and warehouse
        $schedule->command('wc:sync-stores')->everyFiveMinutes()->withoutOverlapping();

        // WooCommerce status pushes are queued by the order observer; no permanent worker, so the scheduler runs them.
        $schedule->command('queue:work database --queue=woocommerce --stop-when-empty --max-time=55 --tries=3')
            ->everyMinute()->withoutOverlapping(10)->runInBackground();

        // Afra: read the statuses of the open Afra orders of every account that has credentials.
        $schedule->call(function () {
            \App\Models\AccountCarrier::where('carrier_id', \App\Services\AfraShippingClient::carrierId())
                ->whereNotNull('username')->whereNotNull('password')
                ->each(function ($link) {
                    $running = \App\Models\AfraSyncRun::where('account_id', $link->account_id)
                        ->where('kind', 'statuses')->active()->exists();
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

        // Afra has no webhook: compare its city list with our copy once a day.
        $schedule->command('afra:check-cities')->dailyAt('06:00')->withoutOverlapping();

        // No permanent queue worker on the server: run the Afra jobs (pickup sends, status syncs)
        // from the scheduler. A run started from the app begins within the minute.
        $schedule->command('queue:work database --queue=afra --stop-when-empty --max-time=55 --tries=1')
            ->everyMinute()->withoutOverlapping(10)->runInBackground();
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
