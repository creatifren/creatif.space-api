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
        // POST /auth/google/native accepts ID tokens whose `aud` is client_id
        // (the Android app passes it as serverClientId) or one of these
        // (comma-separated). Empty by default.
        'native_extra_audiences' => env('GOOGLE_NATIVE_EXTRA_AUDIENCES', ''),
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
            // The Pro products above carry Creem's 7-day trial (card taken).
            // These twins have none: an owner who already had a trial or a
            // paid plan is sent here. Empty = falls back to the products
            // above, so a repeat trial is possible (logged).
            'pro_monthly_notrial' => env('CREEM_PRODUCT_PRO_MONTHLY_NOTRIAL'),
            'pro_yearly_notrial' => env('CREEM_PRODUCT_PRO_YEARLY_NOTRIAL'),
        ],
        // Must match the trial period on the Pro products in Creem.
        'trial_days' => 7,
        // "Founding 100": a Creem discount code (30% off the first yearly
        // payment, 100 redemptions) sent on every yearly checkout. Empty = off.
        'founding_code' => env('CREEM_FOUNDING_CODE'),
        'founding_percent' => 30,
    ],

    // Social posting — which relay new accounts and posts go through.
    // Rows remember their own provider, so switching leaves earlier posts
    // with the one that holds them.
    'social' => [
        'provider' => env('SOCIAL_PROVIDER', 'outstand'),
        // Credits for one publish (one post to one account), by platform; a
        // platform not listed costs `default`. X has its own because X's API
        // bills us per post: $0.015, or $0.20 when it contains a URL.
        'per_send' => [
            'default' => (int) env('CREDITS_PER_SEND', 300),
            'x' => (int) env('CREDITS_PER_SEND_X', 300),
            'x_link' => (int) env('CREDITS_PER_SEND_X_LINK', 2000),
        ],
    ],

    // Outstand — the relay since the 11 Nov launch. Same tenancy as below,
    // in Outstand's tenant_id. The webhook secret is the signing secret
    // set on the webhook in Outstand's dashboard (Settings → Webhooks).
    'outstand' => [
        'key' => env('OUTSTAND_API_KEY'),
        'base_url' => env('OUTSTAND_BASE_URL') ?: 'https://api.outstand.so/v1',
        'webhook_secret' => env('OUTSTAND_WEBHOOK_SECRET'),
        // Instagram sign-in: `scopes` replaces Outstand's defaults, so this is
        // the default list; instagram_business_manage_messages is added to it.
        // Verify against Outstand's docs/dashboard — see the Inbox PR.
        'instagram_scopes' => array_values(array_filter(explode(',', (string) (env('OUTSTAND_INSTAGRAM_SCOPES')
            ?: 'instagram_business_basic,instagram_business_content_publish,instagram_business_manage_comments,instagram_business_manage_insights')))),
        // Private reply body: nested (instagram.comment_id + author_id),
        // top_level (comment_id), or auto (nested, then top level).
        'private_reply_shape' => env('OUTSTAND_PRIVATE_REPLY_SHAPE', 'auto'),
    ],

    'inbox' => [
        'enabled' => (bool) env('INBOX_ENABLED', true),
        // Credits one Inbox reply costs (DM, comment reply, private reply). 0 = free.
        'credits_per_reply' => (int) env('CREDITS_PER_REPLY', 0),
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

    // Where Enterprise "Contact sales" requests are emailed.
    'enterprise' => [
        'contact_email' => env('ENTERPRISE_CONTACT_EMAIL', 'halo@creatif.space'),
    ],

    // Any OpenAI-compatible chat endpoint, for the composer's caption help.
    'ai' => [
        'key' => env('AI_API_KEY'),
        'base_url' => env('AI_BASE_URL'),
        'model' => env('AI_MODEL'),
    ],

    /*
     * Server-side product analytics (PostHog). Empty key = off: nothing is
     * queued, nothing is sent. The internal lists mark staff and test
     * accounts as is_internal so dashboards can filter them out.
     */
    'posthog' => [
        'key' => env('POSTHOG_API_KEY'),
        'host' => env('POSTHOG_HOST', 'https://us.i.posthog.com'),
        'internal_email_domains' => array_values(array_filter(array_map(
            fn (string $d) => strtolower(ltrim(trim($d), '@')),
            explode(',', (string) env('ANALYTICS_INTERNAL_EMAIL_DOMAINS', '')),
        ))),
        'internal_emails' => array_values(array_filter(array_map(
            fn (string $e) => strtolower(trim($e)),
            explode(',', (string) env('ANALYTICS_INTERNAL_EMAILS', '')),
        ))),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
        // Confirmed waitlist sign-ups land in these segments; empty = confirm still works, logged.
        'waitlist_segments' => [
            'mcp' => env('RESEND_WAITLIST_MCP_SEGMENT', ''),
        ],
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
