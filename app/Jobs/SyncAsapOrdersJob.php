<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Http\Controllers\AsapDeliveryController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SyncAsapOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($user)
    {
        $this->user = $user;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            if ($this->user) {
                Auth::login($this->user);
            }

            Log::info("ASAP Sync Job: Starting order synchronization in the background.");
            
            $controller = new AsapDeliveryController();
            $result = $controller->runSync();
            
            Log::info("ASAP Sync Job: Finished. Result: " . json_encode($result));
        } catch (\Throwable $e) {
            Log::error("ASAP Sync Job Exception during execution: " . $e->getMessage(), [
                'exception' => $e,
                'user_id' => $this->user ? $this->user->id : null,
            ]);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error("ASAP Sync Job Failed: " . $exception->getMessage(), [
            'exception' => $exception,
            'user_id' => $this->user ? $this->user->id : null,
        ]);
    }
}
