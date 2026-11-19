# v7 backend (prototype v7/v8, 19 Nov 2026)

What the API and MCP server gained for the v7 app. Migrations M1–M7 were approved by Rizal on 19 Nov 2026.

| Area | Endpoints | Migration |
|---|---|---|
| Client labels | `GET/POST /client-labels`, `GET/PATCH/DELETE /client-labels/{id}`, `PUT /social-accounts/{id}/client-labels`; posts carry `clients` and filter by `client_ids[]` / `client_id` | M1 |
| Sign in with an email code | `POST /auth/email-code`, `POST /auth/email-code/verify` (web routes, SPA session) | M2 |
| Approval links | `views`, `visibility` (link default), `replaces_link_id`, `client_id`; public `/a/{token}`: `summary`, `views`, `documents`, `captions_differ`, per-target `caption_effective`; 409 `state` revoked/replaced, 410 `state` expired; approve takes `note` | M3 |
| Client reports + Report emails | `GET/PATCH /me/report-settings`, `GET/POST /client-reports`, `GET/PATCH /client-reports/{id}`, `POST /client-reports/{id}/send`, `DELETE /client-reports/{id}/link`, public `GET /public/client-reports/{token}`; job `SendClientReports` hourly | M4 |
| Spaces Gallery / Blog | spaces `type`, visibility `link`; items `title/featured/hidden`; `GET/POST /spaces/{id}/posts`, `GET/PATCH/DELETE /blog-posts/{id}`, `POST /blog-posts/{id}/publish|unpublish|send-for-approval`; `PUT /social-posts/{id}/spaces` (Also add to); public `/profiles/{handle}/spaces/{slug}/posts/{post}`; `/a/{token}/documents/{id}/decision` | M5 |
| Public profile + URLs | profile `layout`, `contact`; `GET/PUT/DELETE /me/domain` (Enterprise only, 402 `required_plan: enterprise` otherwise), `POST /me/domain/check`, `GET /me/link-taps`, `POST /profiles/{handle}/taps`, `GET /public/domains/{host}`; public profile `pages` | M6 |
| Region + theme | users `week_start`, `time_format`; theme default `system` | M7 |

MCP tools added: list_clients, create_client, update_client, delete_client, set_channel_clients, get_report_settings, update_report_settings, list_client_reports, get_client_report, update_client_report, send_client_report, list_blog_posts, create_blog_post, update_blog_post, publish_blog_post, send_blog_post_for_approval, set_post_spaces (65 tools).
