# Reviewer flow smoke (QA)

A command-line check of what a directory reviewer does first, run by QA
against staging or production after a deploy and before submission:

1. sign in as the directory reviewer (`POST /auth/reviewer/login`);
2. mint a short-lived API key (scopes `read`, `draft`, `approve`);
3. over MCP: `tools/list` → `list_channels` → `draft_post` (a draft only) →
   `create_content_plan` with `confirm: false` (a preview);
4. clean up: `discard_draft` the smoke draft, revoke the key.

**Nothing is published, scheduled, sent, emailed or charged.** The only
writes are one draft and one API key, and both are removed at the end, also
after a failure. The OAuth consent flow itself is checked by hand in Claude
and ChatGPT ([`directory-review.md`](directory-review.md)); this script uses
an API key so it can run unattended.

## Run

Needs Node 22 and `npm ci` in `mcp/`. The account must already be a reviewer
(`php artisan reviewer:grant`) with one connected channel (test pack sample
data).

```bash
cd mcp
SMOKE_EMAIL=reviewer@example.com node scripts/reviewer-smoke.mjs
```

The password is asked for with a hidden prompt (or taken from
`SMOKE_PASSWORD` in CI). **Never put it in a file, a ticket or a command
line.** The script prints neither the password nor the key.

| Variable | Default |
| --- | --- |
| `SMOKE_EMAIL` | — (required) |
| `SMOKE_PASSWORD` | prompted |
| `SMOKE_API` | `https://api.creatif.space` |
| `SMOKE_MCP` | `https://mcp.creatif.space/mcp` |
| `SMOKE_ORIGIN` | `https://app.creatif.space` (the frontend origin the API trusts for sessions) |

For staging, point the three URLs at the staging hosts.

## Reading the result

Each step prints `PASS` or `FAIL` with a reason; exit code 0 means all
passed. Typical failures:

| Step | Likely cause |
| --- | --- |
| 1 sign-in | wrong password, the flag was revoked, or rate limited (5 / 15 min per address) |
| 2 API key | `SMOKE_ORIGIN` is not one of the API's stateful domains (session not used), or 10 active keys already |
| 3b list_channels | the reviewer account has no connected channel |
| 3c / 3d | the API answered an error: the message names it |
| 4a / 4b | clean-up failed: delete the `[reviewer smoke]` draft and the "directory reviewer smoke" key in creatif.space by hand |

`mcp/test/reviewer-smoke.test.ts` runs the script in CI against a fake API
and the stub MCP server.
