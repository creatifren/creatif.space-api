<?php

/*
 * MCP (Model Context Protocol) access: OAuth 2.1 for MCP clients (Claude,
 * ChatGPT) and personal API keys (Claude Desktop / Code, Cursor).
 *
 * A token — OAuth or API key — only ever reaches the V1 routes listed in
 * `routes` below, and only with the scope written next to each. Anything
 * else answers 403 even if the session cookie for the same person could
 * do it.
 */
return [

    // The protected resource (the Node MCP server). Its URL is the token
    // audience the authorization server advertises.
    'resource_url' => env('MCP_RESOURCE_URL', 'https://mcp.creatif.space/mcp'),

    // Shared secret the MCP server sends to POST /api/v1/oauth/introspect
    // in the X-MCP-Secret header. Empty = introspection is closed.
    'introspection_secret' => env('MCP_INTROSPECT_SECRET', ''),

    'scopes' => [
        'read' => 'Read your channels, posts, files, Credits balance, Engage inbox (comments and DMs) and Insight numbers',
        'draft' => 'Save draft posts and upload media to your library (never sends or schedules them)',
        'approve' => 'Create client approval links for your posts (Free: one client, posts up to 7 days ahead)',
        'publish' => 'Schedule and publish your drafts (at most 20 a day) and reply to comments and DMs, spending Credits',
        // The approver side: posts other people sent you for approval.
        'approvals:decide' => 'Approve or request changes on posts sent to you',
    ],

    // Consent-screen titles where ucfirst(id) would read badly.
    'scope_labels' => [
        'approvals:decide' => 'Decide approvals',
    ],

    'default_scopes' => ['read'],

    // Lifetimes. Access tokens are short; refresh tokens rotate on use.
    'access_token_ttl_minutes' => (int) env('MCP_ACCESS_TOKEN_TTL_MINUTES', 60),
    'refresh_token_ttl_days' => (int) env('MCP_REFRESH_TOKEN_TTL_DAYS', 30),

    // Requests per minute, per token (OAuth access token or API key).
    'rate_limit_per_minute' => (int) env('MCP_RATE_LIMIT_PER_MINUTE', 120),

    // MCP audit log rows are deleted after this many days (privacy page).
    'audit_retention_days' => (int) env('MCP_AUDIT_RETENTION_DAYS', 90),

    // Personal API keys.
    'api_keys' => [
        'max_active' => 10,
        'prefix' => 'cs_live_',
    ],

    // Dynamic Client Registration (RFC 7591) — open registration, rate
    // limited per IP. Off switch for incidents.
    'registration_enabled' => (bool) env('MCP_REGISTRATION_ENABLED', true),

    /*
     * Route name => scope a token needs to call it. Not listed = 403.
     */
    'routes' => [
        'api.v1.me' => 'read',
        'api.v1.social-accounts.index' => 'read',
        'api.v1.social-accounts.pinterest-boards' => 'read',
        'api.v1.social-posts.index' => 'read',
        'api.v1.social-posts.show' => 'read',
        'api.v1.files.index' => 'read',
        'api.v1.credits.show' => 'read',
        'api.v1.me.subscription' => 'read',
        // get_insight_summary: Pro / Max (402 plan_required on Free).
        'api.v1.insight.summary' => 'read',
        // get_insight_report_link: a 15-minute signed PDF link (Pro / Max).
        'api.v1.insight.export-link' => 'read',
        // Drafts only: EnforceTokenScope refuses a body that would send,
        // schedule or ask for approval.
        'api.v1.social-posts.store' => 'draft',

        // M2. Changing a post that is already queued additionally needs
        // `publish`; the MCP server checks that before it calls.
        'api.v1.social-posts.update' => 'draft',
        'api.v1.social-posts.send-preview' => 'read',
        // CapTokenSends holds bearer callers to 20 sends a day.
        'api.v1.social-posts.send' => 'publish',
        'api.v1.social-approval-links.store' => 'approve',
        'api.v1.social-approval-links.show' => 'read',

        // Media (request_media_upload / confirm_media_upload): the same
        // presign and complete as the app — limits, quota and the
        // magic-byte check all stay in FileController.
        'api.v1.files.presign' => 'draft',
        'api.v1.files.complete' => 'draft',
        'api.v1.files.show' => 'read',

        // Engage. Replying sends in the user's name (and may cost Credits):
        // `publish`, like a send. The MCP server previews before it replies.
        'api.v1.inbox.feed.index' => 'read',
        'api.v1.inbox.feed.show' => 'read',
        'api.v1.inbox.feed.reply' => 'publish',

        // AI caption (suggest_caption): a suggestion, nothing saved. `draft`,
        // like writing a draft; AI_CAPTION_ENABLED and the plan gate stay in
        // AiCaptionController (404 while off, 402 on Free).
        'api.v1.ai.caption' => 'draft',
        // Approver side (decide_approval / list_waiting_approvals): only
        // links whose approver_email is this account's verified email.
        'api.v1.me.approvals.waiting' => 'approvals:decide',
        'api.v1.me.approvals.preview' => 'approvals:decide',
        'api.v1.me.approvals.decide' => 'approvals:decide',
    ],
];
