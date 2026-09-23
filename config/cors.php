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

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'v1/central/*'],

    'allowed_methods' => ['*'],

    // Parse comma-separated origins from env: CORS_ALLOWED_ORIGINS=http://staging.mfukoplus.com,http://localhost:3000
    'allowed_origins' => array_values(array_unique(array_filter(array_merge(
        array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', 'http://staging.mfukoplus.com,http://localhost:3000'))),
        [
            // Explicit deployment origins; do not trust unrelated vercel.app projects.
            'https://finance-link-frontend-2026.vercel.app',
            'https://mfukodemo.vercel.app',
            'https://buwatesacco.vercel.app',
            'https://finance-link-frontend-2026-git-main-newtonyamu22-2109s-projects.vercel.app',
        ],
    )))),

    'allowed_origins_patterns' => [
        // Allow any subdomain of staging.mfukoplus.com (e.g. api.staging.mfukoplus.com)
        '#^https?://([a-z0-9\-]+\.)?staging\.mfukoplus\.com$#',
        // Allow any subdomain of mfukopro.ug (e.g. sacco1.mfukopro.ug)
        '#^https?://([a-z0-9\-]+\.)?mfukopro\.ug$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true, // updated from false

];
