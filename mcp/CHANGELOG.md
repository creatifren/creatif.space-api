# Changelog — creatif.space MCP server

Versions of the remote MCP server at `https://mcp.creatif.space/mcp`
(`serverInfo.version`, `GET /health` → `version`, both read from
`package.json`). The `/free` server and the registry entry
(`server.json`) are versioned on their own.

## 0.3.0 — 2026-11-12

36 tools (was 11). Every description follows the agent shape (what / when to
use / example / errors → next step, #78), and every error is
`{code, message, next_step, details}` (#76).

Added (25):

- **Posts:** `discard_draft` (preview first; drafts only), `suggest_caption` (AI caption + hashtags; `not_enabled` while the flag is off)
- **Approvals:** `send_approval_reminder`, `list_approval_links`, `get_approval_audit`, `get_plan_calendar_link` (Content Plan ICS feed, read-only); approver side `list_waiting_approvals`, `decide_approval`, `list_shared_with_me` (scope `approvals:decide`)
- **Media:** `request_media_upload`, `confirm_media_upload`, `get_storage_usage`, `keep_file`, `unkeep_file`
- **Channels:** `get_connect_link` (app link, no token); `list_channels` marks channels to reconnect
- **Insight:** `get_insight_summary`, `get_insight_report_link`, `create_insight_share_link`, `revoke_insight_share_link` (scope `approve`)
- **Spaces:** `list_spaces`, `create_space_from_files`, `publish_space`
- **Engage:** `list_conversations`, `reply_to_conversation`
- **Feedback:** `send_feedback`

Resources (new): `creatif://channels`, `creatif://schedule/next-7-days` (read-only, scope `read`).

Prompts (new): `plan_week`, `send_for_approval`, `monthly_report` (`prompts/list`, `prompts/get`).

Changed:

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
