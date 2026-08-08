<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\WoocommerceController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
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
        $this->info("Starting WooCommerce orders sync for status: {$status}...");

        try {
            // Authenticate user context for background CLI execution
            $user = User::where('email', 'abder.elachqar@gmail.com')->first() ?? User::first();
            if ($user) {
                Auth::login($user);
                $this->info('Authenticated context user: ' . $user->email);
            } else {
                $this->error('No system user found to authenticate command execution.');
                return Command::FAILURE;
            }

            $controller = new WoocommerceController();
            $response = $controller->rest(new Request(), 'orders', $status);

            if ($response instanceof \Illuminate\Http\JsonResponse) {
                $data = $response->getData(true);
                $this->info('Sync finished with response: ' . json_encode($data));
            } else {
                $this->info('Sync finished.');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error during WooCommerce orders sync: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
