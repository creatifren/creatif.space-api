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
        'read' => 'Read your channels, posts, files, Spaces, Credits balance, Engage inbox (comments and DMs) and Insight numbers',
        'draft' => 'Save draft posts and draft Spaces, and upload media to your library (never sends, schedules or publishes them)',
        'approve' => 'Create client approval links for your posts (Free: one client, posts up to 7 days ahead)',
        'publish' => 'Schedule and publish your drafts (at most 20 a day), publish your Spaces, and reply to comments and DMs, spending Credits',
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
        // list_top_posts: the top five posts by engagement (Pro / Max).
        'api.v1.insight.top-posts' => 'read',
        // get_best_times: best weekday + hour slots from the workspace's own posts (Pro / Max).
        'api.v1.insight.best-times' => 'read',
        // get_insight_report_link: a 15-minute signed PDF link (Pro / Max).
        'api.v1.insight.export-link' => 'read',
        // create_insight_share_link / revoke_insight_share_link: sharing with
        // a client, like an approval link (Pro / Max).
        'api.v1.insight.share.store' => 'approve',
        'api.v1.insight.share.destroy' => 'approve',
        // Drafts only: EnforceTokenScope refuses a body that would send,
        // schedule or ask for approval.
        'api.v1.social-posts.store' => 'draft',

        // M2. Changing a post that is already queued additionally needs
        // `publish`; the MCP server checks that before it calls.
        'api.v1.social-posts.update' => 'draft',
        'api.v1.social-posts.send-preview' => 'read',
        // CapTokenSends holds bearer callers to 20 sends a day.
        'api.v1.social-posts.send' => 'publish',
        // get_notification_settings / update_notification_settings: the
        // user's own three email switches.
        'api.v1.me.notification-settings' => 'read',
        'api.v1.me.notification-settings.update' => 'draft',
        // retry_post: the preview reads; the retry spends Credits like a send.
        'api.v1.social-posts.retry-preview' => 'read',
        'api.v1.social-posts.retry' => 'publish',
        'api.v1.social-approval-links.store' => 'approve',
        // send_approval_reminder: one email per link per 12 h (in the controller).
        'api.v1.social-approval-links.remind' => 'approve',
        'api.v1.social-approval-links.show' => 'read',
        // get_approval_audit: the owner's own link, as CSV.
        'api.v1.social-approval-links.audit' => 'read',
        // get_approval_history: one post's decisions, email masked.
        'api.v1.social-posts.approval-audit' => 'read',
        // get_plan_calendar_link: reads the plan's feed URL (turning it on or
        // off stays in the app).
        'api.v1.social-approval-links.calendar.show' => 'read',

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

        // Media housekeeping (get_storage_usage / keep_file / unkeep_file).
        // Quota and plan rules stay in MediaQuota: Keep is paid, 402 when full.
        'api.v1.files.usage' => 'read',
        'api.v1.files.keep' => 'draft',
        'api.v1.files.unkeep' => 'draft',

        // Post housekeeping. discard_draft: SocialPostController::discard
        // deletes a draft and nothing else (422 otherwise). list_approval_links:
        // the links list, read like the single link above.
        'api.v1.social-posts.discard' => 'draft',
        'api.v1.social-approval-links.index' => 'read',
        // Approver side (decide_approval / list_waiting_approvals): only
        // links whose approver_email is this account's verified email.
        'api.v1.me.approvals.waiting' => 'approvals:decide',
        'api.v1.me.approvals.preview' => 'approvals:decide',
        'api.v1.me.approvals.decide' => 'approvals:decide',
        // list_shared_with_me: Content Plans sent to this account's email.
        'api.v1.me.reports.shared' => 'approvals:decide',
        // send_feedback: a note to the team, nothing public, no Credits.
        'api.v1.feedback.store' => 'draft',

        // Spaces (list_spaces / create_space_from_files / publish_space).
        // Creating makes a Draft+Private Space, never a published one;
        // publishing needs `publish` and the publish preview's confirm_token.
        'api.v1.spaces.index' => 'read',
        'api.v1.spaces.show' => 'read',
        'api.v1.spaces.store' => 'draft',
        'api.v1.spaces.publish-preview' => 'read',
        'api.v1.spaces.publish' => 'publish',
    ],

    /*
     * Route name => plan feature a token caller's plan must have, on top of
     * the scope. Past it: the standard 402 plan_required (PlanFeatureRequired).
     * The app keeps showing a Free user's existing Spaces; connected apps
     * get Spaces on Pro and Max only.
     */
    'route_features' => [
        'api.v1.spaces.index' => 'spaces',
        'api.v1.spaces.show' => 'spaces',
        'api.v1.spaces.store' => 'spaces',
        'api.v1.spaces.publish-preview' => 'spaces',
        'api.v1.spaces.publish' => 'spaces',
    ],
];
