<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Phoneable;
use App\Models\Addressable;
use App\Models\Imageable;
use App\Models\Compensationable;
use App\Models\Offerable;

class MergeDuplicateCustomers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customers:merge-duplicates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Merge duplicate customers based on their phone numbers and reassign related data.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting the process to merge duplicate customers...');

        // 1. Find all phone IDs that are linked to more than one customer
        $duplicatePhoneIds = Phoneable::where('phoneable_type', Customer::class)
            ->select('phone_id')
            ->groupBy('phone_id')
            ->havingRaw('COUNT(phoneable_id) > 1')
            ->pluck('phone_id');

        if ($duplicatePhoneIds->isEmpty()) {
            $this->info('No duplicate customers found based on phone numbers.');
            return Command::SUCCESS;
        }

        $this->info("Found " . $duplicatePhoneIds->count() . " phone numbers linked to multiple customers.");

        $mergedCount = 0;

        foreach ($duplicatePhoneIds as $phoneId) {
            DB::transaction(function () use ($phoneId, &$mergedCount) {
                // 2. Get all customer IDs linked to this phone number
                $customerIds = Phoneable::where('phoneable_type', Customer::class)
                    ->where('phone_id', $phoneId)
                    ->orderBy('phoneable_id', 'asc') // Order by oldest customer ID first
                    ->pluck('phoneable_id');

                // The first one is our primary customer to keep
                $primaryCustomerId = $customerIds->shift();

                foreach ($customerIds as $duplicateId) {
                    // 3. Reassign Orders
                    Order::where('customer_id', $duplicateId)
                        ->update(['customer_id' => $primaryCustomerId]);

                    // 4. Reassign Polymorphic Relationships
                    // Addresses
                    Addressable::where('addressable_type', Customer::class)
                        ->where('addressable_id', $duplicateId)
                        ->update(['addressable_id' => $primaryCustomerId]);

                    // Images
                    Imageable::where('imageable_type', Customer::class)
                        ->where('imageable_id', $duplicateId)
                        ->update(['imageable_id' => $primaryCustomerId]);

                    // Compensations
                    Compensationable::where('compensationable_type', Customer::class)
                        ->where('compensationable_id', $duplicateId)
                        ->update(['compensationable_id' => $primaryCustomerId]);

                    // Offers
                    Offerable::where('offerable_type', Customer::class)
                        ->where('offerable_id', $duplicateId)
                        ->update(['offerable_id' => $primaryCustomerId]);

                    // Phones (Remove the duplicate link to avoid duplicate phone entries for the primary customer)
                    Phoneable::where('phoneable_type', Customer::class)
                        ->where('phoneable_id', $duplicateId)
                        ->delete();

                    // 5. Delete the duplicate customer
                    Customer::where('id', $duplicateId)->delete();

                    $mergedCount++;
                }

                $this->info("Merged duplicates for Phone ID: {$phoneId} into Customer ID: {$primaryCustomerId}");
            });
        }

        $this->info("Merge complete! Successfully merged {$mergedCount} duplicate customer records.");

        return Command::SUCCESS;
    }
}
