<?php

use App\Http\Middleware\AssignRequestId;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Notifications\OpsDailyDigest;
use App\Services\Analytics;
use App\Services\ErrorTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
 * API-D launch reliability: error alerting with request ids, the daily ops
 * mail, GET /api/v1/health/deep, and a throttle on every public route.
 */

class OpsBoomJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        throw new LogicException('ops job went wrong');
    }
}

describe('error alerting', function () {
    beforeEach(function () {
        ErrorTracking::flushState();
        Route::middleware('api')->get('api/_test/ops-boom/{thing}', fn () => throw new RuntimeException('boom for ops@example.com'));
    });

    it('gives every response an X-Request-Id, keeping a sane one the caller sent', function () {
        $id = $this->getJson('/up')->assertOk()->headers->get(AssignRequestId::HEADER);
        expect($id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');

        $this->withHeader(AssignRequestId::HEADER, 'monitor-abc.123')->getJson('/api/v1/plans')
            ->assertHeader(AssignRequestId::HEADER, 'monitor-abc.123');
        $replaced = $this->withHeader(AssignRequestId::HEADER, "bad id\n<script>")->getJson('/api/v1/plans')->headers->get(AssignRequestId::HEADER);
        expect($replaced)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    });

    it('sends an unhandled exception with request id, route template and user ulid — no other PII', function () {
        $fake = Analytics::fake();
        $user = User::factory()->create(['email' => 'secret.person@example.com', 'name' => 'Secret Person']);

        $res = $this->actingAs($user)->getJson('/api/_test/ops-boom/42?email=leak@example.com')->assertStatus(500);

        $sent = $fake->captured('$exception');
        expect($sent)->toHaveCount(1);
        $p = $sent[0]['properties'];
        expect($p['request_id'])->toBe($res->headers->get(AssignRequestId::HEADER))
            ->and($p['route'])->toBe('api/_test/ops-boom/{thing}')
            ->and($p['user_ulid'])->toBe($user->ulid)
            ->and($sent[0]['distinct_id'] ?? $user->ulid)->toBe($user->ulid);
        $json = json_encode($sent);
        expect($json)->not->toContain('secret.person')->not->toContain('Secret Person')
            ->not->toContain('leak@example.com')->not->toContain('ops@example.com');
    });

    it('sends a failed queue job with its class', function () {
        $fake = Analytics::fake();
        config(['queue.default' => 'database']);
        OpsBoomJob::dispatch();

        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $sent = collect($fake->captured('$exception'))->firstWhere('properties.job', OpsBoomJob::class);
        expect($sent)->not->toBeNull()
            ->and($sent['properties']['source'])->toBe('queue')
            ->and(DB::table('failed_jobs')->count())->toBe(1);
    });
});

describe('daily ops mail', function () {
    it('sends nothing on a quiet day', function () {
        Notification::fake();

        $this->artisan('ops:daily-digest')->assertSuccessful();

        Notification::assertNothingSent();
    });

    it('mails halo@creatif.space the last 24h of failed jobs and publishes, grouped, without user data', function () {
        Notification::fake();
        $user = User::factory()->create(['email' => 'owner.private@example.com']);
        $post = SocialPost::factory()->for($user)->create(['caption' => 'Private caption']);
        $account = SocialAccount::factory()->for($user)->create();
        $post->targets()->create(['social_account_id' => $account->id, 'status' => 'failed', 'fail_code' => 'media_spec']);
        $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($user)->create()->id, 'status' => 'failed', 'fail_code' => 'media_spec']);
        $old = $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($user)->create()->id, 'status' => 'failed', 'fail_code' => 'auth']);
        DB::table('social_post_targets')->where('id', $old->id)->update(['updated_at' => now()->subDays(2)]);
        foreach ([now()->subHour(), now()->subHours(3), now()->subDays(3)] as $at) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) str()->uuid(), 'connection' => 'database', 'queue' => 'default',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\SyncInbox']), 'exception' => 'x', 'failed_at' => $at,
            ]);
        }

        $this->artisan('ops:daily-digest')->assertSuccessful();

        Notification::assertSentOnDemand(OpsDailyDigest::class, function (OpsDailyDigest $n, array $channels, AnonymousNotifiable $to) {
            $mail = $n->toMail($to);
            $text = $mail->subject.' '.implode(' ', $mail->introLines);

            return $to->routes['mail'] === 'halo@creatif.space'
                && $n->failedJobs === 2
                && $n->jobsByClass === ['App\\Jobs\\SyncInbox' => 2]
                && $n->failedPublishes === 2
                && $n->publishesByCode === ['media_spec' => 2]
                && ! str_contains($text, 'owner.private') && ! str_contains($text, 'Private caption');
        });
    });

    it('is scheduled daily', function () {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'ops:daily-digest'));

        expect($event)->not->toBeNull()->and($event->expression)->toBe('20 6 * * *');
    });
});

describe('GET /api/v1/health/deep', function () {
    beforeEach(function () {
        config([
            'services.ops.health_token' => 'h3alth-t0ken',
            'services.outstand.base_url' => 'https://api.outstand.test/v1',
            'services.outstand.key' => 'k',
            'queue.default' => 'database',
            'mail.default' => 'resend',
            'services.resend.key' => 're_test',
        ]);
        Storage::fake('s3');
        $GLOBALS['ops_outstand'] = fn () => Http::response(['data' => []]);
        Http::fake(['api.outstand.test/*' => fn () => ($GLOBALS['ops_outstand'])()]);
    });

    $deep = fn ($test, ?string $token = 'h3alth-t0ken') => ($token === null ? $test : $test->withToken($token))->getJson('/api/v1/health/deep');

    it('does not exist without HEALTH_TOKEN, and refuses a wrong or missing token', function () use ($deep) {
        $deep($this, null)->assertUnauthorized();
        $deep($this, 'nope')->assertUnauthorized();
        $this->withoutToken()->withHeader('X-Health-Token', 'h3alth-t0ken')->getJson('/api/v1/health/deep')->assertOk();

        config(['services.ops.health_token' => '']);
        $deep($this)->assertNotFound();
    });

    it('answers 200 with every check ok and its latency, and nothing else', function () use ($deep) {
        User::factory()->create(['email' => 'someone@example.com']);

        $res = $deep($this)->assertOk()->assertJsonPath('status', 'ok')->assertHeader('Cache-Control', 'no-store, private');

        expect(array_keys($res->json('checks')))->toBe(['db', 'queue', 'storage', 'outstand', 'mail']);
        foreach ($res->json('checks') as $check) {
            expect($check['ok'])->toBeTrue()->and($check['latency_ms'])->toBeInt();
        }
        expect(json_encode($res->json()))->not->toContain('someone@example.com');
    });

    it('answers 503 when the queue lags past the limit', function () use ($deep) {
        config(['services.ops.queue_max_lag' => 600]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(15)->getTimestamp(), 'created_at' => now()->subMinutes(15)->getTimestamp()]);

        $deep($this)->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.queue.ok', false)
            ->assertJsonPath('checks.db.ok', true);
        expect($deep($this)->json('checks.queue.detail'))->toStartWith('oldest job waiting');
    });

    it('answers 503 when Outstand answers 5xx or cannot be reached', function () use ($deep) {
        $GLOBALS['ops_outstand'] = fn () => Http::response('down', 502);
        $deep($this)->assertStatus(503)->assertJsonPath('checks.outstand.ok', false)->assertJsonPath('checks.outstand.detail', 'answered 502');
    });

    it('answers 503 when storage throws, and when mail has no real driver', function () use ($deep) {
        Storage::shouldReceive('disk')->with('s3')->andThrow(new RuntimeException('R2 down'));
        $deep($this)->assertStatus(503)->assertJsonPath('checks.storage.ok', false)->assertJsonPath('checks.storage.detail', 'RuntimeException');
    });

    it('fails the mail check when Resend has no key', function () use ($deep) {
        config(['services.resend.key' => '']);
        $deep($this)->assertStatus(503)->assertJsonPath('checks.mail.detail', 'Resend has no API key');
    });
});

describe('throttles on public routes', function () {
    /** Unauthenticated routes that deliberately have no throttle. */
    $exempt = [
        'POST api/v1/webhooks/creem',      // HMAC signature
        'POST api/v1/webhooks/outstand',   // HMAC signature
        'POST api/v1/webhooks/postforme',  // shared-secret header
        'POST resend/webhook',             // Svix signature (RESEND_WEBHOOK_SECRET)
        'GET|HEAD up',                     // liveness for the uptime monitor
        'GET|HEAD sanctum/csrf-cookie',    // package route, cookie only
        'GET|HEAD /',                      // redirect to the app
    ];

    it('has a throttle on every unauthenticated route except signed webhooks and liveness', function () use ($exempt) {
        $missing = collect(Route::getRoutes()->getRoutes())
            ->reject(fn ($r) => str_starts_with($r->uri(), '_') || str_starts_with($r->uri(), 'admin') || str_starts_with($r->uri(), 'livewire') || str_starts_with($r->uri(), 'filament') || str_starts_with($r->uri(), 'storage'))
            ->reject(fn ($r) => collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && (str_starts_with($m, 'auth') || str_contains($m, 'Authenticate'))))
            ->reject(fn ($r) => collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle')))
            ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())
            ->reject(fn ($k) => in_array($k, $exempt, true))
            ->values()
            ->all();

        expect($missing)->toBe([]);
    });

    it('answers 429 past a newly added limit (GET /plans, 60 a minute)', function () {
        RateLimiter::clear('');
        foreach (range(1, 60) as $i) {
            $this->getJson('/api/v1/plans')->assertOk();
        }
        $this->getJson('/api/v1/plans')->assertStatus(429);
    });
});
