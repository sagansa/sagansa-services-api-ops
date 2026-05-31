<?php

$defaultDevOrigins = [
    'http://localhost:3000',    // Next.js Admin Web
    'http://127.0.0.1:3000',    // Next.js Admin Web
    'http://localhost:3002',    // Next.js Admin Web (alternative port)
    'http://127.0.0.1:3002',    // Next.js Admin Web (alternative port)
    'http://localhost:3003',    // Next.js Admin Web (alternative port)
    'http://127.0.0.1:3003',    // Next.js Admin Web (alternative port)
    'http://localhost:8081',    // React Native POS/Presence (jika berjalan di web)
    'http://127.0.0.1:8081',    // React Native POS/Presence (jika berjalan di web)
    'http://localhost:19006',   // Expo Go
    'http://127.0.0.1:19006',   // Expo Go
    'https://admin.sagansa.id', // Production Admin Web
    'https://ops.sagansa.id',   // Production Ops Web
    'https://presence.sagansa.id', // Production Presence
    'https://*.sagansa.id',     // Wildcard subdomains
];

$rawFrontendOrigins = array_filter(
    array_map('trim', explode(',', (string) env('FRONTEND_URLS', env('FRONTEND_URL'))))
);

$allowedOrigins = array_values(array_unique(array_merge($defaultDevOrigins, $rawFrontendOrigins)));

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

    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => env('CORS_SUPPORTS_CREDENTIALS', false),

];
