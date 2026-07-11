<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$review = \App\Models\Review::first();
if ($review) {
    print_r($review->toArray());
    echo "\nAccountUser: " . (\App\Models\AccountUser::find($review->user_id) ? 'Found' : 'Not Found');
    echo "\nUser: " . (\App\Models\User::find($review->user_id) ? 'Found' : 'Not Found');
} else {
    echo "No reviews found";
}
