# creatif.space MCP server

Remote [MCP](https://modelcontextprotocol.io) server for creatif.space, served at
`https://mcp.creatif.space/mcp` (also `/`). Streamable HTTP, stateless, Node 22,
official `@modelcontextprotocol/sdk`.

It is a thin layer: every tool calls the existing **V1 API** with the caller's
own bearer token, so ownership, plans, Credits and validation stay in Laravel.
No business logic lives here.

## Endpoints

| Path | What |
| --- | --- |
| `POST /mcp` (and `POST /`) | MCP JSON-RPC (bearer required) |
| `GET /health` | Liveness, no auth |
| `GET /.well-known/oauth-protected-resource` | RFC 9728 metadata → `authorization_servers: ["https://api.creatif.space"]` |

No / invalid token → `401` with
`WWW-Authenticate: Bearer resource_metadata="https://mcp.creatif.space/.well-known/oauth-protected-resource"`.

## Auth

Tokens (Passport OAuth 2.1 access tokens or `cs_live_…` API keys) are checked with
`POST {V1_API_URL}/oauth/introspect`, header `X-MCP-Secret`, body `{token}` →
`{active, user_ulid, scopes[], client_id, client_name, token_type, exp}`.
Answers are cached 60 s (never past `exp`). The same bearer is then forwarded
to V1 routes (guard `mcp`, built in the OAuth PR).

Scopes per tool: `read` for every list/get tool, `draft` for `draft_post`.
Rate limit: 60 requests / minute / token (429 + `Retry-After`).

## Tools (M1)

| Tool | Scope | V1 endpoint |
| --- | --- | --- |
| `list_channels` | read | `GET /social-accounts` |
| `list_posts` (status, from, to, channel_id, limit) | read | `GET /social-posts?status&from&to&account_id&limit` |
| `get_post` | read | `GET /social-posts/{id}` |
| `list_files` (search, type, folder_id, limit) | read | `GET /files` |
| `draft_post` (caption, channel_ids, file_ids, media_urls, captions_by_channel, type) | draft | `POST /social-posts` with `draft: true` |
| `get_credits` | read | `GET /credits` |

Errors come back as tool results with `isError: true` and
`{error: {code, message, hint, details?}}`; codes: `reconnect_required`,
`scope_missing`, `plan_required`, `not_found`, `invalid_input`, `unauthorized`,
`forbidden`, `rate_limited`, `upstream_unavailable`.

Each call emits PostHog `mcp_tool_called` `{tool, client_name, ok, ms}` (plus
`error` code on failure) — never content. Skipped when `POSTHOG_KEY` is unset.

## Environment

| Var | Default | Notes |
| --- | --- | --- |
| `PORT` | `3010` | |
| `HOST` | `127.0.0.1` | nginx in front |
| `V1_API_URL` | `https://api.creatif.space/api/v1` | |
| `MCP_RESOURCE_URL` | `https://mcp.creatif.space` | protected-resource id |
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
