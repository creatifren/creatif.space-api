<?php

use App\Jobs\ExpireSubscriptions;
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

// TODO Fase 2 leftover: the periodic Drive re-check (version changed /
// access lost) belongs here too, once it exists.
