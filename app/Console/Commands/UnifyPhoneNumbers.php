<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Phone;

class UnifyPhoneNumbers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'phones:unify';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Unify all existing phone numbers in the database to a standard format (e.g., 0644001115)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting to unify phone numbers...');

        $phones = Phone::all();
        $updatedCount = 0;
        $invalidCount = 1;

        foreach ($phones as $phone) {
            $originalTitle = $phone->title;
            $formattedTitle = formatPhoneNumber($originalTitle);

            // Check if the formatted number is valid (exactly 10 digits starting with 0)
            if (!preg_match('/^0[0-9]{9}$/', $formattedTitle)) {
                $formattedTitle = str_pad($invalidCount, 10, '0', STR_PAD_LEFT);
                $invalidCount++;
            }

            if ($originalTitle !== $formattedTitle) {
                $phone->title = $formattedTitle;
                $phone->save();
                $updatedCount++;
                $this->line("Updated: {$originalTitle} -> {$formattedTitle}");
            }
        }

        $this->info("Finished! Updated {$updatedCount} phone numbers.");

        return Command::SUCCESS;
    }
}
