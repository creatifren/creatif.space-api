[![CI](https://github.com/creatifren/creatif.space-api/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/creatifren/creatif.space-api/actions/workflows/ci.yml)

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Approve from your AI chat

Clients can review and decide on posts sent to them from Claude or ChatGPT
through the creatif.space MCP server: `list_shared_with_me`,
`list_waiting_approvals` and `decide_approval` (scope `approvals:decide`), and
owners can pull the audit trail with `get_approval_audit`. See
[mcp/README.md → Approve from your AI chat](mcp/README.md#approve-from-your-ai-chat)
for the rules and an example conversation.

## Android app (Capacitor shell)

- **Google sign-in:** `GET /sanctum/csrf-cookie`, then `POST /auth/google/native` with `X-XSRF-TOKEN` and `{"id_token": "<Credential Manager ID token>", "next": "/spaces"}` (`next` optional). Answers `200 {"redirect": "…"}`, `422 invalid_token`, `403 suspended | google_not_linked`, `429`, or `503 google_unavailable`. The token's `aud` must be `GOOGLE_CLIENT_ID` (the web client, used as `serverClientId`) or one of `GOOGLE_NATIVE_EXTRA_AUDIENCES`. Details: `GoogleAuthController::native`.
- **Social connect:** send `"client": "android"` to `POST /api/v1/social-accounts/auth-url`; the return to `/social/connect` then carries `shell=android` (Outstand only; Post for Me's redirect is fixed per project).
- **Google Play Billing:** the app launches Play Billing with `obfuscatedAccountId` = the user's `id` (ULID from `/api/v1/me`), then sends `POST /api/v1/billing/google/verify` (Sanctum, 20/min) `{"purchase_token": "…", "product_id": "pro_monthly"}`. Answers `200 {"plan", "period", "status", "current_period_end", "provider": "google"}`, `202 pending` (payment not cleared, nothing granted), `422 unknown_product | invalid_token`, `403 account_mismatch`, `409 active_web_subscription` (a Creem plan runs: the purchase is not acknowledged, so Google refunds it), or `503 google_unavailable`. Same token again = same row (idempotent). While a Play plan runs, Creem checkout/cancel/portal answer `409 active_play_subscription`. `/api/v1/me` has `billing_provider` and `/api/v1/me/subscription` has `provider` + `manage_url` (`creem` = manage at creatif.space, `google` = "Manage subscription on Google Play").
  - **Play product ids** (Play Console → Subscriptions; overridable by env): `pro_monthly` → Pro monthly, `pro_yearly` → Pro yearly, `max_monthly` → Max monthly, `max_yearly` → Max yearly.
  - **RTDN:** Play Console → Monetization setup → Real-time developer notifications → a Pub/Sub topic with a **push** subscription to `https://<api>/api/v1/webhooks/google-play`, authentication on (service account + audience). Every notification re-reads `purchases.subscriptionsv2` and applies the same mapping; `REVOKED`/voided purchases end the plan at once.
  - **Env:**

    | Variable | Meaning |
    | --- | --- |
    | `GOOGLE_PLAY_PACKAGE_NAME` | default `space.creatif.app` |
    | `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` | path to the service account key file on the server (never committed, never logged); the account needs Play Console access to view financial data / manage orders |
    | `GOOGLE_PLAY_RTDN_AUDIENCE` | the push subscription's audience (exact `aud` of Pub/Sub's OIDC token) |
    | `GOOGLE_PLAY_RTDN_SA_EMAIL` | the push subscription's service account email |
    | `GOOGLE_PLAY_PRODUCT_PRO_MONTHLY` / `_PRO_YEARLY` / `_MAX_MONTHLY` / `_MAX_YEARLY` | Play product ids, defaults as above |

    Missing service account → verify answers `503 google_unavailable`; missing RTDN audience/email → every push is `401`.

## Google Play billing: real-time developer notifications (RTDN)

Play tells us about renewals, cancellations, holds, refunds and voided purchases through **Pub/Sub push** to `POST /api/v1/webhooks/google-play`. Each push carries a Google-signed OIDC token. We check its audience and service-account email (`GooglePushToken`), then re-read the subscription from the Play Developer API and map it (`GooglePlayBilling`). Nothing secret lives in this repo: the values below are env on the server, and the API key is a file outside the repo.

### Rizal-only setup (Play Console + Google Cloud)

Use the Google Cloud project linked to the Play developer account (below: `<project>`).

1. **Pub/Sub topic.** Cloud Console → Pub/Sub → Topics → *Create topic* `play-rtdn`. On the topic → *Permissions* → *Add principal* `google-play-developer-notifications@system.gserviceaccount.com` with role **Pub/Sub Publisher**. Play needs this to publish to the topic.
2. **Play Console → Monetize with Play → Monetization setup → Real-time developer notifications.**
   - *Topic name*: `projects/<project>/topics/play-rtdn`.
   - Notifications: **subscriptions and voided purchases**.
   - Save, then **Send test message** (step 6 checks it arrived).
3. **Service account for the push token.** IAM → Service accounts → *Create* `play-rtdn-push` (no roles needed). Its email is `play-rtdn-push@<project>.iam.gserviceaccount.com`.
4. **Push subscription.** Pub/Sub → Subscriptions → *Create subscription* on topic `play-rtdn`:
   - Delivery type **Push**, endpoint `https://api.creatif.space/api/v1/webhooks/google-play`.
   - **Enable authentication**: service account `play-rtdn-push@<project>.iam.gserviceaccount.com`.
   - **Audience** = exactly the value you put in `GOOGLE_PLAY_RTDN_AUDIENCE`. Use the endpoint URL, `https://api.creatif.space/api/v1/webhooks/google-play`.
   - Acknowledgement deadline 30 s; retry policy *exponential backoff*. Our webhook answers 2xx to everything it has authenticated, so Pub/Sub does not retry forever.
5. **Play Developer API access** (the webhook re-reads each subscription; the app's verify call uses the same access).
   - Create, or reuse, a service account for the API, e.g. `play-api@<project>.iam.gserviceaccount.com`, and download its JSON key **to the server only**.
   - In Play Console → **Users and permissions** (older consoles: *Setup → API access → link the project / grant access*), invite that service account email with:
     - **View financial data, orders, and cancellation survey responses**
     - **Manage orders and subscriptions**
6. **Server env** (production `.env`, never committed):

   ```
   GOOGLE_PLAY_PACKAGE_NAME=space.creatif.app
   GOOGLE_PLAY_SERVICE_ACCOUNT_JSON=/path/on/server/play-api.json
   GOOGLE_PLAY_RTDN_AUDIENCE=https://api.creatif.space/api/v1/webhooks/google-play
   GOOGLE_PLAY_RTDN_SA_EMAIL=play-rtdn-push@<project>.iam.gserviceaccount.com
   ```

   Then check that Play's test message arrived. The log shows `google_play.rtdn {"outcome":"test"}`. A `google_play.rtdn_unauthorized` line means the audience or service-account email does not match step 4.

Product ids default to `pro_monthly`, `pro_yearly`, `max_monthly` and `max_yearly` (`GOOGLE_PLAY_PRODUCT_*` overrides them).

### Self-test (local / testing only)

```
php artisan play:rtdn-selftest
```

This sends a `testNotification` through the real webhook. It signs a Pub/Sub-style OIDC token with a throwaway key, and Google's JWKS, OAuth and androidpublisher answers are simulated; it never calls Google. It checks against your configured audience and push service account when they are set.

```
php artisan play:rtdn-selftest --user=<ulid or email> --type=canceled --state=CANCELED
```

This gives that local user a Play purchase, sends the notification and prints the mapped row: plan, status, period end, grace, auto-renew.
- **`--type`**: `purchased`, `renewed`, `canceled`, `recovered`, `on_hold`, `in_grace`, `paused`, `revoked`, `expired`, `voided` or `test`.
- **`--state`**: what Google answers, `ACTIVE`, `CANCELED`, `IN_GRACE_PERIOD`, `ON_HOLD`, `PAUSED` or `EXPIRED`.

The command refuses to run when `APP_ENV` is production.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
