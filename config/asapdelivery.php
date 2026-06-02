<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | ASAP Delivery API Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for the ASAP Delivery API.
    |
    */

    'base_url' => env('ASAP_DELIVERY_BASE_URL', 'https://api.asapdelivery.ma'),

    'token' => env('ASAP_DELIVERY_TOKEN'),

    'secret_key' => env('ASAP_DELIVERY_SECRET_KEY'),
];
