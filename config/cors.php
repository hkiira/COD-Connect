<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Handled globally by \Illuminate\Http\Middleware\HandleCors (App\Http\Kernel).
    | Do not add Access-Control-* headers in nginx / .htaccess as well: the browser
    | rejects a response that carries the header twice.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth', 'storage/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://codconnect.com',
        'https://codconnect.cloud',
    ],
    // codconnect.com / codconnect.cloud and all their subdomains (space., mini., omnichat., ...),
    // plus localhost on any port for development.
    'allowed_origins_patterns' => [
        '#^https?://([a-z0-9-]+\.)*codconnect\.(com|cloud)$#i',
        '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 86400,
    // The frontend authenticates with a Bearer token, not cookies.
    'supports_credentials' => false,
];
