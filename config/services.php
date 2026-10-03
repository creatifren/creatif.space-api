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
         * MidtransService throwing when its key is absent.
         */
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        // Separate callback for the Drive connect flow (scope drive.file).
        'drive_redirect' => env('GOOGLE_DRIVE_REDIRECT_URI'),
    ],

    // Payments — subscription checkout (Snap) and its webhook. The server
    // key is also the webhook signing secret, so it never leaves the server.
    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),
    ],

    // Social posting — Outstand relays posts to the platforms. One API key,
    // one org shared by every user: Outstand has no per-user tenant, so which
    // account belongs to whom is recorded and enforced on our side only. The
    // webhook secret is whatever was typed into their dashboard.
    'outstand' => [
        'key' => env('OUTSTAND_API_KEY'),
        'org_id' => env('OUTSTAND_ORG_ID'),
        'base_url' => env('OUTSTAND_BASE_URL', 'https://api.outstand.so/v1'),
        'connect_url' => 'https://www.outstand.so/app/api/socials',
        'webhook_secret' => env('OUTSTAND_WEBHOOK_SECRET'),
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
