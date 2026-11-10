<?php

/*
 * MCP (Model Context Protocol) access: OAuth 2.1 for MCP clients (Claude,
 * ChatGPT) and personal API keys (Claude Desktop / Code, Cursor).
 *
 * A token — OAuth or API key — only ever reaches the V1 routes listed in
 * `routes` below, and only with the scope written next to each. Anything
 * else answers 403 even if the session cookie for the same person could
 * do it. Approve and publish are reserved for M2 and are not listed.
 */
return [

    // The protected resource (the Node MCP server). Its URL is the token
    // audience the authorization server advertises.
    'resource_url' => env('MCP_RESOURCE_URL', 'https://mcp.creatif.space/mcp'),

    // Shared secret the MCP server sends to POST /api/v1/oauth/introspect
    // in the X-MCP-Secret header. Empty = introspection is closed.
    'introspection_secret' => env('MCP_INTROSPECT_SECRET', ''),

    'scopes' => [
        'read' => 'Read your channels, posts, files and Credits balance',
        'draft' => 'Save draft posts (never sends or schedules them)',
    ],

    'default_scopes' => ['read'],

    // Lifetimes. Access tokens are short; refresh tokens rotate on use.
    'access_token_ttl_minutes' => (int) env('MCP_ACCESS_TOKEN_TTL_MINUTES', 60),
    'refresh_token_ttl_days' => (int) env('MCP_REFRESH_TOKEN_TTL_DAYS', 30),

    // Requests per minute, per token (OAuth access token or API key).
    'rate_limit_per_minute' => (int) env('MCP_RATE_LIMIT_PER_MINUTE', 120),

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
        'api.v1.social-posts.index' => 'read',
        'api.v1.social-posts.show' => 'read',
        'api.v1.files.index' => 'read',
        'api.v1.credits.show' => 'read',
        'api.v1.me.subscription' => 'read',
        // Drafts only: EnforceTokenScope refuses a body that would send,
        // schedule or ask for approval.
        'api.v1.social-posts.store' => 'draft',
    ],
];
