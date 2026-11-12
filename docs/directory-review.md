# Directory review runbook

How staff prepare and run a review of the creatif.space MCP server for the
Claude Connectors Directory and the ChatGPT Apps directory. **No secrets go
in this file, in tickets or in chat**: passwords are generated or typed on
the server and handed to the reviewer through the directory's own form.
Submitting is Rizal's step; this runbook stops before it.

## 1. Before the review

| Check | Where |
| --- | --- |
| Live connector answers | `https://mcp.creatif.space/mcp` (401 + `WWW-Authenticate` without a token) |
| OAuth discovery | `https://api.creatif.space/.well-known/oauth-authorization-server` (DCR `registration_endpoint` and `client_id_metadata_document_supported`) |
| ChatGPT domain check | `OPENAI_APPS_CHALLENGE` set at deploy; `GET https://mcp.creatif.space/.well-known/openai-apps-challenge` returns the token (404 while unset) |
| Tool list | titles, hints and one line per tool: `mcp/docs/tool-justifications.md` |
| Log retention | `docs/logging.md` (recommend `LOG_STACK=daily`, 14 days, at deploy) |

## 2. The reviewer account

1. Create an ordinary account for the reviewer's address in creatif.space
   (Rizal). Put it on **Pro** with Credits, connect **one test Instagram
   channel**, and add the sample data listed in
   [`mcp/docs/directory-test-pack.md`](../mcp/docs/directory-test-pack.md#sample-data-the-reviewer-account-needs).
2. Make it a reviewer and give it a password, on the API server:

   ```bash
   php artisan reviewer:grant reviewer@example.com
   ```

   This prints a 24-character password **once**; or use `--password` to
   type one (hidden). Paste it only into the directory's submission form.
   Details: [`docs/directory-reviewer.md`](directory-reviewer.md).
3. The reviewer signs in with `POST /auth/reviewer/login` (email +
   password, no email code, no MFA), then adds the connector in Claude or
   ChatGPT and approves the normal consent screen.

## 3. What the reviewer can and cannot do

**Can** (with the scopes they approve on the consent screen):
- read the account: plan, Credits, channels, posts, files, Insight;
- draft, edit and discard drafts; upload media to the library;
- create approval links and Content Plans, send reminders;
- schedule or publish a post, reply to a comment or DM, publish a Space —
  **only after a preview and an explicit yes** (`confirm: true`); these post
  publicly on the test channel.

**Cannot**:
- change the plan, pay, buy Credits or see card details (no tool exists);
- delete the account, change its email or password, or manage the team;
- reach any other account's data (every call is scoped to the token's user and workspace);
- sign in with the password anywhere but `/auth/reviewer/login`, or once the
  flag is revoked.

## 4. Running the test pack

QA runs [`mcp/docs/directory-test-pack.md`](../mcp/docs/directory-test-pack.md)
(5 positive, 3 negative prompts) in both clients and records, per prompt: the
client, the tools called (client tool log or `mcp_audit_logs`), pass/fail and
a screenshot. A write tool called in N1–N3 is a fail.

Every reviewer sign-in attempt is in `directory_reviewer_logins` (address
hashed); every MCP call in `mcp_audit_logs` (tool, status, timing — no
content).

## 5. After the review

```bash
php artisan reviewer:grant reviewer@example.com --revoke
```

Clears the flag and the password; the account can then only sign in the
normal ways. Disconnect the connector in creatif.space → Settings → Connected
apps if the reviewer's tokens should stop at once.
