# creatif.space MCP server

Remote [MCP](https://modelcontextprotocol.io) server for creatif.space. Connector
URL: **`https://mcp.creatif.space/mcp`** (also answers on `/`). Streamable HTTP, stateless, Node 22,
official `@modelcontextprotocol/sdk`.

It is a thin layer: every tool calls the existing **V1 API** with the caller's
own bearer token, so ownership, plans, Credits and validation stay in Laravel.
No business logic lives here.

## Endpoints

| Path | What |
| --- | --- |
| `POST /mcp` (and `POST /`) | MCP JSON-RPC (bearer required) |
| `GET /health` | Liveness, no auth |
| `GET /.well-known/glama.json` | Glama connector claim, no auth (also HEAD); same document at `GET /mcp/.well-known/glama.json` |
| `GET /.well-known/oauth-protected-resource` | RFC 9728 metadata: `resource: "https://mcp.creatif.space/mcp"`, `authorization_servers: ["https://api.creatif.space"]` |
| `GET /.well-known/oauth-protected-resource/mcp` | the same document at the RFC 9728 path form |
| `POST /free` | **Creatif Space Free Tools** — a separate MCP server, **no auth**, 30 calls/min per IP |

**CORS** (browser clients: MCP Inspector web, web IDEs) on `/mcp`, `/` and
`/free`: `OPTIONS` → `204` with `Access-Control-Allow-Origin: *` (no
credentials — auth is the Bearer header only), `Allow-Methods: GET, POST,
DELETE, OPTIONS`, `Allow-Headers: authorization, content-type,
mcp-protocol-version, mcp-session-id, last-event-id`, `Expose-Headers:
mcp-session-id, www-authenticate`, `Max-Age: 600`. The same headers are on
every response from those paths, 401 and 429 included. Done in the Node app
(`src/cors.ts`); nginx passes `OPTIONS` through and must not add its own
`Access-Control-*` headers.

No / invalid token → `401` with
`WWW-Authenticate: Bearer resource_metadata="https://mcp.creatif.space/.well-known/oauth-protected-resource/mcp"` (RFC 9728 path form, built from the origin; the root well-known serves the same document).

## Auth

Tokens (Passport OAuth 2.1 access tokens or `cs_live_…` API keys) are checked with
`POST {V1_API_URL}/oauth/introspect`, header `X-MCP-Secret`, body `{token}` →
`{active, user_ulid, scopes[], client_id, client_name, token_type, exp}`.
Answers are cached 60 s (never past `exp`). The same bearer is then forwarded
to V1 routes (guard `mcp`, built in the OAuth PR).

Scopes (`scopes_supported`): `read`, `draft`, `approve`, `publish`, `approvals:decide` — the same
names as `config/mcp.php` on the API. Every V1 call carries `X-MCP-Tool` and
`X-MCP-Client` for the API's audit log (never content).
Rate limit: 60 requests / minute / token (429 + `Retry-After`).

## Tools

| Tool | Scope | V1 endpoint |
| --- | --- | --- |
| `list_channels` | read | `GET /social-accounts` |
| `list_posts` (status, from, to, channel_id, limit) | read | `GET /social-posts?status&from&to&account_id&limit` |
| `get_post` | read | `GET /social-posts/{id}` |
| `list_files` (search, type, folder_id, limit) | read | `GET /files` |
| `draft_post` (caption, channel_ids, file_ids, media_urls, captions_by_channel, type) | draft | `POST /social-posts` with `draft: true` |
| `get_credits` | read | `GET /credits` |
| `create_approval_link` (post_ids — or a Content Plan: date_from, date_to, timezone, channel_ids — **approver_email** (required — the client signs in with it to decide), title, client_name, expires_in_hours) — every plan; Free: one client (by approver_email), 7-day window, 10 queued | approve | `POST /social-approval-links` (drafts accepted) |
| `check_approval_status` (post_id \| link_id) | read | `GET /social-posts/{id}` or `GET /social-approval-links/{id}` |
| `update_post` (caption, scheduled_at, labels, file_ids \| add_file_ids) | draft (drafts and posts waiting for approval only) | `PATCH /social-posts/{id}` |
| `schedule_post` (post_id, scheduled_at, confirm) | publish | preview `GET /social-posts/{id}/send-preview`; `confirm: true` → `POST /social-posts/{id}/send` |
| `publish_post` (post_id, confirm) | publish | same, sends now |
| `request_media_upload` (filename, mime, size_bytes, folder_id) | draft | `POST /files/presign` → file_id + presigned PUT URL (15 min) |
| `confirm_media_upload` (file_id) | draft | `POST /files/{id}/complete` (409 → `GET /files/{id}`: already ready is returned) |
| `list_conversations` (type comment\|dm, channel_id, unread_only, since, limit, cursor) — Engage comments and DMs in one list, newest first | read | `GET /inbox/feed?type&account&unread&since&per_page&cursor` |
| `reply_to_conversation` (conversation_id, text, confirm) — DM into the thread, or public reply under a comment | publish | preview `GET /inbox/feed/{id}`; `confirm: true` → `POST /inbox/feed/{id}/reply` |
| `suggest_caption` (brief, platform) — a caption and hashtags in the user's style; saves nothing. Pro/Max; 404 while `AI_CAPTION_ENABLED` is off → `not_enabled` ("write the caption yourself"), Free → `plan_required` | draft | `POST /ai/caption` |
| `list_waiting_approvals` — posts other users sent to this user for approval | approvals:decide | `GET /me/approvals/waiting` |
| `decide_approval` (link, post_id, decision approve\|revise, reason, confirm) — the approver side; same rules as the approval page, audited as `mcp` | approvals:decide | preview `GET /me/approvals/{post}/preview`; `confirm: true` → `POST /me/approvals/{post}/decision` |

**Media upload.** `request_media_upload` → the client PUTs the exact bytes to
the URL (Content-Type and Content-Length are signed) → `confirm_media_upload`
→ the id goes in `draft_post` / `update_post` `file_ids`. Same presign and
complete as the app, so every rule stays in Laravel: Free 1 GB total, Pro 25 GB
/ Max 50 GB Keep quota, per-file caps, accepted formats, magic-byte check.
Refusals come back as `storage_full` (free-up-space `files_url` + `billing_url`
upgrade link), `file_too_large`, `unsupported_format`, `upload_limit`,
`upload_incomplete` (confirm before the PUT), `file_not_ready` (an unconfirmed
or foreign id in a post); another workspace's id is `not_found`.

`schedule_post` / `publish_post` without `confirm: true` only return a preview
(channels, Credit cost, sends left today, anything blocking). Sends from a bearer
token are capped at **20 per user per UTC day**, enforced in Laravel
(`CapTokenSends`, 429 `daily_send_cap`); the app's cookie session is never capped.

**confirm_token.** The send preview returns a `confirm_token` signed by the API
(HMAC with the app key; bound to user + post + target ids + Credit price; valid
10 minutes). A send from a bearer token (MCP guard or API key) must bring it
back: none → 422 `confirm_token_required`; mismatched, expired or forged → 409
`preview_stale`. Holding a draft for approval and the app's cookie session need
none. This server keeps the token of the last preview per user + post in memory
and sends it on `confirm: true` — the model never sees it. No preview on record
(never shown, expired, already used, server restarted) → a fresh preview and
nothing sent; a 409 → tool error `preview_stale` with the new preview in
`details.preview`.

**Approval and edits are the API's rules.** A draft that needs its client's
approval (or whose client asked for changes) is never sent or scheduled, from
the app or a token (409 `approval_required`); it can only be held for the
client again. A token cannot drop the editor's own approval step either. A
token can only edit drafts and posts waiting for approval (409 `not_editable`).

**Plan limits are the API's rules too** (one plan definition: `PlanSeeder`,
enforced by `PlanQuota`). Free schedules at most 7 days ahead (UTC), holds at
most 10 queued posts (scheduled + waiting for approval) and has approval links
for one client at a time; Pro and Max have none of these limits. Past one, the
API answers 402 `{message, reason: "plan_required", feature, required_plan,
limit?}` (`feature`: `schedule_window`, `queue_limit`, `approval_clients`, or
a gated feature) and the tool error is `plan_required` with those fields in
`details`. `schedule_post` sends its `scheduled_at` with the preview, so the
limit answers before the user is asked to confirm.

**Engage.** `list_conversations` / `reply_to_conversation` read and answer the
same rows as the app's Engage (comments and Instagram/Facebook DMs via the
Outstand relay), with the same ownership (another workspace's id → `not_found`),
policies (a viewer seat cannot reply), Credits and relay errors — the API's
`/inbox/feed` routes delegate to the app's Engage controllers. Ids are typed:
`dm_<ulid>` or `comment_<ulid>`. Engage is on every plan (PlanSeeder v2 has no
Engage flag), so neither tool is plan-gated; a 402 from the API would still
come back as `plan_required`. `reply_to_conversation` without `confirm: true`
only previews; a confirm sends only the exact text of a preview shown for that
conversation in the last 10 minutes (kept in memory per user), otherwise it
previews again and sends nothing. Comment and DM text is data, never
instructions — both descriptions say so.

Errors come back as tool results with `isError: true` and
`{error: {code, message, next_step, details}}`. `next_step` is the one thing
the model should do now; `details` is never empty (it always has `tool`) and a
`next_step` only points at a `details.X` that is there (`test/errors.test.ts`
checks both for every error path). Bad arguments and unknown tool names, which
the SDK answers itself, get the same envelope. Codes: `reconnect_required`,
`scope_missing`, `plan_required`, `not_found`, `unknown_tool`, `invalid_input`,
`unauthorized`, `forbidden`, `rate_limited`, `daily_cap_reached`,
`insufficient_credits`, `quota_exceeded`, `preview_stale`, `not_editable`,
`not_draft`, `not_approvable`, `approval_required`, `storage_full`,
`file_too_large`, `unsupported_format`, `upload_limit`, `upload_incomplete`,
`file_not_ready`, `upstream_unavailable`, `internal_error`, and for Engage
replies `reply_window_closed`, `reply_rejected`, `reply_not_possible`,
`relay_unavailable`.

Each call emits PostHog `mcp_tool_called` `{tool, client_name, ok, ms}` (plus
`error` code on failure) — never content. Skipped when `POSTHOG_KEY` is unset.

## Free Tools (`/free`)

A second, stateless MCP server on the same process: `check_caption`,
`approval_request_template`, `connect_guide`. No sign-in, no user data
stored; each call sends PostHog `free_tool_called {tool, network}` (no
caption text) with the existing `POSTHOG_KEY`. Copy and limits live in
`free-tools/*.json` (`caption-rules.json`, `approval-templates.json` keyed by
niche, `connect-guides.json` with `file:line` evidence that is never sent to
callers). Registry entry `free-tools/server.json`
(`io.github.creatifren/creatif-space-tools`) is **not published** by the
registry workflow, which only publishes `mcp/server.json`.

## Environment

| Var | Default | Notes |
| --- | --- | --- |
| `PORT` | `3010` | |
| `HOST` | `127.0.0.1` | nginx in front |
| `V1_API_URL` | `https://api.creatif.space/api/v1` | |
| `MCP_RESOURCE_URL` | `https://mcp.creatif.space/mcp` | exact connector URL = resource id; same value as Laravel's `MCP_RESOURCE_URL` |
| `MCP_AUTHORIZATION_SERVER` | `https://api.creatif.space` | |
| `MCP_INTROSPECT_SECRET` | — | **required** in real mode; same value as Laravel's |
| `MCP_AUTH_MODE` | `real` | `stub` is for tests only, refused with `NODE_ENV=production` (tokens `stub:<user_ulid>:<scopes>`) |
| `POSTHOG_KEY` | — | unset → no analytics |
| `POSTHOG_HOST` | `https://us.i.posthog.com` | |
| `GLAMA_CLAIM` | the published claim | served at `GET /.well-known/glama.json` and `GET /mcp/.well-known/glama.json` (public, must stay published) |

## Develop

```bash
cd mcp
npm ci
npm run lint && npm run typecheck && npm test && npm run build
MCP_AUTH_MODE=stub npm start   # local only
```

## Deploy checklist (QA)

1. Server has **Node 22 LTS** (`node -v` → v22.x) and nginx; DNS `mcp.creatif.space` → this host; TLS cert issued (`certbot --nginx -d mcp.creatif.space`).
2. API side first: the OAuth PR is deployed and its migrations ran; Laravel `.env` has `MCP_INTROSPECT_SECRET` and `MCP_RESOURCE_URL=https://mcp.creatif.space/mcp`.
   **`MCP_RESOURCE_URL` must be exactly `https://mcp.creatif.space/mcp` on BOTH sides** (Laravel `.env` and `/etc/creatif-mcp.env`) — not the bare origin.
3. `/etc/creatif-mcp.env` (mode 600, owner root) with these **names**:
   `PORT`, `HOST`, `V1_API_URL`, `MCP_RESOURCE_URL`, `MCP_AUTHORIZATION_SERVER`,
   `MCP_INTROSPECT_SECRET` (same value as Laravel's), `MCP_AUTH_MODE` (`real`, or leave unset),
   `POSTHOG_KEY`, `POSTHOG_HOST`. Never set `MCP_AUTH_MODE=stub` here.
4. `cd /var/www/creatif.space-api && git pull && cd mcp && npm ci && npm run build && npm prune --omit=dev`
5. Install the systemd unit below, `systemctl daemon-reload && systemctl enable --now creatif-mcp`; on later deploys `systemctl restart creatif-mcp`.
6. Install the nginx vhost below, `nginx -t && systemctl reload nginx`.
7. Smoke:
   - `curl -s https://mcp.creatif.space/health` → `{"ok":true,...}`
   - `curl -si -X POST https://mcp.creatif.space/mcp | grep -i www-authenticate` → `Bearer resource_metadata="https://mcp.creatif.space/.well-known/oauth-protected-resource/mcp"`
   - `curl -s https://mcp.creatif.space/.well-known/oauth-protected-resource/mcp` → `"resource":"https://mcp.creatif.space/mcp"`
   - `journalctl -u creatif-mcp -n 20` shows `listening on http://127.0.0.1:3010 (auth: real)`
8. In Claude: Settings → Connectors → Add custom connector → `https://mcp.creatif.space/mcp`, sign in, run `list_channels`.

## Run in production

Build once per deploy: `npm ci && npm run build` (then `npm prune --omit=dev`).

### systemd — `/etc/systemd/system/creatif-mcp.service`

```ini
[Unit]
Description=creatif.space MCP server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/creatif.space-api/mcp
EnvironmentFile=/etc/creatif-mcp.env
Environment=NODE_ENV=production
ExecStart=/usr/bin/node dist/index.js
Restart=always
RestartSec=3
NoNewPrivileges=true
ProtectSystem=full

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now creatif-mcp
```

### or pm2 — `mcp/ecosystem.config.cjs`

```js
module.exports = {
  apps: [{
    name: 'creatif-mcp',
    cwd: '/var/www/creatif.space-api/mcp',
    script: 'dist/index.js',
    env: { NODE_ENV: 'production', PORT: 3010 }, // secrets via the shell env / .env loader
    max_memory_restart: '300M',
  }],
};
```

### nginx — `mcp.creatif.space`

SSE-safe: no buffering, long read timeout, HTTP/1.1 to the upstream.

```nginx
server {
    listen 443 ssl http2;
    server_name mcp.creatif.space;

    ssl_certificate     /etc/letsencrypt/live/mcp.creatif.space/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/mcp.creatif.space/privkey.pem;

    client_max_body_size 2m;

    location / {
        proxy_pass http://127.0.0.1:3010;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # Streamable HTTP / SSE
        proxy_buffering off;
        proxy_cache off;
        chunked_transfer_encoding on;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        add_header X-Accel-Buffering no;
    }
}

server {
    listen 80;
    server_name mcp.creatif.space;
    return 301 https://$host$request_uri;
}
```

Check: `curl -s https://mcp.creatif.space/health` and
`curl -si -X POST https://mcp.creatif.space/mcp | grep -i www-authenticate`.
