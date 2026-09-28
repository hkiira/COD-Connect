<?php

namespace App\Jobs;

use App\Models\AfraSyncRun;
use App\Services\AfraShippingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncAfraShippingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(public int $runId)
    {
        $this->onConnection('database');
        $this->onQueue('afra');
    }

    public function handle(AfraShippingService $service): void
    {
        $run = AfraSyncRun::findOrFail($this->runId);
        $run->update(['status' => 'running']);
        try {
            $run->kind === 'pickup' ? $service->syncPickup($run) : $service->syncStatuses($run);
            $run->update(['status' => 'completed']);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }
    }
}
