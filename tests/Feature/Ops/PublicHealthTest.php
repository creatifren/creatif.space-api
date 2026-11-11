<?php

use App\Models\User;
use App\Services\ErrorTracking;
use App\Support\HealthProbe;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
 * GET /api/v1/health (public) and the scheduled health:selfcheck.
 */

beforeEach(function () {
    Cache::flush();
    Storage::fake('s3');
    config(['app.release' => str_repeat('ab12', 10), 'queue.default' => 'database']);
    ErrorTracking::flushState();
});

it('answers 200 with every check ok and the deployed commit', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'db' => 'ok',
            'queue' => 'ok',
            'storage' => 'ok',
            'version' => str_repeat('ab12', 10),
        ])
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('answers 503 and names the failing check, without a reason', function () {
    Storage::shouldReceive('disk')->with('s3')->andThrow(new RuntimeException('secret-bucket.r2.example: AccessDenied'));

    $response = $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('storage', 'fail')
        ->assertJsonPath('db', 'ok');

    expect($response->getContent())->not->toContain('secret-bucket')->not->toContain('AccessDenied');
});

it('fails the queue when the oldest waiting job is past the lag limit', function () {
    config(['services.ops.queue_max_lag' => 600]);
    DB::table('jobs')->insert([
        'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
        'available_at' => now()->subMinutes(20)->getTimestamp(), 'created_at' => now()->subMinutes(20)->getTimestamp(),
    ]);

    $this->getJson('/api/v1/health')->assertStatus(503)->assertJsonPath('queue', 'fail');
});

it('carries no user data and no configuration', function () {
    User::factory()->create(['email' => 'someone@example.test']);
    config(['services.ops.health_token' => 'deep-token-value', 'services.outstand.key' => 'sk_live_secret']);

    $body = $this->getJson('/api/v1/health')->assertOk()->getContent();

    expect(array_keys(json_decode((string) $body, true)))->toBe(['status', 'db', 'queue', 'storage', 'version'])
        ->and($body)->not->toContain('someone@example.test')
        ->not->toContain('deep-token-value')
        ->not->toContain('sk_live_secret');
});

it('answers null for the version when no commit is known', function () {
    config(['app.release' => '']);
    ErrorTracking::flushState();
    $this->app->instance(HealthProbe::class, new class extends HealthProbe
    {
        public function version(): ?string
        {
            return null;
        }
    });

    $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('version', null);
});

it('is rate-limited', function () {
    foreach (range(1, 60) as $_) {
        $this->getJson('/api/v1/health')->assertOk();
    }

    $this->getJson('/api/v1/health')->assertStatus(429);
});

it('keeps an answer briefly instead of probing on every call', function () {
    $probe = Mockery::mock(HealthProbe::class);
    $probe->shouldReceive('run')->once()->andReturn(['db' => true, 'queue' => true, 'storage' => true]);
    $probe->shouldReceive('version')->andReturn('abc');
    $this->app->instance(HealthProbe::class, $probe);

    $this->getJson('/api/v1/health')->assertOk();
    $this->getJson('/api/v1/health')->assertOk();
});

describe('health:selfcheck', function () {
    it('passes quietly when everything answers', function () {
        Log::spy();

        $this->artisan('health:selfcheck')
            ->expectsOutputToContain('All checks passed.')
            ->assertExitCode(0);

        Log::shouldNotHaveReceived('warning');
    });

    it('logs a warning naming the failed checks and exits 1', function () {
        Log::spy();
        $probe = Mockery::mock(HealthProbe::class);
        $probe->shouldReceive('run')->andReturn(['db' => true, 'queue' => false, 'storage' => false]);
        $probe->shouldReceive('version')->andReturn('abc');
        $this->app->instance(HealthProbe::class, $probe);

        $this->artisan('health:selfcheck')
            ->expectsOutputToContain('Health check failed: queue, storage')
            ->assertExitCode(1);

        Log::shouldHaveReceived('warning')->once()->with('health.selfcheck_failed', ['failed' => ['queue', 'storage'], 'version' => 'abc']);
    });

    it('is scheduled every five minutes', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'health:selfcheck'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('*/5 * * * *');
    });
});
