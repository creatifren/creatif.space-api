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
| `GET /.well-known/oauth-protected-resource` | RFC 9728 metadata: `resource: "https://mcp.creatif.space/mcp"`, `authorization_servers: ["https://api.creatif.space"]` |
| `GET /.well-known/oauth-protected-resource/mcp` | the same document at the RFC 9728 path form |

No / invalid token → `401` with
`WWW-Authenticate: Bearer resource_metadata="https://mcp.creatif.space/.well-known/oauth-protected-resource/mcp"` (RFC 9728 path form, built from the origin; the root well-known serves the same document).

## Auth

Tokens (Passport OAuth 2.1 access tokens or `cs_live_…` API keys) are checked with
`POST {V1_API_URL}/oauth/introspect`, header `X-MCP-Secret`, body `{token}` →
`{active, user_ulid, scopes[], client_id, client_name, token_type, exp}`.
Answers are cached 60 s (never past `exp`). The same bearer is then forwarded
to V1 routes (guard `mcp`, built in the OAuth PR).

Scopes (`scopes_supported`): `read`, `draft`, `approve`, `publish` — the same
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
| `create_approval_link` (post_ids, title, client_name, expires_in_hours) — Pro/Max | approve | `POST /social-approval-links` (drafts accepted) |
| `check_approval_status` (post_id \| link_id) | read | `GET /social-posts/{id}` or `GET /social-approval-links/{id}` |
| `update_post` (caption, scheduled_at, labels) | draft (+ publish if already queued) | `PATCH /social-posts/{id}` |
| `schedule_post` (post_id, scheduled_at, confirm) | publish | preview `GET /social-posts/{id}/send-preview`; `confirm: true` → `POST /social-posts/{id}/send` |
| `publish_post` (post_id, confirm) | publish | same, sends now |

`schedule_post` / `publish_post` without `confirm: true` only return a preview
(channels, Credit cost, sends left today, anything blocking). Sends from a bearer
token are capped at **20 per user per UTC day**, enforced in Laravel
(`CapTokenSends`, 429 `daily_send_cap`); the app's cookie session is never capped.

Errors come back as tool results with `isError: true` and
`{error: {code, message, hint, details?}}`; codes: `reconnect_required`,
`scope_missing`, `plan_required`, `not_found`, `invalid_input`, `unauthorized`,
`forbidden`, `rate_limited`, `daily_cap_reached`, `insufficient_credits`,
`upstream_unavailable`.

Each call emits PostHog `mcp_tool_called` `{tool, client_name, ok, ms}` (plus
`error` code on failure) — never content. Skipped when `POSTHOG_KEY` is unset.

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
