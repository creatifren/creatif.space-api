# Logs: what is kept, and for how long

## Redaction (every channel)

Every writing log channel in `config/logging.php` (`single`, `daily`,
`monthly`, `webhooks`, `stderr`, `syslog`, `errorlog`, `papertrail`,
`slack`) is tapped with `App\Logging\RedactSensitiveData`, which runs
`App\Logging\Redactor` over each record's message, context and extra:

- **Emails** anywhere in a string → `[email]`.
- **Tokens and keys** → `[token]` / `[redacted]`: `Bearer …`, JWTs
  (access / ID tokens), `cs_live_…` API keys, long hex secrets (refresh
  tokens), and `code=`, `token=`, `access_token=`, `signature=`… in URLs.
- **Fields named for people or what they wrote** are replaced whole with
  `[redacted]`: `name`, `author`, `display_name`, `client_name`, `email`,
  `*_email`, `caption`, `text`, `body`, `comment`, `dm`, `reply`,
  `feedback`, `note`, `suggestion`, and secrets (`password`, `token`,
  `*_token`, `secret`, `authorization`, `cookie`, `signature`…).
- **Exceptions** in the context become their class, redacted message,
  file:line and redacted trace.

Ids (ULIDs, numeric ids), error codes, statuses, counts, platforms and IPs
stay, so a line still says what happened. Tests:
`tests/Unit/Logging/RedactorTest.php`, `tests/Feature/Logging/LogRedactionTest.php`.

The MCP server (`mcp/`) logs nothing per request (only its start-up line);
tool calls are recorded by the API in `mcp_audit_logs` (tool, route, status,
timing — no arguments or results).

## Retention

| Where | What | Kept |
| --- | --- | --- |
| `storage/logs/laravel.log` (`LOG_STACK=daily`) | application log | `LOG_DAILY_DAYS`, default 14 days |
| `storage/logs/laravel.log` (`LOG_STACK=single`) | application log | until rotated by the server's logrotate — use `daily` in production |
| `storage/logs/webhooks-*.log` | platform webhooks | 14 days |
| `mcp_audit_logs` (DB) | one row per MCP / API-key call: tool, status, timing | `mcp.audit_retention_days`, default 90 days (`PruneMcpAuditLogs`, daily 02:50) |
| `directory_reviewer_logins` (DB) | reviewer sign-in attempts, address hashed | until the account is deleted |
| `activity_log` (DB) | the user's own Activity screen | until the account is deleted |

Deleting an account removes its `mcp_audit_logs`, `activity_log` and
`directory_reviewer_logins` rows (`App\Support\AccountDeletion`).
