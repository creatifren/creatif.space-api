# Changelog — creatif.space MCP server

Versions of the remote MCP server at `https://mcp.creatif.space/mcp`
(`serverInfo.version`, `GET /health` → `version`, both read from
`package.json`). The `/free` server and the registry entry
(`server.json`) are versioned on their own.

## 0.3.0 — 2026-11-12

48 tools (was 11). Every description follows the agent shape (what / when to
use / example / errors → next step, #78), and every error is
`{code, message, next_step, details}` (#76).

Added (38):

- **Posts:** `list_failed_posts` (last 30 days, per channel `failure_code` + reason; points to `retry_post`), `schedule_posts` (up to 10 drafts, one preview, all or none), `discard_draft` (preview first; drafts only), `retry_post` (the failed channels of a post again, preview first; each failure with a stable `failure_code`), `suggest_caption` (AI caption + hashtags; `not_enabled` while the flag is off)
- **Approvals:** `create_content_plan` (preview → `confirm: true` creates a Content Plan and emails the approver), `send_approval_reminder`, `list_approval_links`, `revoke_approval_link` (preview → `confirm: true` revokes the link; unanswered posts come back to the user), `get_approval_audit`, `get_approval_history` (one post’s decisions, email masked), `get_plan_calendar_link` (Content Plan ICS feed, read-only); approver side `list_waiting_approvals`, `decide_approval`, `list_shared_with_me` (scope `approvals:decide`)
- **Media:** `request_media_upload`, `confirm_media_upload`, `get_storage_usage`, `keep_file`, `unkeep_file`
- **Channels:** `get_connect_link` (app link, no token); `list_channels` marks channels to reconnect
- **Insight:** `get_insight_summary` (per-channel threshold notes; previous period + change for every number), `list_top_posts` (top five by engagement), `get_best_times` (best weekday + hour from the user’s own posts; `not_enough_data` under 10 posts), `get_insight_report_link`, `get_insight_csv_link` (24-hour signed CSV link), `create_insight_share_link` (optional `recipient_email`: emailed, and listed in their `list_shared_with_me`), `revoke_insight_share_link` (scope `approve`)
- **Spaces:** `list_spaces`, `create_space_from_files`, `publish_space`
- **Engage:** `list_conversations`, `reply_to_conversation`
- **Feedback:** `send_feedback`
- **Settings:** `get_notification_settings`, `update_notification_settings` (email: post_missed_or_failed, approval_decided, weekly_digest)
- **Account:** `get_account_summary` (plan, limits left, Credits, channels, storage in one call), `get_onboarding` (the Get started checklist — connect, draft, send, publish — with done/at and the one next step to suggest)

Resources (new): `creatif://channels`, `creatif://schedule/next-7-days` (read-only, scope `read`).

Prompts (new): `plan_week` (“Plan next week”), `send_for_approval` (“Send to client for approval”), `weekly_results` (“Weekly results”: the last 7 days, or a month), `monthly_report` (“Monthly report”, kept for clients that call it by name) (`prompts/list`, `prompts/get`; names, titles and arguments snapshot-tested).

Changed:

- `MCP_PUBLIC_DISCOVERY` (off by default): `initialize` and `tools/list` without a token; `tools/call` stays behind OAuth (401 + `WWW-Authenticate`).
- `serverInfo.icons` and the registry entry's `icons`: the Pen Rocket, 512×512 PNG at `https://creatif.space/icons/icon-512.png` (registry entry 1.2.0).
- Every tool description opens with one verb-led sentence (≤ 120 chars) saying what the tool does for the user, naming the prompt (`plan_week`, `send_for_approval`, `weekly_results`) that walks through it.
- Registry entry 1.2.1: `repository` → `https://github.com/creatifren/creatif.space-api` (subfolder `mcp`). Not published from here: the registry workflow is Rizal's to run.
- Confirms of `publish_post`, `schedule_post` and `retry_post`, and `create_approval_link`, send an `Idempotency-Key` (one per confirm), so a double submit sends, charges or creates once.
- `mcp_tool_called` adds `client_surface` (claude / chatgpt / cursor / vscode / api_key / other), `code` (`ok` or the error code) and `duration_ms`; `ms` and `error` stay. Never user text.
- `test/in-step.test.ts`: the served tools are the one source of truth for the CHANGELOG count, the README Tools table, `server.json`, `docs/tool-justifications.md` and the first lines; every read-only tool has an `outputSchema` or is in `UNSTRUCTURED` on purpose.
- `/free` tools (`check_caption`, `approval_request_template`, `connect_guide`): descriptions in the agent shape (what / When to use / Example / Errors → next step, no sign-in errors), and failures — bad arguments, an unknown tool, an internal error — answer in the `{code, message, next_step, details}` envelope instead of bare text or `{"error":"internal_error"}`. The first line of each description is unchanged.
- Publishing (live, 13 Nov): a sent post whose channel stays pending 5 minutes after it was due is no longer silent. The API asks the relay first (a late result wins), otherwise cancels the relay post when nothing went out and fails the channel with `failure_code` `not_confirmed` (Credits returned, one failure email). `retry_post` describes the code.
- Blackbox P3s (13 Nov): B9 `schedule_posts` lists `invalid_input` (a post listed twice), and a test now holds every error code a tool throws itself to its description; B10 `get_credits` `features.ai_caption` is true only when `suggest_caption` will answer (the API's new `limits.ai_caption_ready`: plan and the AI switched on and set up); B11 `list_spaces` adds `visibility_means` ("private" = link only, not hidden) and the Space descriptions say so; B14 uploads without client dimensions (MCP) get width/height read from the image itself, and `get_storage_usage` / `get_account_summary` add `library_used_bytes` beside the quota figure; B15 `get_post` (and every post output) returns the per-channel captions `draft_post` took, as `captions_by_channel` and on each channel.
- Registry text (13 Nov, not published): main entry `server.json` (1.2.2) description is now "Get content approved & published faster: draft, get client approval, publish from Claude or ChatGPT." (100 characters).
- AI captions dropped (Rizal, 13 Nov): `suggest_caption` is removed (48 tools): in MCP the user’s own AI writes the caption. `plan_week` no longer names it, and `get_credits` `features.ai_caption` is the plan’s flag again (the app’s Improve with AI / Add hashtags), with no `ai_caption_ready` override. The API drops `POST /ai/caption`; `POST /social-captions/assist` stays as the only AI path, for captions of at least 20 non-whitespace characters.
- Directory prep (13 Nov, not submitted): `docs/reviewer.md` is the public page for directory reviewers (connect, sign in with the provided email and password, sample data, the 8 test prompts); `test/reviewer-doc.test.ts` keeps its prompts word for word with `docs/directory-test-pack.md` and keeps internal process, server paths and prices out of it.
- Engage privacy (P1): a disconnected channel's DMs, messages and comments are no longer listed or readable — `list_conversations` and `reply_to_conversation` (and the app's Engage) leave them out at once, and the API deletes them (retention: none; documented in `docs/logging.md`). After a reconnect only what the next sync brings in is listed. Both tools say so; an id of a disconnected channel answers `not_found`.
- `list_top_posts` and `get_insight_summary.top_posts` name `post_id` for posts that went out through creatif.space (B6): matched by the relay's post id as the Insight CSV does, since most feeds carry no external id (it was null). `get_insight_summary.top_posts` adds `post_id` and `channel_id`; a public shared report never shows either.
- A disconnected copy of an account that is also connected (hidden by `list_channels` since #204) is no longer reported as needing a reconnect anywhere: `get_account_summary` leaves it out of `needs_reconnect`, `reconnect` and `next_steps` (adds `hidden_duplicates`), `create_content_plan` previews give no reconnect warning for it, and `get_connect_link` leaves it out of `existing`.
- Approval links (B3): a post on a link (a shared draft is kept as "scheduled" with no time) now reads status `waiting_for_approval` (`changes_requested` when sent back), with `needs_time` when it has no time. `discard_draft` also deletes such a post and takes it off its link. Approving a post with no time is refused (`needs_time`, on the client page and in `decide_approval`): a yes used to publish it at once; an approved post goes out at its scheduled time, which `decide_approval` now returns.
- `approvals:decide` (bug fix, no new scope, no re-consent): the test-only stub token parser read `stub:<user>:read,approvals:decide` (no client name) as scopes `read,approvals`; it now keeps colon scopes whole. Tests pin the scope behaviour on both sides: owner scopes unchanged, a token or key without the grant gets `scope_missing` / `insufficient_scope`, and a token granted `approvals:decide` keeps it after the same app is reconnected and through a refresh.
- `list_channels` leaves out a disconnected copy of an account that is also connected (same network and username); `hidden_duplicates` counts them and `include_disconnected: true` lists them. A disconnected account with no working copy is still listed, with its reconnect step.
- `mcp/docs/telemetry.md`: HogQL over `mcp_tool_called` — p50/p95 `duration_ms` per tool, error rate per code, error codes per tool, and calls / error rate / p95 per day and client; each falls back to `ok`/`error`/`ms` for events from before `code` and `duration_ms`. `test/telemetry-doc.test.ts` checks the queries read only properties the server sends.
- Notification settings add `post_missed_or_failed` (“Post missed / failed”: a scheduled post missed its time or failed). Feature-detected: the API's own key when `GET /me/notification-settings` serves one, else the `publish_failed` switch, which covers both since API #199. `publish_failed` stays as the older name; giving both with different values is `invalid_input`.
- `list_posts` status adds `waiting` (held for approval) and `changes_requested` (sent back by the client) — approval states of queued posts, paged over the raw rows — and `from`/`to` take a date (YYYY-MM-DD, UTC; `to` covers the whole day) as well as an ISO time; `from` after `to` is `invalid_input`.
- `mcp/docs/mcp-tools.md`: every tool's title, scope, hints, description and inputs, generated from the served tool list (`npm run docs:tools`); `test/tools-doc.test.ts` fails CI when it is out of date.
- Errors audit: for every tool, an API 401, 403, 404, 409, 422 or 429 returns one plain next step — no vendor names, prices, upgrade wording or links. A 404 names the tool's own way to a valid id or filter (no generic fallback left), and a 409 names the one tool that shows where the item stands now. `test/errors-table.test.ts` runs all 47 tools against each status and pins the codes in a table.
- `get_connect_link` adds `account_needed` (one line per network on the account it needs, from the same data as `/free` `connect_guide`: what the code confirms, then what to check on the connect screen) and `account_type`, so the AI can say it before the user clicks.
- `reply_to_conversation`'s preview adds `goes_to` (network, the user's channel it is sent from, the person, direct message or public reply, the post for a comment) and a one-line `summary` with the exact text; nothing is sent without `confirm: true` on that preview.
- `get_account_summary` adds `failed_posts` (the `list_failed_posts` count, last 30 days) and `approvals` {waiting, changes_requested}, each with a next step.
- Registry text (Growth, not published): main entry description "Get content approved & published faster: draft posts, send client approval links, publish from AI."; the free tools entry is `io.github.creatifren/creatif-space-free-tools` (websiteUrl `https://creatif.space/free-tools`). Both descriptions ≤ 100 characters, pinned by `test/registry-text.test.ts`.
- Registry prep (not published): `server.json` 1.2.2 with the current tool list; `free-tools/server.json` 1.1.0 with icon, repository, its three tools and no auth; `docs/registry-publish.md` has Rizal's publish commands.
- Every `list_*` tool takes `limit` + `cursor` and returns `next_cursor` (null at the end).
- The 10 most-used read tools declare an `outputSchema` and return `structuredContent` (text block kept).
- `/free` `connect_guide` returns `account_type` per network: `confirmed` from the code, or `check in app` where the code does not prove it.

- `get_credits` adds this cycle's spend by action (`spend`) and days until renewal (`renewal`).
- `update_post` takes `add_file_ids` (keep current media) as well as `file_ids` (replace).
- CORS for browser MCP clients on `/mcp`, `/` and `/free`; Glama claim at `/.well-known/glama.json`.

## 0.2.0

11 tools: `list_channels`, `list_posts`, `get_post`, `list_files`,
`draft_post`, `get_credits`, `create_approval_link`, `check_approval_status`,
`update_post`, `schedule_post`, `publish_post`.
