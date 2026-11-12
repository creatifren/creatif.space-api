<?php

use App\Support\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * GET /api/v1/health for QA's watch: queue_lag_seconds (the oldest waiting
 * job) and scheduler_last_tick (the scheduler beats every minute). Both
 * are reported, neither decides ok / degraded, and neither says anything
 * but a number and a time.
 */

beforeEach(function () {
    Cache::flush();
    Storage::fake('s3');
    config(['queue.default' => 'database', 'services.ops.queue_max_lag' => 600]);
    Carbon::setTestNow(Carbon::parse('2026-11-12 09:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

it('reports how long the oldest waiting job has waited, ignoring reserved ones', function () {
    DB::table('jobs')->insert([
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subSeconds(95)->getTimestamp(), 'created_at' => now()->subSeconds(95)->getTimestamp()],
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => now()->getTimestamp(),
            'available_at' => now()->subHour()->getTimestamp(), 'created_at' => now()->subHour()->getTimestamp()],
    ]);

    $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('queue_lag_seconds', 95);
});

it('reports null queue lag off the database queue', function () {
    config(['queue.default' => 'sync']);

    $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('queue_lag_seconds', null);
});

it('reports the scheduler\'s last tick, null before the first', function () {
    $this->getJson('/api/v1/health')->assertJsonPath('scheduler_last_tick', null);

    (new SchedulerHeartbeat)();
    $this->travel(3)->minutes();

    $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('scheduler_last_tick', '2026-11-12T09:00:00+00:00');
});

it('leaves the status alone when the scheduler is quiet', function () {
    Cache::forever(SchedulerHeartbeat::KEY, now()->subDay()->getTimestamp());

    $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'ok')
        ->assertJsonPath('scheduler_last_tick', '2026-11-11T09:00:00+00:00');
});

it('beats every minute on the scheduler', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => $e->description === 'scheduler:heartbeat');

    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *');
});
