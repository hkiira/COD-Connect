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

class SyncAsapReturnsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 3600; // 1 hour

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
            set_time_limit(3600);
            if ($this->user) {
                Auth::guard('web')->login($this->user);
            }

            Log::info("ASAP Sync Returns Job: Starting returns synchronization in the background.");
            
            $controller = new AsapDeliveryController();
            $result = $controller->runSyncReturns();
            
            Log::info("ASAP Sync Returns Job: Finished. Result: " . json_encode($result));
        } catch (\Throwable $e) {
            Log::error("ASAP Sync Returns Job Exception during execution: " . $e->getMessage(), [
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
        Log::error("ASAP Sync Returns Job Failed: " . $exception->getMessage(), [
            'exception' => $exception,
            'user_id' => $this->user ? $this->user->id : null,
        ]);
    }
}
