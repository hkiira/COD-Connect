<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$order = \App\Models\Order::first();
echo "Order status before: " . $order->order_status_id . "\n";

$deliveredComment = \App\Models\Comment::where('new_statut', 7)->first();
$updateRequest = new \Illuminate\Http\Request([
    [
        'id' => $order->id,
        'comment' => [
            'id' => $deliveredComment->id,
            'title' => 'Exchange Processed Test'
        ]
    ]
]);

// Need to mock user account
$accountUser = \App\Models\AccountUser::first();
auth()->loginUsingId($accountUser->user_id);
\Illuminate\Support\Facades\Session::put('account_user', $accountUser);

\App\Http\Controllers\OrderController::update($updateRequest, 1);

$order->refresh();
echo "Order status after: " . $order->order_status_id . "\n";
