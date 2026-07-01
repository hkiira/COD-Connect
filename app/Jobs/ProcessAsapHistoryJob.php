<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Traits\ScrapesAsapHistory;
use App\Models\AsapColisMeta;

class ProcessAsapHistoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ScrapesAsapHistory;

    public $colisId;
    public $sessionId;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($colisId, $sessionId = null)
    {
        $this->colisId = $colisId;
        $this->sessionId = $sessionId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $log = \Illuminate\Support\Facades\Log::build([
            'driver' => 'single',
            'path' => storage_path('logs/asap_scrape.log'),
        ]);

        try {
            $sessionId = $this->sessionId ?: $this->login();

            $res = $this->processColisHistory($this->colisId, $sessionId, $log);

            if ($res['status'] === 'error') {
                throw new \Exception($res['message'] ?? 'Unknown scraping error');
            }

        } catch (\Exception $e) {
            $log->error("Colis ID {$this->colisId}: Exception Caught! " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            throw $e;
        }
    }
}
