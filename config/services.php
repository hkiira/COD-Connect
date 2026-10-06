<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Read here, not with env() in the code: once `php artisan config:cache` runs (the aaPanel deploy
    // does), env() returns null outside config files. Defaults stay in the controllers.
    'speedaf' => [
        'app_code'        => env('SPEEDAF_APPCODE'),
        'secret_key'      => env('SPEEDAF_SECRETKEY'),
        'customer_code'   => env('SPEEDAF_CUSTOMERCODE'),
        'platform_source' => env('SPEEDAF_PLATFORMSOURCE'),
        'base_url'        => env('SPEEDAF_BASE_URL'),
        'vip_url'         => env('SPEEDAF_VIP_URL'),
        'sender'          => [
            'name'    => env('SPEEDAF_SENDER_NAME'),
            'address' => env('SPEEDAF_SENDER_ADDRESS'),
            'phone'   => env('SPEEDAF_SENDER_PHONE'),
            'city'    => env('SPEEDAF_SENDER_CITY'),
        ],
    ],

    'scrapedo' => [
        // comma-separated scrape.do tokens
        'tokens' => env('SCRAPEDO_TOKENS'),
    ],

    'afra' => [
        'base_url' => env('AFRA_API_URL', 'https://afradelivery.com/api/seller'),
        'ca_bundle' => env('AFRA_CA_BUNDLE'),
        // carriers.id of AFRA DELIVERY in this installation
        'carrier_id' => (int) env('AFRA_CARRIER_ID', 26),
        // The API does not say how long an access token lives: keep it a while, re-login on 401.
        'token_ttl' => (int) env('AFRA_TOKEN_TTL', 3000),
    ],

];
