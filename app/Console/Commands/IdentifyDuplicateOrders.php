<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use Carbon\Carbon;

class IdentifyDuplicateOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:identify-duplicates {--minutes=5 : The time window in minutes to consider orders as duplicates} {--remove : Automatically remove the identified duplicate orders if their status is 1, 2, 3, or 4}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Identify and optionally remove duplicate orders based on phone number and products within a specific time window';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $minutes = $this->option('minutes');
        $remove = $this->option('remove');
        
        $this->info("Searching for duplicate orders created within {$minutes} minutes of each other...");
        if ($remove) {
            $this->warn("WARNING: The --remove flag is set. Duplicate orders with status 1, 2, 3, or 4 will be DELETED.");
            if (!$this->option('no-interaction') && !$this->confirm('Do you wish to continue?')) {
                return 0;
            }
        }

        try {
            // Get all orders with their phones and products, ordered by creation date
            // Chunking to avoid memory exhaustion
            $orders = collect();
            Order::with(['phones', 'orderPvas'])->orderBy('created_at', 'desc')->take(5000)->get()->each(function ($order) use (&$orders) {
                $orders->push($order);
            });
            $orders = $orders->sortBy('created_at')->values();
        } catch (\Exception $e) {
            $this->error("Error fetching orders: " . $e->getMessage());
            return 1;
        }
        
        $duplicates = [];
        $processedIds = [];

        foreach ($orders as $order) {
            if (in_array($order->id, $processedIds)) {
                continue;
            }

            $phoneTitles = $order->phones->pluck('title')->toArray();
            if (empty($phoneTitles)) {
                continue;
            }

            $productIds = $order->orderPvas->pluck('product_variation_attribute_id')->sort()->values()->toArray();
            
            // Find potential duplicates for this order
            $timeWindowEnd = Carbon::parse($order->created_at)->addMinutes($minutes);
            
            $potentialDuplicates = $orders->where('id', '!=', $order->id)
                ->where('created_at', '>=', $order->created_at)
                ->where('created_at', '<=', $timeWindowEnd);

            $currentDuplicates = [];

            foreach ($potentialDuplicates as $potentialDuplicate) {
                if (in_array($potentialDuplicate->id, $processedIds)) {
                    continue;
                }

                $potentialPhoneTitles = $potentialDuplicate->phones->pluck('title')->toArray();
                $potentialProductIds = $potentialDuplicate->orderPvas->pluck('product_variation_attribute_id')->sort()->values()->toArray();

                // Check if they share at least one phone number and have the exact same products
                if (!empty(array_intersect($phoneTitles, $potentialPhoneTitles)) && $productIds === $potentialProductIds) {
                    $currentDuplicates[] = $potentialDuplicate;
                    $processedIds[] = $potentialDuplicate->id;
                }
            }

            if (!empty($currentDuplicates)) {
                $processedIds[] = $order->id;
                $duplicates[] = [
                    'original' => $order,
                    'duplicates' => $currentDuplicates
                ];
            }
        }

        if (empty($duplicates)) {
            $this->info("No duplicate orders found.");
            return 0;
        }

        $this->warn("Found " . count($duplicates) . " sets of duplicate orders:");

        $removedCount = 0;

        foreach ($duplicates as $index => $set) {
            $this->line("--- Set " . ($index + 1) . " ---");
            $this->info("Original Order ID: {$set['original']->id} | Code: {$set['original']->code} | Created: {$set['original']->created_at} | Status: {$set['original']->order_status_id}");
            
            foreach ($set['duplicates'] as $dup) {
                $statusMsg = "Status: {$dup->order_status_id}";
                
                if ($remove && in_array($dup->order_status_id, [1, 2, 3, 4])) {
                    $dup->delete();
                    $statusMsg .= " -> [DELETED]";
                    $removedCount++;
                } elseif ($remove) {
                    $statusMsg .= " -> [SKIPPED: Status not 1, 2, 3, or 4]";
                }

                $this->error("  Duplicate Order ID: {$dup->id} | Code: {$dup->code} | Created: {$dup->created_at} | {$statusMsg}");
            }
        }

        if ($remove) {
            $this->info("Successfully removed {$removedCount} duplicate orders.");
        }

        return 0;
    }
}
