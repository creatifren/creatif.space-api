# Changelog — creatif.space MCP server

Versions of the remote MCP server at `https://mcp.creatif.space/mcp`
(`serverInfo.version`, `GET /health` → `version`, both read from
`package.json`). The `/free` server and the registry entry
(`server.json`) are versioned on their own.

## 0.3.0 — 2026-11-12

47 tools (was 11). Every description follows the agent shape (what / when to
use / example / errors → next step, #78), and every error is
`{code, message, next_step, details}` (#76).

Added (36):

- **Posts:** `list_failed_posts` (last 30 days, per channel `failure_code` + reason; points to `retry_post`), `schedule_posts` (up to 10 drafts, one preview, all or none), `discard_draft` (preview first; drafts only), `retry_post` (the failed channels of a post again, preview first; each failure with a stable `failure_code`), `suggest_caption` (AI caption + hashtags; `not_enabled` while the flag is off)
- **Approvals:** `create_content_plan` (preview → `confirm: true` creates a Content Plan and emails the approver), `send_approval_reminder`, `list_approval_links`, `get_approval_audit`, `get_approval_history` (one post’s decisions, email masked), `get_plan_calendar_link` (Content Plan ICS feed, read-only); approver side `list_waiting_approvals`, `decide_approval`, `list_shared_with_me` (scope `approvals:decide`)
- **Media:** `request_media_upload`, `confirm_media_upload`, `get_storage_usage`, `keep_file`, `unkeep_file`
- **Channels:** `get_connect_link` (app link, no token); `list_channels` marks channels to reconnect
- **Insight:** `get_insight_summary` (per-channel threshold notes; previous period + change for every number), `list_top_posts` (top five by engagement), `get_best_times` (best weekday + hour from the user’s own posts; `not_enough_data` under 10 posts), `get_insight_report_link`, `get_insight_csv_link` (24-hour signed CSV link), `create_insight_share_link` (optional `recipient_email`: emailed, and listed in their `list_shared_with_me`), `revoke_insight_share_link` (scope `approve`)
- **Spaces:** `list_spaces`, `create_space_from_files`, `publish_space`
- **Engage:** `list_conversations`, `reply_to_conversation`
- **Feedback:** `send_feedback`
- **Settings:** `get_notification_settings`, `update_notification_settings` (email: publish_failed, approval_decided, weekly_digest)
- **Account:** `get_account_summary` (plan, limits left, Credits, channels, storage in one call)

Resources (new): `creatif://channels`, `creatif://schedule/next-7-days` (read-only, scope `read`).

Prompts (new): `plan_week` (“Plan next week”), `send_for_approval` (“Send to client for approval”), `weekly_results` (“Weekly results”: the last 7 days, or a month; replaces `monthly_report`) (`prompts/list`, `prompts/get`).

Changed:

- `MCP_PUBLIC_DISCOVERY` (off by default): `initialize` and `tools/list` without a token; `tools/call` stays behind OAuth (401 + `WWW-Authenticate`).
- `serverInfo.icons` and the registry entry's `icons`: the Pen Rocket, 512×512 PNG at `https://creatif.space/icons/icon-512.png` (registry entry 1.2.0).
- Every tool description opens with one verb-led sentence (≤ 120 chars) saying what the tool does for the user, naming the prompt (`plan_week`, `send_for_approval`, `weekly_results`) that walks through it.
- Registry entry 1.2.1: `repository` → `https://github.com/creatifren/creatif.space-api` (subfolder `mcp`). Not published from here: the registry workflow is Rizal's to run.
- Confirms of `publish_post`, `schedule_post` and `retry_post`, and `create_approval_link`, send an `Idempotency-Key` (one per confirm), so a double submit sends, charges or creates once.
- `mcp_tool_called` adds `client_surface` (claude / chatgpt / cursor / vscode / api_key / other), `code` (`ok` or the error code) and `duration_ms`; `ms` and `error` stay. Never user text.
- `test/in-step.test.ts`: the served tools are the one source of truth for the CHANGELOG count, the README Tools table, `server.json`, `docs/tool-justifications.md` and the first lines; every read-only tool has an `outputSchema` or is in `UNSTRUCTURED` on purpose.
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
