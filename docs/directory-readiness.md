# Directory readiness (Claude Connectors Directory, ChatGPT Apps)

Status of each readiness item, where it landed, and how a reviewer (or QA)
checks it on the live service. Nothing here submits anything; submission is
Rizal's step. Runbook: [`directory-review.md`](directory-review.md).

| # | Item | Status | PR → main sha | How to verify |
| --- | --- | --- | --- | --- |
| G1 | Every tool has a title and explicit readOnly / destructive / openWorld hints; one justification line per tool | Done | [#148](https://github.com/creatifren/creatif.space-api/pull/148) `c605965`, [#158](https://github.com/creatifren/creatif.space-api/pull/158) `1bcb256` | `tools/list`: every tool has `title` and `annotations` with four booleans; openWorld only on public-posting tools. Lines in [`mcp/docs/tool-justifications.md`](../mcp/docs/tool-justifications.md). CI: `mcp/test/annotations.test.ts`. |
| G2 | ChatGPT: any 402 → neutral `not_available_on_plan`, no upgrade link; no price, plan name or upgrade wording in any tool description | Done | [#151](https://github.com/creatifren/creatif.space-api/pull/151) `6834c72`, [#171](https://github.com/creatifren/creatif.space-api/pull/171) `07c066f`, [#172](https://github.com/creatifren/creatif.space-api/pull/172) `95fa3ae`, [#177](https://github.com/creatifren/creatif.space-api/pull/177) `95c051a` | In ChatGPT, ask for Insight on a plan without it: the error reads "This isn't available on this account's plan." with no link. Descriptions: no Free / Pro / Max / prices. CI: `chatgpt-surface`, `chatgpt-402-all-tools`, `no-upsell`, `firstlines` tests. |
| G3 | Reviewer password login (staff-set flag only; others unchanged) | Done | [#152](https://github.com/creatifren/creatif.space-api/pull/152) `771a599` | `php artisan reviewer:grant <email>`; `POST /auth/reviewer/login` works for that account only and every attempt is in `directory_reviewer_logins`. See [`directory-reviewer.md`](directory-reviewer.md). |
| G4 | — | Not in this work stream | — | — |
| G5 | Log hygiene: emails, names, tokens and user text redacted; retention documented; internal ids trimmed from MCP output | Done | [#154](https://github.com/creatifren/creatif.space-api/pull/154) `c4b4590` | Any log line: addresses read `[email]`, tokens `[token]`. Retention: [`logging.md`](logging.md). |
| G6 | Directory test pack: 5 positive, 3 negative prompts for the reviewer account | Done | [#159](https://github.com/creatifren/creatif.space-api/pull/159) `423b0c2` | QA runs [`mcp/docs/directory-test-pack.md`](../mcp/docs/directory-test-pack.md) in Claude and ChatGPT. |
| G7 | — | Not in this work stream | — | — |
| G8 | `GET https://mcp.creatif.space/.well-known/openai-apps-challenge` from `OPENAI_APPS_CHALLENGE` | Done (code); value pending | [#155](https://github.com/creatifren/creatif.space-api/pull/155) `d8e4e0e` | `php artisan mcp:check-openai-challenge` (never prints the token; compares by hash if the API env has it), or `curl -i` that URL: `200 text/plain` once set, `404` until then. |
| G9 | OAuth hygiene: dead refresh token → `invalid_grant`, refresh rotation, Claude + loopback callbacks, endpoints < 10 s | Done | [#156](https://github.com/creatifren/creatif.space-api/pull/156) `a7ee620` | Reuse a refresh token after one refresh: `400 {"error":"invalid_grant"}`. CI: `OAuthHygieneTest`. |
| G10 | OAuth Client ID Metadata Documents next to DCR | Done | [#165](https://github.com/creatifren/creatif.space-api/pull/165) `376125e` | `/.well-known/oauth-authorization-server` has `client_id_metadata_document_supported: true` and `registration_endpoint`. CI: `ClientMetadataDocumentTest`. |

Supporting docs: reviewer runbook [#174](https://github.com/creatifren/creatif.space-api/pull/174) `b133474`.

## Still Rizal-only

These are deliberately not done in code and need a person with production
access. No values belong in this repository.

1. **`OPENAI_APPS_CHALLENGE`** — paste the token OpenAI gives into the MCP
   server's environment and restart it; then `php artisan mcp:check-openai-challenge` must say OK.
2. **Reviewer account** — create the account, add the sample data from the
   test pack, run `php artisan reviewer:grant <email>`, and hand the printed
   password to the directory through its own form only.
3. **Deploy** — every item above is merged to `main`, not deployed. QA's
   deploy checklist applies; recommend `LOG_STACK=daily` (14 days).
4. **Submission** — Claude Connectors Directory and ChatGPT Apps forms. Tool
   justifications: `mcp/docs/tool-justifications.md`.
5. **Revoke after review** — `php artisan reviewer:grant <email> --revoke`.
