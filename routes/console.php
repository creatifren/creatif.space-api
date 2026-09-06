<?php

use App\Jobs\AggregateSpaceStats;
use App\Jobs\ExpireSubscriptions;
use App\Jobs\PruneSpaceArchives;
use App\Jobs\PruneSpaceEvents;
use App\Jobs\BackfillFileChecksums;
use App\Jobs\PruneStaleUploads;
use App\Jobs\PurgeTrashedFiles;
use App\Jobs\ReleaseCommissions;
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

/*
 * Yesterday's traffic becomes today's chart. Runs before billing so a slow
 * aggregation cannot delay the thing that decides who is on which plan.
 * The job re-does today as well as yesterday, so a night the queue was down
 * heals itself rather than leaving a permanent gap.
 */
Schedule::job(new AggregateSpaceStats)->dailyAt('02:10');

// Raw events are kept 90 days; the aggregate above is what survives.
Schedule::job(new PruneSpaceEvents)->dailyAt('02:40');

/*
 * Commission that has finished its holding period becomes a balance. Runs
 * after the analytics sweeps and before billing, so a day's releases are
 * already visible by the time anyone looks.
 */
Schedule::job(new ReleaseCommissions)->dailyAt('02:55');

/*
 * Uploads that were presigned but never completed leave pending rows (and
 * possibly orphaned R2 objects). A daily sweep is plenty — the presign
 * window is 15 minutes.
 */
Schedule::job(new PruneStaleUploads)->dailyAt('01:30');

/*
 * The Trash empties itself 30 days after deletion. After the stale-upload
 * prune, so a file that was both pending and trashed is already gone by
 * the time this looks.
 */
Schedule::job(new PurgeTrashedFiles)->dailyAt('01:45');

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
