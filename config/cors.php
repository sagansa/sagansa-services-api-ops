<?php

$rawFrontendOrigins = array_filter(
    array_map('trim', explode(',', (string) env('FRONTEND_URLS', env('FRONTEND_URL'))))
);

$defaultDevOrigins = [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost:3001',
    'http://127.0.0.1:3001',
];

$allowedOrigins = $rawFrontendOrigins;

if ($allowedOrigins === []) {
    $allowedOrigins = env('APP_ENV') === 'production'
        ? ['https://yourdomain.com']
        : $defaultDevOrigins;
}

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

    // Allow fine-grained origin control in production while keeping dev permissive
    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
