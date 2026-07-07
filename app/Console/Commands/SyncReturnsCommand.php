<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\AsapDeliveryController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class SyncReturnsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:returns';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronisation des retours ASAP Delivery';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        try {
            // Find administrator user to authenticate background context
            $admin = User::where('email', 'achkar.abder@gmail.com')->first();
            if ($admin) {
                Auth::login($admin);
                $this->info('Authenticated as admin: ' . $admin->email);
            } else {
                $this->error('Administrator account (achkar.abder@gmail.com) not found. Cannot proceed.');
                return Command::FAILURE;
            }

            $this->info('Starting returns synchronization job dispatch...');
            
            $controller = new AsapDeliveryController();
            $response = $controller->syncReturns();
            
            if ($response instanceof \Illuminate\Http\JsonResponse) {
                $data = $response->getData(true);
                if (isset($data['success']) && $data['success']) {
                    $this->info($data['message']);
                    return Command::SUCCESS;
                } else {
                    $this->error($data['message'] ?? 'Failed to queue returns sync.');
                    return Command::FAILURE;
                }
            }

            $this->info('Returns sync job dispatched.');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error during returns sync dispatch: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
