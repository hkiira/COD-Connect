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
            // If a valid session ID is not passed, get a new one
            $sessionId = $this->sessionId;
            if (!$sessionId) {
                $sessionId = $this->login();
            }

            if (!$sessionId) {
                $log->error("Colis ID {$this->colisId}: Failed to obtain a valid PHPSESSID via login.");
                return;
            }

            $data = $this->scrapeColisHistoryData($this->colisId, $sessionId);

            if (isset($data['error'])) {
                $log->error("Colis ID {$this->colisId}: " . $data['error']);
                return;
            }

            if (empty($data['meta'])) {
                $log->warning("Colis ID {$this->colisId}: Empty meta array entirely, ignoring.");
                return;
            }

            if (empty($data['meta']['Code']) && empty($data['meta']['Destinataire'])) {
                \App\Models\AsapColisEmptyRecord::updateOrCreate(['colis_id' => $this->colisId]);
                $log->info("Colis ID {$this->colisId}: Logged empty Code and Destinataire in asap_colis_empty_records.");
                return;
                // Optionally return here to skip saving them into the main tables
            }

            // Save Meta
            $meta = AsapColisMeta::updateOrCreate(
                ['colis_id' => $this->colisId],
                [
                    'code' => $data['meta']['Code'] ?? null,
                    'destinataire' => $data['meta']['Destinataire'] ?? null,
                    'telephone' => $data['meta']['Téléphone'] ?? null,
                    'ville' => $data['meta']['Ville'] ?? null,
                    'adresse' => $data['meta']['Adresse'] ?? null,
                ]
            );

            // Save State History
            if (isset($data['state_history']) && is_array($data['state_history'])) {
                foreach ($data['state_history'] as $sh) {
                    $meta->histories()->firstOrCreate([
                        'date' => $sh['date'] ?? null,
                        'etat' => $sh['etat'] ?? null,
                        'date_reporte' => $sh['date_reporte'] ?? null,
                        'description' => $sh['description'] ?? null,
                        'utilisateur' => $sh['utilisateur'] ?? null,
                    ]);
                }
            }

            // Save Address History
            if (isset($data['address_history']) && is_array($data['address_history'])) {
                foreach ($data['address_history'] as $ah) {
                    $meta->addressHistories()->firstOrCreate([
                        'date' => $ah['date'] ?? null,
                        'client' => $ah['client'] ?? null,
                        'adresse' => $ah['adresse'] ?? null,
                        'telephone' => $ah['telephone'] ?? null,
                    ]);
                }
            }

            // Save Call History
            if (isset($data['call_history']) && is_array($data['call_history'])) {
                foreach ($data['call_history'] as $ch) {
                    $meta->callHistories()->firstOrCreate([
                        'date' => $ch['date'] ?? null,
                        'action' => $ch['action'] ?? null,
                        'utilisateur' => $ch['utilisateur'] ?? null,
                    ]);
                }
            }

            // $log->info("Colis ID {$this->colisId}: Successfully scraped and saved.");

        } catch (\Exception $e) {
            $log->error("Colis ID {$this->colisId}: Exception Caught! " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            throw $e;
        }
    }
}
