<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The Next.js frontend (creatif.space) talks to this API with cookie-based
    | Sanctum SPA auth, so credentials support is required and origins must be
    | listed explicitly (no wildcard).
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'auth/*'],

    'allowed_methods' => ['*'],

    // PUBLIC_URL: client pages (Space password gate, downloads, view events)
    // are served from creatif.space via the marketing proxy and call the API from there.
    // MARKETING_URL: the creatif.space waitlist form (only its endpoint takes that origin; see MarketingSiteOrigin).
    'allowed_origins' => array_values(array_unique(array_filter([env('FRONTEND_URL', 'http://localhost:3000'), env('PUBLIC_URL'), rtrim((string) env('MARKETING_URL', 'https://creatif.space'), '/')]))), // env() is fine here: config files are the one place it is allowed

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // Browsers may reuse a preflight answer for 2 h (Chrome caps it there),
    // so the approval page's decision is one round trip, not two, when a
    // client approves and closes the tab. It only caches what is allowed
    // above; it widens no origin, method or header, here or elsewhere.
    'max_age' => 7200,

    'supports_credentials' => true,

];
