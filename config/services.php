<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        /*
         * All three redirects are env-only, with no fallback on purpose.
         *
         * Two of them used to default to http://localhost:8000. A production
         * deploy that forgot the variable would not fail — it would build a
         * consent URL pointing at plaintext localhost and send the user
         * there, which looks like a broken login rather than a missing
         * config. Worse, these are the values Google matches against the
         * registered redirect list, so the failure surfaces as an opaque
         * redirect_uri_mismatch far from its cause.
         *
         * Unset now yields null, Google rejects it immediately, and the
         * missing variable is named in .env.example. Same reasoning as
         * client_id/client_secret above, which never had fallbacks, and as
         * CreemService throwing when its key is absent.
         */
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // Payments — Creem is the merchant of record: hosted checkout for plans
    // and Credits, and a signed webhook. Test-mode keys only work against
    // the test host, so test_mode and the key must agree. Product ids differ
    // between test and live too, hence env rather than the plans table.
    'creem' => [
        'api_key' => env('CREEM_API_KEY'),
        'webhook_secret' => env('CREEM_WEBHOOK_SECRET'),
        'test_mode' => (bool) env('CREEM_TEST_MODE', true),
        'products' => [
            'pro_monthly' => env('CREEM_PRODUCT_PRO_MONTHLY'),
            'pro_yearly' => env('CREEM_PRODUCT_PRO_YEARLY'),
            'max_monthly' => env('CREEM_PRODUCT_MAX_MONTHLY'),
            'max_yearly' => env('CREEM_PRODUCT_MAX_YEARLY'),
            // One-time, $1 a unit = 10,000 Credits; checkout sends the units.
            'credits' => env('CREEM_PRODUCT_CREDITS'),
        ],
    ],

    // Social posting — Post for Me relays posts to the nine platforms. One
    // API key, one shared workspace: tenancy lives in external_id (our user
    // ulid), enforced on our side. The webhook secret is issued when the
    // webhook is registered with them.
    'postforme' => [
        'key' => env('POSTFORME_API_KEY'),
        'base_url' => env('POSTFORME_BASE_URL', 'https://api.postforme.dev/v1'),
        'webhook_secret' => env('POSTFORME_WEBHOOK_SECRET'),
    ],

    // Any OpenAI-compatible chat endpoint, for the composer's caption help.
    'ai' => [
        'key' => env('AI_API_KEY'),
        'base_url' => env('AI_BASE_URL'),
        'model' => env('AI_MODEL'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
