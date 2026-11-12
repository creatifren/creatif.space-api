# PostHog setup after deploy — insights for QA and Growth to create

This is a checklist. Nothing here is created automatically, and no agent creates insights in PostHog: QA or Growth
create these by hand in the project, after the deploy described below.

Definitions, steps and HogQL live in [`activation-funnel.md`](activation-funnel.md) (#166). This page says what to
create, in what order, and how to check it shows data.

## 0. Before you start (the deploy must include)

| Needed | Why | Where |
|---|---|---|
| API with creatif.space-api #160 **and its migration run** (`2026_11_29_100000_add_signup_attribution_to_users`) | sends `first_channel_connected`, `first_draft_created`, `first_approval_sent`, `first_post_published` and `signup_source` | this repo |
| API with #153 | `user_signed_up` carries `is_staff` and `signup_source` | this repo (live since `4b94ba1`) |
| App with creatif.space #204 and #210 | browser events `signup_viewed`, `google_signin_started`, `google_signin_completed`, `connect_nudge_shown`, plus `referrer_host` / `landing_path` at sign-up | creatifren/creatif.space |
| Marketing with creatifspace #84 | every sign-up CTA lands on app `/signup`; nav "Sign in" counts as `cta = sign_in` | creatifren/creatifspace |
| `POSTHOG_API_KEY` set on the API, and its queue worker running | server events leave through the queued `SendAnalytics` job | API env (already true on 12 Nov 2026: server events arrive) |

Until #160 is deployed, the `first_*` events and `signup_source` / `minutes_since_signup` don't exist in PostHog, and
the insights in §2 stay empty. That is expected.

## 1. Everywhere

- **Filter out internal accounts:** person property `is_internal` is not `true`. The API already drops staff and
  internal addresses. This filter catches the rest, such as accounts marked internal later, or browser events from
  before identify.
- **Count:** unique persons, never events.
- **Name** each insight exactly as below, so the dashboard and links stay stable.

## 2. Insights to create

| # | Name | Type | Definition | Breakdown |
|---|------|------|------------|-----------|
| 1 | **Activation funnel (WAP)** | Funnel, sequential, 14-day window | `user_signed_up` → `first_channel_connected` → `first_draft_created` → `first_approval_sent` → `first_post_published` | `signup_source` (step 1) |
| 2 | **Signed up → first publish** | Funnel, sequential, 14-day window | `user_signed_up` → `first_channel_connected` → `first_draft_created` → `first_post_published` (no approval step: approval is optional) | `signup_source` |
| 3 | **Time to first publish** | Trends, weekly | `first_post_published`, aggregation *property value median* of `minutes_since_signup`; second series: the same at p90 | none (or `plan`) |
| 4 | **Time to each step** | SQL insight | the "Time to each step" HogQL in `activation-funnel.md` | — |
| 5 | **Sign-ups by source** | Trends, daily, bar | `user_signed_up`, unique persons | `signup_source` |
| 6 | **Sign-up page funnel** | Funnel, sequential, 1-day window | `$pageview` on `creatif.space` → `signup_viewed` (`page = signup`) → `google_signin_started` → `user_signed_up` | `utm_source` |
| 7 | **Google sign-in completion** | Trends, daily | `google_signin_started` and `google_signin_completed` (unique persons), formula B / A | `page` |
| 8 | **Connect nudge** | Funnel, sequential, 1-day window | `connect_nudge_shown` → `onboarding_step_clicked` (`nudge = true`) → `first_channel_connected` | — |
| 9 | **Sign-up CTA clicks** | Trends, daily | `cta_clicked` where `cta` in (`start_free`, `get_pro`, `get_max`) | `cta`, `location` |

Put 1, 2, 3, 5 and 6 on a dashboard named **Activation**. Put 7, 8 and 9 on the same dashboard under a "Sign-up
diagnostics" text tile.

## 3. Check each one shows data (after the first real sign-up)

- Insight 5 shows the new sign-up within a few minutes. If it doesn't, check the API's `analytics.send_failed` and
  `analytics.dispatch_failed` logs and the queue worker. Today's server events (`post_created`, `approval_link_*`)
  prove the key and the worker are fine.
- A brand-new **internal** account (staff, `hello@creatif.space`, `ANALYTICS_INTERNAL_EMAILS` /
  `_EMAIL_DOMAINS`) sends **no** `user_signed_up`. That is by design. Test with a non-internal address.
- In insight 1, step 1 counts only people who signed up inside the date range. Older accounts' milestones are not
  entries.
- Accounts made before #160 show `signup_source = unknown` on their milestones.

## 4. Don't

- Don't count `channel_connected` with `is_first_channel = true` *and* `first_channel_connected` in the same
  funnel. They are the same moment; use `first_channel_connected`.
- Don't send any of the API's events from the browser (`user_signed_up`, `first_*`, `post_published`, …). The
  funnel would count twice.
