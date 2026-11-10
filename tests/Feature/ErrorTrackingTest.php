<?php

use App\Exceptions\ProviderRateLimited;
use App\Jobs\SendAnalytics;
use App\Models\User;
use App\Services\Analytics;
use App\Services\AnalyticsFake;
use App\Services\ErrorTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

/*
 * Unhandled exceptions and failed jobs → PostHog `$exception` events.
 */

class ErrorTrackingBoomJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        throw new LogicException('job went wrong');
    }
}

beforeEach(function () {
    ErrorTracking::flushState();
    config(['app.release' => 'b1179c56d958817d1724678116d5c1ba4173f2bf']);

    Route::middleware('api')->group(function () {
        Route::post('api/_test/boom/{thing}', fn () => throw new RuntimeException(
            'Boom for rani@example.com with Bearer abc.def.ghi and https://x.test/cb?token=s3cr3t&ok=1',
        ));
        Route::get('api/_test/rate-limited', fn () => throw new ProviderRateLimited(30));
        Route::get('api/_test/query', fn () => DB::select('select * from no_such_table where email = ?', ['andi@example.com']));
    });
});

function sentExceptions(AnalyticsFake $fake): array
{
    return $fake->captured('$exception');
}

it('reports an unhandled exception with the user, route, status, release and frames', function () {
    $fake = Analytics::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/_test/boom/42?email=leak@example.com', ['password' => 'hunter2', 'email' => 'body@example.com'])
        ->assertStatus(500);

    $sent = sentExceptions($fake);
    expect($sent)->toHaveCount(1);
    $p = $sent[0]['properties'];

    expect($sent[0]['distinct_id'])->toBe($user->ulid)
        ->and($p['route'])->toBe('api/_test/boom/{thing}')
        ->and($p['method'])->toBe('POST')
        ->and($p['status'])->toBe(500)
        ->and($p['source'])->toBe('http')
        ->and($p['release'])->toBe('b1179c56d958817d1724678116d5c1ba4173f2bf')
        ->and($p['$exception_list'][0]['type'])->toBe(RuntimeException::class)
        ->and($p['$exception_list'][0]['value'])->toBe('Boom for [email] with Bearer [redacted] and https://x.test/cb?token=[redacted]&ok=1');

    $frames = $p['$exception_list'][0]['stacktrace']['frames'];
    $last = end($frames);
    expect($last['filename'])->toBe('tests/Feature/ErrorTrackingTest.php')
        ->and($last['in_app'])->toBeFalse()
        ->and(collect($frames)->every(fn ($f) => array_keys($f) === ['platform', 'lang', 'filename', 'lineno', 'function', 'in_app']))->toBeTrue()
        ->and(collect($frames)->contains(fn ($f) => str_starts_with($f['filename'], 'vendor/') && $f['in_app'] === false))->toBeTrue();

    // Nothing from the request body, the query string, or any email.
    $json = json_encode($sent);
    expect($json)->not->toContain('hunter2')
        ->and($json)->not->toContain('@example.com')
        ->and($json)->not->toContain('s3cr3t')
        ->and($json)->not->toContain(base_path());
});

it('uses "server" as distinct_id for guests', function () {
    $fake = Analytics::fake();

    $this->postJson('/api/_test/boom/1')->assertStatus(500);

    expect(sentExceptions($fake)[0]['distinct_id'])->toBe('server');
});

it('marks app/ frames in_app and reports a misconfigured relay refusal with its 422', function () {
    $fake = Analytics::fake();
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
    ]);
    Http::fake(['api.outstand.test/*' => Http::response(['success' => false, 'error' => 'Social network not configured'], 404)]);

    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'tiktok'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'provider_misconfigured');

    $p = sentExceptions($fake)[0]['properties'];
    $frames = $p['$exception_list'][0]['stacktrace']['frames'];
    expect($p['status'])->toBe(422)
        ->and($p['route'])->toBe('api/v1/social-accounts/auth-url')
        ->and(end($frames)['filename'])->toBe('app/Exceptions/ProviderConnectRefused.php')
        ->and(end($frames)['in_app'])->toBeTrue();
});

it('skips validation, auth, 404, rate limits and the user\'s own relay refusals', function () {
    $fake = Analytics::fake();
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
    ]);
    Http::fake(['api.outstand.test/*' => Http::response(['success' => false, 'error' => 'Invalid app password'], 400)]);
    $user = User::factory()->create();

    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->actingAs($user)->postJson('/api/v1/social-accounts/auth-url', [])->assertUnprocessable();
    $this->actingAs($user)->getJson('/api/v1/no-such-route')->assertNotFound();
    $this->actingAs($user)->getJson('/api/_test/rate-limited')->assertStatus(500);
    $this->actingAs($user)
        ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'bluesky', 'handle' => 'rani.bsky.social', 'app_password' => 'nope'])
        ->assertJsonPath('code', 'provider_connect_refused');

    expect(sentExceptions($fake))->toBe([]);
});

it('sends the same error at most once a minute', function () {
    $fake = Analytics::fake();

    $this->postJson('/api/_test/boom/1')->assertStatus(500);
    $this->postJson('/api/_test/boom/2')->assertStatus(500);
    expect(sentExceptions($fake))->toHaveCount(1);

    $this->travel(61)->seconds();
    $this->postJson('/api/_test/boom/3')->assertStatus(500);
    expect(sentExceptions($fake))->toHaveCount(2);
});

it('keeps query bindings out of a database error', function () {
    $fake = Analytics::fake();

    $this->getJson('/api/_test/query')->assertStatus(500);

    $value = sentExceptions($fake)[0]['properties']['$exception_list'][0]['value'];
    expect($value)->toContain('no_such_table')
        ->and($value)->toContain('email = ?')
        ->and($value)->not->toContain('andi@');
});

it('reports a failed queue job with its class', function () {
    $fake = Analytics::fake();

    expect(fn () => ErrorTrackingBoomJob::dispatch())->toThrow(LogicException::class);

    $sent = sentExceptions($fake);
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['distinct_id'])->toBe('server')
        ->and($sent[0]['properties']['job'])->toBe(ErrorTrackingBoomJob::class)
        ->and($sent[0]['properties']['source'])->toBe('queue')
        ->and($sent[0]['properties'])->not->toHaveKey('route');
});

it('queues one SendAnalytics job, not held back by a transaction', function () {
    config(['services.posthog.key' => 'phc_test']);
    app()->instance(Analytics::class, new class extends Analytics
    {
        public function enabled(): bool
        {
            return true;
        }
    });
    Queue::fake();

    $this->postJson('/api/_test/boom/1')->assertStatus(500);

    Queue::assertPushed(SendAnalytics::class, fn (SendAnalytics $job) => $job->message['event'] === '$exception'
        && $job->message['distinct_id'] === 'server'
        && $job->afterCommit !== true);
});

it('never lets reporting itself throw', function () {
    app()->instance(Analytics::class, new class extends Analytics
    {
        public function captureException(string $distinctId, array $properties): void
        {
            throw new RuntimeException('posthog down');
        }
    });

    $this->postJson('/api/_test/boom/1')->assertStatus(500);

    Cache::shouldReceive('add')->andThrow(new RuntimeException('cache down'));
    ErrorTracking::report(new RuntimeException('x'));

    expect(true)->toBeTrue();
});
