# MCP telemetry: queries

Every `/mcp` tool call sends one PostHog event, `mcp_tool_called`, from
`src/analytics.ts` (`PostHogAnalytics.toolCalled`). It holds metadata only,
never arguments, captions or results (`test/telemetry.test.ts`):

| Property | What |
|---|---|
| `tool` | The tool name, e.g. `draft_post` |
| `client_surface` | `claude`, `chatgpt`, `cursor`, `vscode`, `api_key` or `other` |
| `client_name` | The client's own name from `initialize` (e.g. `Claude`) |
| `ok` | `true` when the tool answered without an error |
| `code` | `ok`, or the error code the tool answered (`invalid_input`, `rate_limited`, …) |
| `duration_ms` | How long the call took on the server, in ms |
| `ms` | The same number under its older name |
| `error` | The error code when `ok` is false (older events) |

Events from before `code` and `duration_ms` were added (0.3.0, #168) have
only `ok`, `error` and `ms`. The queries below fall back to those, so a window
that spans the change still counts every call:

- code: `coalesce(properties.code, if(toString(properties.ok) = 'true', 'ok', coalesce(properties.error, 'unknown')))`
- duration: `toFloat(coalesce(properties.duration_ms, properties.ms))`

All four were run against the live project on 2026-11-12. Run them in PostHog → SQL (HogQL). Each has a time window: change
`INTERVAL 7 DAY` to suit. These are one-off queries, not saved catalog metrics.

## p50 / p95 duration per tool

```sql
SELECT
    properties.tool AS tool,
    count() AS calls,
    round(quantile(0.5)(toFloat(coalesce(properties.duration_ms, properties.ms)))) AS p50_ms,
    round(quantile(0.95)(toFloat(coalesce(properties.duration_ms, properties.ms)))) AS p95_ms,
    -- share of calls that answered with an error
    round(100 * countIf(coalesce(properties.code, if(toString(properties.ok) = 'true', 'ok', coalesce(properties.error, 'unknown'))) != 'ok') / count(), 1) AS error_pct
FROM events
WHERE event = 'mcp_tool_called'
  AND timestamp >= now() - INTERVAL 7 DAY
GROUP BY tool
ORDER BY calls DESC
LIMIT 100
```

## Error rate per code

Each code's share of all calls; `ok` is the success rate.

```sql
SELECT
    coalesce(properties.code, if(toString(properties.ok) = 'true', 'ok', coalesce(properties.error, 'unknown'))) AS code,
    count() AS calls,
    round(100 * count() / sum(count()) OVER (), 1) AS pct_of_all_calls,
    uniq(properties.tool) AS tools
FROM events
WHERE event = 'mcp_tool_called'
  AND timestamp >= now() - INTERVAL 7 DAY
GROUP BY code
ORDER BY calls DESC
LIMIT 100
```

## Error codes per tool

Which tool gives which error, for the codes above that are not `ok`.

```sql
SELECT
    properties.tool AS tool,
    coalesce(properties.code, coalesce(properties.error, 'unknown')) AS code,
    count() AS calls
FROM events
WHERE event = 'mcp_tool_called'
  AND timestamp >= now() - INTERVAL 7 DAY
  AND toString(properties.ok) != 'true'
GROUP BY tool, code
ORDER BY calls DESC
LIMIT 100
```

## Per day and client

Calls, error rate and p95 per day, split by client surface.

```sql
SELECT
    toDate(timestamp) AS day,
    -- older events have no client_surface
    coalesce(properties.client_surface, 'before 0.3.0') AS client_surface,
    count() AS calls,
    round(100 * countIf(coalesce(properties.code, if(toString(properties.ok) = 'true', 'ok', coalesce(properties.error, 'unknown'))) != 'ok') / count(), 1) AS error_pct,
    round(quantile(0.95)(toFloat(coalesce(properties.duration_ms, properties.ms)))) AS p95_ms
FROM events
WHERE event = 'mcp_tool_called'
  AND timestamp >= now() - INTERVAL 30 DAY
GROUP BY day, client_surface
ORDER BY day DESC, calls DESC
LIMIT 500
```

`test/telemetry-doc.test.ts` checks that every `properties.*` these queries
read is a field the server sends.
