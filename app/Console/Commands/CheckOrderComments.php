<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use Carbon\Carbon;

class CheckOrderComments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:check-comments';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check orders for missing comments and create automatic comments';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (!function_exists('checkAndCreateAutomaticComment')) {
            require_once app_path('Helpers/OrderScoreHelper.php');
        }

        // Get all active orders (you may want to filter by status)
        // $activeOrders = Order::whereIn('order_status_id', [1, 2, 3, 4, 5])->get();
        $activeOrders = Order::whereIn('order_status_id', [1])->get();

        $this->info("Checking " . $activeOrders->count() . " active orders for missing comments...");

        foreach ($activeOrders as $order) {
            checkAndCreateAutomaticComment($order->id);
        }

        $this->info("Automatic comment check completed!");
        return 0;
    }
}
