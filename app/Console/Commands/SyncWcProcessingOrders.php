<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\WoocommerceController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class SyncWcProcessingOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wc:sync-processing-orders {--status=processing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronisation des commandes WooCommerce en cours de traitement (processing)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $status = $this->option('status') ?? 'processing';
        $msg = "Starting WooCommerce orders sync for status: {$status}...";
        $this->info($msg);
        Log::info('[SyncWcProcessingOrders] ' . $msg);

        try {
            // Authenticate user context for background CLI execution
            $user = User::where('email', 'abder.elachqar@gmail.com')->first() ?? User::first();
            if ($user) {
                Auth::login($user);
                $authMsg = 'Authenticated context user: ' . $user->email;
                $this->info($authMsg);
                Log::info('[SyncWcProcessingOrders] ' . $authMsg);
            } else {
                $errMsg = 'No system user found to authenticate command execution.';
                $this->error($errMsg);
                Log::error('[SyncWcProcessingOrders] ' . $errMsg);
                return Command::FAILURE;
            }

            $controller = new WoocommerceController();
            $response = $controller->rest(new Request(), 'orders', $status);

            if ($response instanceof \Illuminate\Http\JsonResponse) {
                $data = $response->getData(true);
                $resMsg = 'Sync finished with response: ' . json_encode($data);
                $this->info($resMsg);
                Log::info('[SyncWcProcessingOrders] ' . $resMsg);
            } else {
                $resMsg = 'Sync finished.';
                $this->info($resMsg);
                Log::info('[SyncWcProcessingOrders] ' . $resMsg);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $errMsg = 'Error during WooCommerce orders sync: ' . $e->getMessage();
            $this->error($errMsg);
            Log::error('[SyncWcProcessingOrders] ' . $errMsg, [
                'exception' => $e
            ]);
            return Command::FAILURE;
        }
    }
}
