<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ReviewQuestion;

class ReviewQuestionsSeeder extends Seeder
{
    public function run()
    {
        // Question 1: Stars
        ReviewQuestion::create(['text' => 'Quality of Product', 'type' => 'stars']);
        ReviewQuestion::create(['text' => 'Quality of Delivery', 'type' => 'stars']);
        ReviewQuestion::create(['text' => 'Quality of Package', 'type' => 'stars']);

        // Question 2: Multiselect
        $newsQuestion = ReviewQuestion::create(['text' => 'How do you want to receive news?', 'type' => 'multiselect']);
        $newsQuestion->options()->createMany([
            ['label' => 'WhatsApp', 'value' => 'whatsapp'],
            ['label' => 'Appel', 'value' => 'appel'],
            ['label' => 'SMS', 'value' => 'sms'],
        ]);
        
        // Question 3: Text
        ReviewQuestion::create(['text' => 'Any other comments?', 'type' => 'text']);
    }
}
