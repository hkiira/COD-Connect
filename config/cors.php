<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        // Production (HTTPS & HTTP)
        'https://omnichat.codconnect.cloud',
        'https://api-omnichat.codconnect.cloud',
        'https://google-adk.codconnect.cloud',
        'http://omnichat.codconnect.cloud',
        'http://api-omnichat.codconnect.cloud',

        // Plain Domains
        'omnichat.codconnect.cloud',
        'api-omnichat.codconnect.cloud',
        'google-adk.codconnect.cloud',

        // Local Development
        'http://localhost:3000',
        'http://localhost:3001',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:3001',
        'localhost',
        '127.0.0.1',
        'http://localhost',
        'http://localhost:3002',
        'http://localhost:3032',
        'https://space.codconnect.com',
        'https://mini.codconnect.com',
        'https://mini.codconnect.cloud',
        'https://codconnect.cloud'
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
