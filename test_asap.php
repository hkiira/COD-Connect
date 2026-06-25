<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = new \App\Http\Controllers\AsapDeliveryController();
$sessionId = $controller->login();
if (!$sessionId) {
    die("Failed to login\n");
}

$orderId = 10; // or any ID
$curl = curl_init();
$body = [
    'id' => $orderId,
    'action' => 'showcolihistory',
];
$body = http_build_query($body);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_HEADER, false);
curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
$data = [
    "url" => "https://app.asapdelivery.ma/inc/colis.php",
    "customHeaders" => "true"
];
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($curl, CURLOPT_HTTPHEADER, array(
    "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
    "Accept: */*",
    "Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7,ar;q=0.6",
    "Origin: https://app.asapdelivery.ma",
    "Referer: https://app.asapdelivery.ma/colisu.php",
    "X-Requested-With: XMLHttpRequest",
    'cookie: ' . $sessionId,
));
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
$uploadResponse = \App\Services\ScrapeDoService::executeCurl($curl, $data);

file_put_contents(__DIR__.'/storage/logs/asap_html_dump.html', $uploadResponse);
echo "HTML dumped to storage/logs/asap_html_dump.html\n";
