<?php

use App\Jobs\AggregateSpaceStats;
use App\Jobs\ApplyMediaRetention;
use App\Jobs\BackfillFileChecksums;
use App\Jobs\ExpireSubscriptions;
use App\Jobs\PruneActivityLog;
use App\Jobs\PruneMcpAuditLogs;
use App\Jobs\PruneSpaceArchives;
use App\Jobs\PruneSpaceEvents;
use App\Jobs\PruneStaleUploads;
use App\Jobs\PurgeTrashedFiles;
use App\Jobs\ReleaseCommissions;
use App\Jobs\RenewMonthlyCredits;
use App\Jobs\RetryRateLimitedPublishes;
use App\Jobs\SendApprovedHeldPosts;
use App\Jobs\SyncInboxes;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Billing runs before anyone is awake: a plan that lapsed overnight should
 * already say so by the time its owner opens the dashboard.
 */
Schedule::job(new ExpireSubscriptions)->dailyAt('03:00');

// Storage rent (RenewCredits, 1st at 03:20) was removed in Media v2.

/*
 * Monthly Credits: each owner's cycle turns on their own day (sign-up or
 * subscription start), so every day — after billing has settled plans,
 * before anyone is sending.
 */
Schedule::job(new RenewMonthlyCredits)->dailyAt('03:40');

/*
 * Yesterday's traffic becomes today's chart. Runs before billing so a slow
 * aggregation cannot delay the thing that decides who is on which plan.
 * The job re-does today as well as yesterday, so a night the queue was down
 * heals itself rather than leaving a permanent gap.
 */
Schedule::job(new AggregateSpaceStats)->dailyAt('02:10');

// Raw events are kept 90 days; the aggregate above is what survives.
Schedule::job(new PruneSpaceEvents)->dailyAt('02:40');

// The activity ledger keeps the same 90 days as the events above.
Schedule::job(new PruneActivityLog)->dailyAt('02:45');

// MCP audit logs: mcp.audit_retention_days (90), as the privacy page says.
Schedule::job(new PruneMcpAuditLogs)->dailyAt('02:50');

/*
 * Commission that has finished its holding period becomes a balance. Runs
 * after the analytics sweeps and before billing, so a day's releases are
 * already visible by the time anyone looks.
 */
Schedule::job(new ReleaseCommissions)->dailyAt('02:55');

/*
 * Uploads that were presigned but never completed leave pending rows (and
 * possibly orphaned R2 objects). Hourly, so dead rows and objects do not
 * pile up for a day; the quota already stops counting them once the
 * 15-minute presign window has passed (PlanQuota::storageUsed).
 */
Schedule::job(new PruneStaleUploads)->hourly();

/*
 * The Trash empties itself 30 days after deletion. After the stale-upload
 * prune, so a file that was both pending and trashed is already gone by
 * the time this looks.
 */
Schedule::job(new PurgeTrashedFiles)->dailyAt('01:45');

/*
 * Media v2 retention: post media past its rule (MediaRetention). After the
 * Trash purge, before the archives. MEDIA_RETENTION_MODE=report (default)
 * only logs what it would delete; delete deletes.
 */
Schedule::job(new ApplyMediaRetention)->dailyAt('01:48')->withoutOverlapping();

/*
 * Built "Download all" archives live 24 hours. Without this every press
 * ever made stays in the bucket, billed monthly, for a zip nobody will
 * open again.
 */
Schedule::job(new PruneSpaceArchives)->dailyAt('01:50');

/*
 * Fills in checksums for files stored before the upload path recorded one.
 * Bounded per run, so it drains over several nights rather than holding
 * the queue; once every row has one this is a single indexed query that
 * finds nothing.
 */
Schedule::job(new BackfillFileChecksums)->dailyAt('01:55');

/*
 * The Inbox's safety net: webhooks are the live path, this backfills what
 * they missed, for accounts whose Inbox has been opened. Hourly, and each
 * account's job backs off on its own when Outstand rate-limits.
 */
Schedule::job(new SyncInboxes)->hourly();

/*
 * Destinations a platform rate-limited wait with a retry_at instead of
 * failing on the spot; every minute, the ones whose wait is over go again.
 */
Schedule::job(new RetryRateLimitedPublishes)->everyMinute()->withoutOverlapping();

/*
 * Posts a client approved while their slot was past the relay's scheduling
 * horizon wait approved; each goes to the relay once its slot is inside it.
 */
Schedule::job(new SendApprovedHeldPosts)->everyFiveMinutes()->withoutOverlapping();

/*
 * The morning ops mail: failed jobs and failed publishes in the last 24h,
 * to services.ops.alert_email — only when there were any.
 */
Schedule::command('ops:daily-digest')->dailyAt('06:20');

/*
 * Accounts whose deletion grace period (30 days) has passed are purged.
 */
Schedule::command('accounts:purge-deleted')->dailyAt('03:25')->withoutOverlapping();
