# Activation funnel (WAP) — PostHog insight definition

Sign-up → first channel connected → first draft → first approval sent → first post published.

Weekly Active Publishers (WAP) start here: a person who has reached the last step is a publisher. Every step is a
**server-side** event that `app/Support/ProductEvents.php` sends. `distinct_id` is the user's ULID. The app identifies
with the same ULID, so earlier browser events (site visit, sign-up page) join the same person.

## Steps

| # | Step | Event | Sent | Properties |
|---|------|-------|------|------------|
| 1 | Signed up | `user_signed_up` | once per user, at account creation (`signedUp`) | `method`, `plan`, `platform`, `is_staff`, `signup_source`, `ref`, `utm_*`, `entry` |
| 2 | First channel connected | `first_channel_connected` | once per user, ever (`activated`) | `plan`, `signup_source`, `minutes_since_signup`, `network` |
| 3 | First draft | `first_draft_created` | once per user, ever: the first post (draft or queued) | `plan`, `signup_source`, `minutes_since_signup`, `post_id`, `status` |
| 4 | First approval sent | `first_approval_sent` | once per user, ever: the first approval link | `plan`, `signup_source`, `minutes_since_signup`, `link_id`, `posts_count` |
| 5 | First post published | `first_post_published` | once per user, ever: the first destination that went out | `plan`, `signup_source`, `minutes_since_signup`, `post_id`, `network` |

"Once per user, ever": the event fires when the data says it is the first time (the same check as the
`is_first_*` flags). A cache marker per event and user then stops retries, replayed webhooks or a second code path
from sending it again (`ProductEvents::activated`, tests in `tests/Feature/AnalyticsEventsTest.php`).

`signup_source` is `utm_source`, else `referral`, else the `entry` slug, else `direct`. It reads `unknown` for accounts
made before attribution was kept (`users.signup_attribution`).

## Internal accounts

Internal accounts are excluded at two levels:

1. **At the source.** `ProductEvents::capture` and `activated` send nothing for an internal account: staff
   (`is_staff`), `hello@creatif.space`, `ANALYTICS_INTERNAL_EMAILS` and `ANALYTICS_INTERNAL_EMAIL_DOMAINS`.
2. **In the insight.** Add the person filter `is_internal` ≠ `true` anyway. The API sets that property at sign-up and
   the app sets it at identify. The filter covers accounts that became internal later, and browser events from before
   identify.

## Funnel insight (PostHog UI)

- **Type:** Funnel. **Order:** sequential. **Conversion window:** 14 days. **Aggregate by:** unique persons.
- **Steps:**
  1. `user_signed_up`
  2. `first_channel_connected`
  3. `first_draft_created`
  4. `first_approval_sent`
  5. `first_post_published`
- **Filters:** person property `is_internal` is not `true`.
- **Breakdown:** `signup_source` (event property of step 1). Alternatives: `plan`, `platform`.
- **Date range:** last 30 days, re-read weekly.

Approval is optional in the product: a user can publish without sending a link. So the strict 5-step funnel
undercounts publishers. Keep a second insight without step 4, "Signed up → published", for the plain activation
rate.

## HogQL

The same funnel, step counts per `signup_source`, internal accounts excluded. Paste it into SQL insights.

```sql
SELECT
  signup_source,
  countIf(steps >= 1) AS signed_up,
  countIf(steps >= 2) AS first_channel,
  countIf(steps >= 3) AS first_draft,
  countIf(steps >= 4) AS first_approval,
  countIf(steps >= 5) AS first_published,
  round(100 * countIf(steps >= 5) / nullIf(countIf(steps >= 1), 0), 1) AS signup_to_published_pct
FROM (
  SELECT
    person_id,
    argMinIf(properties.signup_source, timestamp, event = 'user_signed_up') AS signup_source,
    windowFunnel(1209600)(
      toDateTime(timestamp),
      event = 'user_signed_up',
      event = 'first_channel_connected',
      event = 'first_draft_created',
      event = 'first_approval_sent',
      event = 'first_post_published'
    ) AS steps
  FROM events
  WHERE event IN ('user_signed_up', 'first_channel_connected', 'first_draft_created', 'first_approval_sent', 'first_post_published')
    AND timestamp > now() - INTERVAL 30 DAY
    AND coalesce(person.properties.is_internal, false) != true
  GROUP BY person_id
)
WHERE steps >= 1
GROUP BY signup_source
ORDER BY signed_up DESC
```

`windowFunnel(1209600)` is 14 days in seconds. `WHERE steps >= 1` keeps only people who signed up in the window. The
milestones of older accounts don't count as entries.

### Time to each step

Each milestone carries `minutes_since_signup`, so time-to-step needs no join.

```sql
SELECT
  event,
  count() AS people,
  round(quantile(0.5)(toFloat(properties.minutes_since_signup)) / 60, 1) AS median_hours,
  round(quantile(0.9)(toFloat(properties.minutes_since_signup)) / 60, 1) AS p90_hours
FROM events
WHERE event IN ('first_channel_connected', 'first_draft_created', 'first_approval_sent', 'first_post_published')
  AND timestamp > now() - INTERVAL 90 DAY
  AND coalesce(person.properties.is_internal, false) != true
GROUP BY event
ORDER BY median_hours
```

## Related

- Browser half of the sign-up funnel (site visit → sign-up view → Google started) and the Today connect nudge:
  `docs/signup-funnel.md` in creatifren/creatif.space.
- Event definitions: `app/Support/ProductEvents.php`. Attribution on the user: `app/Support/SignupAttribution.php`,
  which `php artisan support:user` shows.
