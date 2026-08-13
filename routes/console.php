<?php

use App\Jobs\AggregateSpaceStats;
use App\Jobs\ExpireSubscriptions;
use App\Jobs\PruneSpaceEvents;
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

// TODO Fase 2 leftover: the periodic Drive re-check (version changed /
// access lost) belongs here too, once it exists.
