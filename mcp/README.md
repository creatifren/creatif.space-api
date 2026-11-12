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
| `GET /health` | Liveness, no auth: `{ok, name, version}` — the same version as `serverInfo` (from `package.json`; see `CHANGELOG.md`) |
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
| `list_channels` — channels that need reconnecting carry `reconnect` {reason, next_step} and are listed in `needs_reconnect` | read | `GET /social-accounts` |
| `list_posts` (status, from, to, channel_id, limit) | read | `GET /social-posts?status&from&to&account_id&limit` |
| `list_failed_posts` (limit, cursor) — last 30 days, per channel `failure_code` + `reason`; partly-published posts list only their failed channels | read | `GET /social-posts?status=failed` + `?status=published` (from/to = last 30 days) |
| `get_post` | read | `GET /social-posts/{id}` |
| `list_files` (search, type, folder_id, limit) | read | `GET /files` |
| `draft_post` (caption, channel_ids, file_ids, media_urls, captions_by_channel, type) | draft | `POST /social-posts` with `draft: true` |
| `get_account_summary` — onboarding in one call: plan, limits used/left, Credits left, channels connected / needing reconnect, storage used, next_steps | read | `GET /credits`, `GET /social-accounts`, `GET /files/usage` |
| `get_credits` — plan, limits, Credits; `spend` (this cycle by action: publish, reply, ai) and `renewal` (resets_at, days_until) | read | `GET /credits` (`limits.spend`) |
| `create_approval_link` (post_ids — or a Content Plan: date_from, date_to, timezone, channel_ids — **approver_email** (required — the client signs in with it to decide), title, client_name, expires_in_hours) — every plan; Free: one client (by approver_email), 7-day window, 10 queued | approve | `POST /social-approval-links` (drafts accepted) |
| `create_content_plan` (date_from, date_to, timezone, channel_ids, **approver_email**, title, client_name, expires_in_hours, confirm) — preview first; `confirm: true` creates the Content Plan and emails the approver | approve | `GET /social-accounts`, `GET /social-posts?from&to` (preview); `confirm: true` → `POST /social-approval-links` with `notify_approver: true` |
| `check_approval_status` (post_id \| link_id) | read | `GET /social-posts/{id}` or `GET /social-approval-links/{id}` |
| `send_approval_reminder` (link_id) | approve | `POST /social-approval-links/{id}/remind` (one per link per 12 h) |
| `update_post` (caption, scheduled_at, labels, file_ids \| add_file_ids) | draft (drafts and posts waiting for approval only) | `PATCH /social-posts/{id}` |
| `schedule_posts` (posts: [{post_id, scheduled_at}] up to 10, confirm) — one preview of every post + total Credits; `confirm: true` schedules all or none | publish | previews `GET /social-posts/{id}/send-preview` each; `confirm: true` → `POST /social-posts/send-batch` (checks all first; a refusal takes back the ones already sent) |
| `schedule_post` (post_id, scheduled_at, confirm) | publish | preview `GET /social-posts/{id}/send-preview`; `confirm: true` → `POST /social-posts/{id}/send` |
| `publish_post` (post_id, confirm) | publish | same, sends now |
| `retry_post` (post_id, confirm) — the failed channels of a post again; preview shows each `failure_code` and reason | publish | preview `GET /social-posts/{id}/retry-preview`; `confirm: true` → `POST /social-posts/{id}/retry` |
| `get_insight_summary` (period: {month} \| {from, to} ≤ 92 days, channel_ids) — Pro / Max; Free → `plan_required` | read | `GET /insight/summary?month \| from&to&channel_ids` |
| `list_top_posts` (period: {month} \| {from, to} ≤ 92 days, channel_ids, limit, cursor) — Pro / Max; Free → `plan_required` | read | `GET /insight/top-posts?month \| from&to&channel_ids` |
| `get_best_times` (days 7–92, channel_ids) — Pro / Max; Free → `plan_required` | read | `GET /insight/best-times?days&channel_ids` |
| `get_insight_csv_link` (from, to ≤ 366 days, channel_ids) — Pro / Max; Free → `plan_required` | read | `POST /insight/export-csv-link` |
| `get_insight_report_link` (from, to ≤ 92 days, format onepage\|deck\|details, channel_ids, locale) — a 15-minute signed download link to the Insight PDF (one page, 16:9 deck or detailed); Pro / Max | read | `POST /insight/export-link` |
| `create_insight_share_link` (from, to ≤ 92 days, channel_ids, recipient_email) — a read-only web link to an Insight report, open without an account for 30 days; with `recipient_email` that person is emailed once and sees it in `list_shared_with_me` | approve | `POST /insight/share` |
| `revoke_insight_share_link` (share_id) — turns a shared Insight link off at once | approve | `DELETE /insight/share/{id}` |
| `request_media_upload` (filename, mime, size_bytes, folder_id) | draft | `POST /files/presign` → file_id + presigned PUT URL (15 min) |
| `confirm_media_upload` (file_id) | draft | `POST /files/{id}/complete` (409 → `GET /files/{id}`: already ready is returned) |
| `list_conversations` (type comment\|dm, channel_id, unread_only, since, limit, cursor) — Engage comments and DMs in one list, newest first | read | `GET /inbox/feed?type&account&unread&since&per_page&cursor` |
| `reply_to_conversation` (conversation_id, text, confirm) — DM into the thread, or public reply under a comment | publish | preview `GET /inbox/feed/{id}`; `confirm: true` → `POST /inbox/feed/{id}/reply` |
| `get_storage_usage` — used vs quota (Free: 1 GB total; paid: Keep quota) and kept vs auto-delete (soonest dates), from the newest 300 files | read | `GET /files/usage` + `GET /files?per_page=60&page=1..5` |
| `keep_file` (file_id) — Pro/Max; Free → `plan_required`, quota full → `storage_full`, not ready (409) → `file_not_ready` | draft | `POST /files/{id}/keep` |
| `unkeep_file` (file_id) — back under the retention rules; returns `auto_delete_at` | draft | `DELETE /files/{id}/keep` |
| `suggest_caption` (brief, platform) — a caption and hashtags in the user's style; saves nothing. Pro/Max; 404 while `AI_CAPTION_ENABLED` is off → `not_enabled` ("write the caption yourself"), Free → `plan_required` | draft | `POST /ai/caption` |
| `discard_draft` (post_id, confirm) — deletes a draft only; preview first, `confirm: true` deletes; scheduled / published / failed → `not_draft` with the next step | draft | `GET /social-posts/{id}`, then `DELETE /social-posts/{id}/draft` |
| `list_approval_links` (status active\|expired\|revoked\|all, limit) — status, approver email, post count, approved/waiting/revision | read | `GET /social-approval-links` |
| `list_waiting_approvals` — posts other users sent to this user for approval | approvals:decide | `GET /me/approvals/waiting` |
| `decide_approval` (link, post_id, decision approve\|revise, reason, confirm) — the approver side; same rules as the approval page, audited as `mcp` | approvals:decide | preview `GET /me/approvals/{post}/preview`; `confirm: true` → `POST /me/approvals/{post}/decision` |
| `list_spaces` (status draft\|published\|archived, search, limit) — Pro/Max | read | `GET /spaces?tab&search` |
| `create_space_from_files` (title, file_ids 1–100, view_mode) — always a Draft+Private Space, never published; Pro/Max | draft | `POST /spaces` with `file_ids` (checked before anything is created) |
| `publish_space` (space_id, confirm) — Pro/Max | publish | preview `GET /spaces/{id}/publish-preview`; `confirm: true` → `POST /spaces/{id}/publish` |
| `get_approval_history` (post_id) — every decision on one post, oldest first: who (email masked), when, decision, comment | read | `GET /social-posts/{id}/approval-audit` |
| `get_plan_calendar_link` (link_id) — ICS feed URL of a Content Plan (scheduled posts, first 60 characters of the caption); read-only, stable URL | read | `GET /social-approval-links/{id}/calendar` |
| `get_approval_audit` (link_id) — every decision on one of the user's links: who, post, when, decision, comment; rows and CSV | read | `GET /social-approval-links/{id}/audit.csv` |
| `send_feedback` (kind bug\|idea\|other, message, page) — to the creatif.space team; nothing public, no Credits; 10 an hour per user | draft | `POST /feedback` |
| `get_notification_settings` — which emails the user gets: publish_failed, approval_decided, weekly_digest | read | `GET /me/notification-settings` |
| `update_notification_settings` (publish_failed, approval_decided, weekly_digest: true/false; timezone of the Monday 08:00 digest; at least one) | draft | `PATCH /me/notification-settings` |
| `list_shared_with_me` — "Reports shared with you": Content Plans and Insight reports other users shared with this user's email | approvals:decide | `GET /me/reports/shared` |
| `get_connect_link` (network) — the app's connect page (`https://creatif.space/social/connect?network=…`) with the steps; no token in the response; a new network on a full plan → `plan_required` | read | `GET /social-accounts` + `GET /credits` (never `POST /social-accounts/auth-url`) |

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

**Spaces.** Pro and Max only for connected apps: every Spaces route a token
can reach is listed in `config('mcp.route_features')`, so a Free caller gets
the standard 402 `plan_required` (`feature: "spaces"`) from `EnforceTokenScope`
before the controller runs — the app itself still lists a Free user's existing
Spaces. `publish_space` works like `publish_post`: the preview returns a
`confirm_token` (HMAC over user + Space + title, address, visibility, password,
expiry and files; 10 minutes) that this server keeps and sends on
`confirm: true`; a token publish without one → 422 `confirm_token_required`,
a changed Space → 409 `preview_stale`. The preview also says what blocks it
(`active_slots_full`) and what to warn about (`no_handle`: no address yet,
`empty`, `expired`). A Space's link is `{public_url}/{handle}/{slug}`
(`public_url` on the Space resources; null without a handle).

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
`not_draft`, `not_approvable`, `not_a_plan`, `link_inactive`, `approval_required`, `storage_full`,
`file_too_large`, `unsupported_format`, `upload_limit`, `upload_incomplete`,
`file_not_ready`, `upstream_unavailable`, `internal_error`, and for Engage
replies `reply_window_closed`, `reply_rejected`, `reply_not_possible`,
`relay_unavailable`.

Each call emits PostHog `mcp_tool_called` `{tool, client_name, ok, ms}` (plus
`error` code on failure) — never content. Skipped when `POSTHOG_KEY` is unset.

**Not an MCP tool, on purpose: the account data export.** `POST /api/v1/me/export`
(a ZIP of the whole workspace, emailed as a 24-hour link) works only from a
signed-in creatif.space session. It is not in `mcp.routes`, so an OAuth or API
token gets 403. A full copy of an account is not something to hand an AI
client.

## Approve from your AI chat

For the **client** — the person a creatif.space user sent posts or a Content
Plan to for approval. They connect creatif.space to Claude or ChatGPT with
their own account (the email the link was sent to), allow **Decide approvals**
(`approvals:decide`) on the consent screen, and can then review and decide
without opening the approval page.

| Tool | What it does |
|---|---|
| `list_shared_with_me` | "Reports shared with you": Content Plans and Insight reports shared with the user's verified email — title, dates, who sent it, link (and, for plans, how many posts wait). |
| `list_waiting_approvals` | Every post still waiting on the user's decision, with the link id and post id to decide with. |
| `decide_approval` | Approve, or request changes with a reason. Always shows a preview first; decides only with `confirm: true` after the user agrees. |

The owner's side has two more (scope `read`): `get_approval_audit` — who
decided what and when, with the comment, as rows and CSV, for one of the
owner's own links — and `get_approval_history` — the same for one post,
across every link it was on, with the approver's email masked.

Rules the tools keep (the API enforces them; the tools only explain them):

- Only links addressed to the user's **verified email**. Anyone else gets
  `forbidden` with a hint of the expected address; an id from another
  workspace is `not_found`.
- Two steps for every decision: preview, then confirm. The confirm carries a
  signed token (10 minutes) over the exact post the user saw; if the post was
  edited in between, the answer is `preview_stale` with the new preview and
  nothing is decided. The token never reaches the model.
- Same rules as the approval page: the post must be in the plan's dates and
  channels and still waiting; on a Free sender's plan only the next 7 days
  can be decided (`invalid_input` with `decidable_from`).
- Every decision is audited with `source: mcp`, and the sender is notified
  exactly as from the page.
- A day before the first waiting post goes out, the approver gets one
  reminder email (once per plan; they can turn it off from the email, or the
  owner with `PATCH /social-approval-links/{id}` `{reminders: false}`).

### Example conversation

> **Rani:** Anything I need to approve this week?
>
> *Claude calls `list_shared_with_me` and `list_waiting_approvals`.*
>
> **Claude:** Bro shared one Content Plan with you, **December**
> (1–31 Dec, Asia/Jakarta). Two posts are waiting for you:
> 1. "Launch day — 20% off everything…" — Instagram, Wed 2 Dec 09:00
> 2. "Behind the scenes at the studio" — Instagram, Fri 4 Dec 18:00
>
> **Rani:** Approve the first one. For the second, ask them to use the photo
> with the team in it.
>
> *Claude calls `decide_approval` with `decision: "approve"` for post 1 and
> `decision: "revise"`, `reason: "Please use the photo with the team in it"`
> for post 2 — both without `confirm`, so both come back as previews.*
>
> **Claude:** Here's what I'll do:
> - **Approve** "Launch day…" — it will go out on Instagram on Wed 2 Dec at 09:00.
> - **Request changes** on "Behind the scenes…", telling Bro: "Please use
>   the photo with the team in it."
>
> Shall I go ahead?
>
> **Rani:** Yes.
>
> *Claude calls `decide_approval` again with the same arguments and
> `confirm: true`, once per post.*
>
> **Claude:** Done. "Launch day…" is approved and goes out Wednesday 09:00;
> Bro has your note on "Behind the scenes…". Nothing else is waiting.

And on the owner's side, later:

> **Bro:** Who approved the December launch post, and when?
>
> *Claude calls `get_approval_audit` with the plan's link id.*
>
> **Claude:** Rani (rani@client.test) asked for changes on "Behind the
> scenes…" on 1 Dec at 03:10 UTC ("Please use the photo with the team in it")
> and approved "Launch day…" at 03:10 UTC, both from an AI chat. Want the
> CSV?

## Tool annotations

Every tool has a human `title` and sets the four MCP hints as explicit
booleans. `list_*`, `get_*` and `check_*` are `readOnlyHint`. Anything that
posts publicly, deletes, revokes or records a decision that cannot be taken
back is `destructiveHint`: `publish_post`, `schedule_post`, `schedule_posts`,
`reply_to_conversation`, `retry_post`, `publish_space`, `discard_draft`,
`unkeep_file`, `decide_approval`, `revoke_insight_share_link`. Only tools that
post publicly (a social network or the public web) are `openWorldHint`:
`publish_post`, `schedule_post`, `schedule_posts`, `reply_to_conversation`,
`retry_post`, `publish_space`. One line per tool, for directory review forms,
is in [`docs/tool-justifications.md`](docs/tool-justifications.md).
`test/annotations.test.ts` checks these rules and that file, and pins every
tool's hints in a snapshot.

## Plan errors and ChatGPT

No tool description names prices, plan tiers or upgrading, for any client
(`test/no-upsell.test.ts`). When the OAuth client is ChatGPT — the API's
introspection says `client_surface: "chatgpt"` (DCR name or a
`chatgpt.com` / `openai.com` redirect host), or the client name says
ChatGPT / OpenAI — any 402 or `plan_required` becomes:

```json
{ "error": { "code": "not_available_on_plan", "message": "This isn’t available on this account’s plan.", "next_step": "…no plan change or purchase…", "details": { "tool": "…" } } }
```

with no upgrade link or pricing step; other errors lose billing / top-up
links. Other clients get the API's message and links unchanged
(`test/chatgpt-surface.test.ts`).

## Rate limits (429)

Every tool turns an API 429 into the same envelope:
`{code: 'rate_limited', message, next_step, details: {retryable: true, retry_after_seconds}}`.

- **`message`:** the API's own text.
- **`retry_after_seconds`:** taken from the body's `retry_after`, else the `Retry-After` header (the token limit in `EnforceTokenScope` sends only the header), else 60.

Two 429s keep their own codes: `upload_limit` (uploads paused for today) and `daily_cap_reached` (20 sends a day from connected apps). `test/ratelimit.test.ts` sends a 429 to every tool.

## Idempotency (double submit)

The API runs a `POST` that carries an `Idempotency-Key` once per user and
key for 24 hours. A repeat gets the first answer back (with
`Idempotent-Replayed: true`) and does nothing again. This applies to
publish/schedule (`/send`), `/retry` and approval-link create. The server
sends one key per confirm:

- `publish_post`, `schedule_post` and `retry_post` derive the key from the
  confirmed preview's token. A confirm sent twice is one send and one charge.
- `create_approval_link` derives the key from its arguments within a
  10-minute window. Asking for the same link twice makes it once.

The key is a hash: it never carries the token or the arguments. Only a 2xx
answer is kept, so a refused confirm can be sent again once its cause is fixed.

## Pagination

Every `list_*` tool pages the same way. It takes `limit` and `cursor`, and
returns `next_cursor` (null when there is no more). Pass `next_cursor` back
as `cursor`, with the same filters, for the next page.

- **Opaque cursor:** it holds the tool, the position and a fingerprint of the
  filters. A cursor from another list, or reused with other filters, is
  refused as `invalid_input` with the next step "start again without cursor".
- **`list_posts`:** pages with `limit` + `offset` on `GET /social-posts`, and
  asks for one extra row to know whether another page exists.
- **`list_files`:** follows the API's page numbers. The cursor keeps the page
  size of the first page.
- **`list_conversations`:** uses the inbox's own cursor.
- **The rest:** the API answers whole, and the server pages it.

`src/tools/paging.ts`; tests in `test/pagination.test.ts`.

## Structured output

`list_channels`, `list_posts`, `get_post`, `get_credits`,
`list_waiting_approvals`, `check_approval_status`, `get_insight_summary`,
`list_files`, `list_spaces` and `get_storage_usage` declare an `outputSchema`
and return `structuredContent`. The same JSON is still in the text block for
older clients. The schemas (`src/tools/outputs.ts`) name and type the fields
agents rely on and let new fields through, so a field the API adds never
fails a call. Error results carry no `structuredContent`; the error envelope
is in the text.

## Prompts

`prompts/list` / `prompts/get` offer three ready-made workflows. Each is one
user message that walks the model through existing tools only (every send
still goes through the tool's own preview + `confirm`); user-typed arguments
are quoted and marked as data. Defined in `src/prompts.ts`.

| Prompt | Arguments | Tools it uses |
| --- | --- | --- |
| `plan_week` — draft a week of posts for a channel (drafts only) | channel_id*, week_start, theme, posts (1–7) | `list_channels`, `list_posts`, `suggest_caption`, `draft_post` |
| `send_for_approval` — bundle drafts into one approval link | approver_email*, post_ids (comma-separated), client_name | `list_posts`, `get_post`, `create_approval_link`, `check_approval_status` |
| `monthly_report` — Insight summary + share link | month (YYYY-MM), share (yes\|no) | `get_insight_summary`, `create_insight_share_link` |

## Registry metadata

- `mcp/server.json`: the official MCP registry entry (`io.github.creatifren/creatif-space`, remote `https://mcp.creatif.space/mcp`, entry version `1.1.0`). Its publisher-provided `_meta` lists the OAuth details (authorization server, protected-resource metadata, scopes), every tool, every prompt and the server version.
- `glama.json` (repo root): Glama maintainers, the same owner as the registry namespace.
- `test/registry.test.ts` keeps all of these in step with the server: the same tools, prompts, scopes and version; the same tool count in the README Tools table; only schema fields.
- **Publishing is manual and not done here:** `.github/workflows/mcp-registry.yml` runs only on `workflow_dispatch`. Publishing is a public act, done by Rizal.

## Resources

`resources/list` and `resources/read` offer two read-only JSON resources, scope `read`. Defined in `src/resources.ts`.

| URI | What | Same shape as |
| --- | --- | --- |
| `creatif://channels` | Connected channels, which ones need reconnecting | `list_channels` (limit 100) |
| `creatif://schedule/next-7-days` | Posts scheduled from now to now + 7 days (up to 100), soonest first, with `{from, to, count}` | `list_posts` status `scheduled` |

- **Errors:** a missing scope or an API error is a JSON-RPC error whose `data` is the usual `{code, message, next_step, details}` envelope.
- **Audit:** API calls carry `X-MCP-Tool: resource.channels` / `resource.schedule`, so they appear in the audit log and `mcp:usage`.

## Free Tools (`/free`)

A second, stateless MCP server on the same process: `check_caption`,
`approval_request_template`, `connect_guide`. No sign-in, no user data
stored; each call sends PostHog `free_tool_called {tool, network}` (no
caption text) with the existing `POSTHOG_KEY`. Copy and limits live in
`free-tools/*.json` (`caption-rules.json`, `approval-templates.json` keyed by
niche, `connect-guides.json` with `file:line` evidence that is never sent to
callers). `connect_guide` also returns `account_type`: each item is
`confirmed` (cites the code) or `check in app` (the code does not prove it,
so nothing is guessed). Registry entry `free-tools/server.json`
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
| `OPENAI_APPS_CHALLENGE` | — | OpenAI Apps domain-verification token, served as `text/plain` at `GET /.well-known/openai-apps-challenge`; unset → 404 |
| `MCP_PUBLIC_DISCOVERY` | off | `true` lets `initialize`, `notifications/initialized`, `ping` and `tools/list` answer **without a token** (registry crawlers, client previews; 30/min per IP). `tools/call` and everything else still get the 401 + `WWW-Authenticate` (`resource_metadata`) sign-in challenge; a request with a token always takes normal auth. Leave off until QA has verified it. |

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

## Usage report (daily launch check)

On the API host: `php artisan mcp:usage --days=7` (1–90; add `--json` for a
machine-readable copy). From `mcp_audit_logs`: API calls per tool, errors
(HTTP ≥ 400), error rate and the top error codes (status mapped to the MCP
code the model sees: 402 → `plan_required`, 404 → `not_found`, 5xx →
`upstream_unavailable`…). Aggregates only — no user, token, IP or client
name. It counts API requests, not tool calls (one tool can make several),
and calls refused inside this server (bad arguments, missing scope) never
reach the API, so they are not in it.

Latency: p50 / p95 per tool for the last 24 hours (plus timed calls and
errors), from `mcp_audit_logs.duration_ms`. That is the API's own time to
answer, written by `EnforceTokenScope`. Rows from before that column existed
have no timing and are left out. Sorted slowest p95 first; in `--json` it is
`latency_24h`.

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
