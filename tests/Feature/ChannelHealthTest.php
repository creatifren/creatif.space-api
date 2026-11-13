<?php

use App\Enums\SocialAccountStatus;
use App\Jobs\VerifyChannels;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Notifications\ChannelNeedsAttention;
use App\Notifications\SocialPostFailed;
use App\Support\ChannelHealth;
use App\Support\Credits;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * Channel health ("is this Bluesky channel actually working?"): a live,
 * read-only check through the relay right after a connect and once a day,
 * stored on the channel; a failed daily check emails the owner once; GET
 * /social-accounts shows it; POST …/verify checks on demand (1/min); a
 * disconnected channel can be removed (DELETE), keeping its history.
 */

beforeEach(function () {
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
        'app.frontend_url' => 'https://app.creatif.test',
    ]);
    Notification::fake();
});

/**
 * The relay answers each account read with this status (null: 404, int:
 * that HTTP error; an id not listed reads as connected). Callable again
 * mid-test: one fake reads the latest answers.
 */
function chRelay(array $byId): void
{
    $GLOBALS['chRelayAnswers'] = $byId;

    if (app()->bound('ch.relay.faked')) {
        return;
    }
    app()->instance('ch.relay.faked', true);

    Http::fake(function (HttpRequest $r) {
        if ($r->method() === 'GET' && preg_match('#/social-accounts/([^/?]+)$#', $r->url(), $m) === 1) {
            $id = $m[1];
            $answer = array_key_exists($id, $GLOBALS['chRelayAnswers']) ? $GLOBALS['chRelayAnswers'][$id] : 'connected';

            return match (true) {
                $answer === null => Http::response(['message' => 'not found'], 404),
                is_int($answer) => Http::response(['message' => 'down'], $answer),
                default => Http::response(['id' => $id, 'platform' => 'bluesky', 'status' => $answer]),
            };
        }

        return Http::response(['message' => 'ok']);
    });
}

/** Channels loads without its 5-minute self-heal sync (not under test here). */
function chList(TestCase $test, User $user): TestResponse
{
    Cache::put('social-self-heal:'.$user->ulid, true, now()->addHour());

    return $test->actingAs($user)->getJson('/api/v1/social-accounts')->assertOk();
}

function chRun(): void
{
    app()->call([new VerifyChannels, 'handle']);
}

function chChannel(User $user, array $attrs = []): SocialAccount
{
    return SocialAccount::factory()->for($user)->create([
        'platform' => 'bluesky',
        'username' => 'creatifspace.bsky.social',
        'connected_at' => now()->subMinute(),
        ...$attrs,
    ]);
}

describe('the check', function () {
    it('checks a just-connected channel read-only and stores ok, shown on the channel list', function () {
        $user = User::factory()->create();
        $bsky = chChannel($user, ['provider_account_id' => 'acc_bsky']);
        chRelay(['acc_bsky' => 'connected']);

        chList($this, $user)
            ->assertJsonPath('data.0.health_status', null)
            ->assertJsonPath('data.0.last_verified_at', null);

        chRun();

        expect($bsky->fresh())->health_status->toBe('ok')->health_reason->toBeNull()
            ->and($bsky->fresh()->last_verified_at)->not->toBeNull();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/social-accounts/acc_bsky'));
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() !== 'GET');

        chList($this, $user)
            ->assertJsonPath('data.0.health_status', 'ok')
            ->assertJsonPath('data.0.health_reason', null)
            ->assertJsonPath('data.0.needs_attention', false)
            ->assertJsonPath('data.0.last_verified_at', fn ($v) => is_string($v) && $v !== '');
        Notification::assertNothingSent();
    });

    it('fails a channel the relay says is inactive or no longer has, and emails the owner once', function (?string $answer, string $code) {
        $user = User::factory()->create();
        $bsky = chChannel($user, ['provider_account_id' => 'acc_bsky']);
        chRelay(['acc_bsky' => $answer]);

        chRun();
        $this->travel(25)->hours();
        chRun();

        expect($bsky->fresh())->health_status->toBe('failed')->health_reason->toBe($code)
            ->and($bsky->fresh()->status)->toBe(SocialAccountStatus::Connected);
        Notification::assertSentToTimes($user, ChannelNeedsAttention::class, 1);
        Notification::assertSentTo($user, ChannelNeedsAttention::class, fn ($n) => in_array('mail', $n->via($user), true));

        chList($this, $user)
            ->assertJsonPath('data.0.health_status', 'failed')
            ->assertJsonPath('data.0.needs_attention', true)
            ->assertJsonPath('data.0.health_reason', ChannelHealth::REASONS[$code]);
    })->with([
        'sign-in no longer active' => ['disconnected', 'signin_expired'],
        'account gone from the relay' => [null, 'not_found'],
    ]);

    it('emails again only after the channel worked in between', function () {
        $user = User::factory()->create();
        $bsky = chChannel($user, ['provider_account_id' => 'acc_bsky']);

        chRelay(['acc_bsky' => 'disconnected']);
        chRun();
        $this->travel(25)->hours();
        chRelay(['acc_bsky' => 'connected']);
        chRun();
        expect($bsky->fresh())->health_status->toBe('ok')->health_notified_at->toBeNull();

        $this->travel(25)->hours();
        chRelay(['acc_bsky' => 'disconnected']);
        chRun();

        Notification::assertSentToTimes($user, ChannelNeedsAttention::class, 2);
    });

    it('leaves the channel as it was when the relay does not answer, and stops after three', function () {
        $user = User::factory()->create();
        $channels = collect(range(1, 5))->map(fn ($i) => chChannel($user, ['provider_account_id' => "acc_{$i}", 'username' => "c{$i}"]));
        chRelay(['acc_1' => 503, 'acc_2' => 503, 'acc_3' => 503]);

        chRun();

        foreach ($channels as $c) {
            expect($c->fresh())->health_status->toBeNull()->last_verified_at->toBeNull();
        }
        Http::assertSentCount(3);
        Notification::assertNothingSent();

        // The three that failed wait an hour; the next run tries the other two.
        chRun();
        expect($channels->map(fn ($c) => $c->fresh()->health_status)->filter()->count())->toBe(2);
    });

    it('checks only what is due, a few per run: new, reconnected since, or a day old', function () {
        $user = User::factory()->create();
        chRelay([]);
        $fresh = chChannel($user, ['last_verified_at' => now()->subHours(2), 'health_status' => 'ok', 'connected_at' => now()->subDays(3)]);
        $reconnected = chChannel($user, ['last_verified_at' => now()->subHours(2), 'health_status' => 'failed', 'health_reason' => 'signin_expired', 'connected_at' => now()->subMinute()]);
        $old = chChannel($user, ['last_verified_at' => now()->subHours(25), 'health_status' => 'ok', 'connected_at' => now()->subDays(3)]);
        $gone = chChannel($user, ['status' => 'disconnected']);
        $removed = chChannel($user, ['status' => 'disconnected', 'removed_at' => now()]);
        $legacy = chChannel($user, ['provider' => 'outstand']);

        expect(VerifyChannels::due()->pluck('id')->sort()->values()->all())->toBe(collect([$reconnected->id, $old->id])->sort()->values()->all());

        // A reconnect makes the old result moot until the new check.
        $rows = collect(chList($this, $user)->json('data'))->keyBy('id');
        expect($rows[$reconnected->ulid]['health_status'])->toBeNull()
            ->and($rows[$fresh->ulid]['health_status'])->toBe('ok')
            ->and($rows[$gone->ulid])->toMatchArray(['health_status' => 'failed', 'needs_attention' => true, 'health_reason' => ChannelHealth::REASONS['disconnected']])
            ->and($rows[$legacy->ulid])->toMatchArray(['health_status' => 'failed', 'health_reason' => ChannelHealth::REASONS['reconnect_required']])
            ->and($rows->has($removed->ulid))->toBeFalse();

        SocialAccount::query()->delete();
        SocialAccount::factory()->for($user)->count(VerifyChannels::BATCH + 5)->create(['connected_at' => now()->subDay()]);
        chRun();
        expect(SocialAccount::query()->whereNotNull('last_verified_at')->count())->toBe(VerifyChannels::BATCH);
    });

    it('respects the publish_failed switch, and never names the service behind the app', function () {
        $user = User::factory()->create(['name' => 'Rizal']);
        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['publish_failed' => false])->assertOk();
        $bsky = chChannel($user, ['provider_account_id' => 'acc_bsky']);
        chRelay(['acc_bsky' => 'disconnected']);

        chRun();

        $user = $user->fresh();
        Notification::assertSentTo($user, ChannelNeedsAttention::class, fn ($n) => ! in_array('mail', $n->via($user), true));

        $mail = (new ChannelNeedsAttention($bsky->fresh()))->toMail($user);
        $text = implode("\n", [$mail->subject, ...$mail->introLines, ...$mail->outroLines, $mail->actionUrl]);
        expect($text)->toContain('A channel needs attention: bluesky @creatifspace.bsky.social')
            ->toContain(ChannelHealth::REASONS['signin_expired'])
            ->toContain('https://app.creatif.test/post/channels')
            ->not->toMatch(SocialPostFailed::VENDORS);
    });

    it('is scheduled every minute, without overlapping', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->description, VerifyChannels::class));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('* * * * *')
            ->and($events->first()->withoutOverlapping)->toBeTrue();
    });
});

describe('POST /social-accounts/{id}/verify', function () {
    it('checks now, answers the channel, and allows once a minute per channel', function () {
        $user = User::factory()->create();
        $bsky = chChannel($user, ['provider_account_id' => 'acc_bsky']);
        $ig = chChannel($user, ['provider_account_id' => 'acc_ig', 'platform' => 'instagram', 'username' => 'loyaldigicard']);
        chRelay(['acc_bsky' => 'connected', 'acc_ig' => 'disconnected']);

        $this->actingAs($user)->postJson("/api/v1/social-accounts/{$bsky->ulid}/verify")
            ->assertOk()
            ->assertJsonPath('data.id', $bsky->ulid)
            ->assertJsonPath('data.health_status', 'ok')
            ->assertJsonPath('data.needs_attention', false);

        $this->actingAs($user)->postJson("/api/v1/social-accounts/{$bsky->ulid}/verify")
            ->assertStatus(429)
            ->assertJsonPath('reason', 'too_soon')
            ->assertHeader('Retry-After');

        // Another channel has its own minute; a failed check here sends no email.
        $this->actingAs($user)->postJson("/api/v1/social-accounts/{$ig->ulid}/verify")
            ->assertOk()
            ->assertJsonPath('data.health_status', 'failed')
            ->assertJsonPath('data.health_reason', ChannelHealth::REASONS['signin_expired']);
        Notification::assertNothingSent();

        $this->travel(61)->seconds();
        $this->actingAs($user)->postJson("/api/v1/social-accounts/{$bsky->ulid}/verify")->assertOk();
    });

    it('answers {data: account} with exactly the contract\'s health fields', function () {
        $user = User::factory()->create();
        $bsky = chChannel($user, ['provider_account_id' => 'acc_bsky']);
        chRelay(['acc_bsky' => 'connected']);

        $data = $this->actingAs($user)->postJson("/api/v1/social-accounts/{$bsky->ulid}/verify")->assertOk()->json('data');

        expect($data['health_status'])->toBeIn(['ok', 'failed'])
            ->and($data['health_reason'])->toBeNull()
            ->and($data['last_verified_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
        expect(array_keys(chList($this, $user)->json('data.0')))
            ->toContain('health_status', 'health_reason', 'last_verified_at');
    });

    it('keeps the last result when the relay does not answer', function () {
        $user = User::factory()->create();
        $bsky = chChannel($user, ['provider_account_id' => 'acc_bsky', 'health_status' => 'ok', 'last_verified_at' => now()->subHour()]);
        chRelay(['acc_bsky' => 502]);

        $this->actingAs($user)->postJson("/api/v1/social-accounts/{$bsky->ulid}/verify")
            ->assertStatus(503)
            ->assertJsonPath('reason', 'upstream_unavailable');

        expect($bsky->fresh()->health_status)->toBe('ok');
    });

    it('refuses a disconnected channel, someone else\'s, and an API key', function () {
        $user = User::factory()->create();
        $gone = chChannel($user, ['status' => 'disconnected']);
        $mine = chChannel($user, ['provider_account_id' => 'acc_bsky']);
        chRelay(['acc_bsky' => 'connected']);

        $this->actingAs($user)->postJson("/api/v1/social-accounts/{$gone->ulid}/verify")
            ->assertStatus(409)->assertJsonPath('reason', 'not_connected');
        $this->actingAs(User::factory()->create())->postJson("/api/v1/social-accounts/{$mine->ulid}/verify")
            ->assertNotFound();

        $key = $this->actingAs($user, 'web')->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'scopes' => ['read']])->json('data.key');
        app('auth')->forgetGuards();
        bearer($this, $key)->postJson("/api/v1/social-accounts/{$mine->ulid}/verify")->assertForbidden();
        expect($mine->fresh()->last_verified_at)->toBeNull();
    });
});

describe('DELETE a disconnected channel', function () {
    it('removes it from Channels, keeps the row and its history, and cancels what was still to go', function () {
        $user = User::factory()->create();
        Credits::grant($user, 10_000, 'Test');
        $gone = chChannel($user, ['status' => 'disconnected', 'provider_account_id' => 'acc_gone']);
        $kept = chChannel($user, ['provider_account_id' => 'acc_kept', 'username' => 'kept']);
        chRelay([]);

        $only = SocialPost::factory()->for($user)->create(['provider_post_id' => 'post_only']);
        $only->targets()->create(['social_account_id' => $gone->id, 'status' => 'pending', 'paid_with' => 'credits', 'charged_at' => now()]);
        $shared = SocialPost::factory()->for($user)->create(['provider_post_id' => 'post_shared']);
        $shared->targets()->create(['social_account_id' => $gone->id, 'status' => 'pending', 'paid_with' => 'credits', 'charged_at' => now()]);
        $shared->targets()->create(['social_account_id' => $kept->id, 'status' => 'pending']);
        $published = SocialPost::factory()->for($user)->create(['status' => 'published', 'provider_post_id' => 'post_old']);
        $published->targets()->create(['social_account_id' => $gone->id, 'status' => 'published']);

        $this->actingAs($user)->deleteJson("/api/v1/social-accounts/{$gone->ulid}")->assertNoContent();

        expect($gone->fresh())->not->toBeNull()
            ->and($gone->fresh()->removed_at)->not->toBeNull()
            ->and($gone->fresh()->status)->toBe(SocialAccountStatus::Disconnected)
            ->and($only->fresh()->status->value)->toBe('draft')
            ->and($shared->targets()->pluck('social_account_id')->all())->toBe([$kept->id])
            ->and($published->targets()->pluck('social_account_id')->all())->toBe([$gone->id]);

        chList($this, $user)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $kept->ulid);

        // Twice is a no-op.
        $this->actingAs($user)->deleteJson("/api/v1/social-accounts/{$gone->ulid}")->assertNoContent();
    });

    it('removes it even when the relay is down, and only for its owner', function () {
        $user = User::factory()->create();
        $gone = chChannel($user, ['status' => 'disconnected', 'provider_account_id' => 'acc_gone']);
        Http::fake(['*' => Http::response(['message' => 'down'], 500)]);

        $this->actingAs(User::factory()->create())->deleteJson("/api/v1/social-accounts/{$gone->ulid}")->assertNotFound();
        expect($gone->fresh()->removed_at)->toBeNull();

        $this->actingAs($user)->deleteJson("/api/v1/social-accounts/{$gone->ulid}")->assertNoContent();
        expect($gone->fresh()->removed_at)->not->toBeNull();
    });

    it('removes a pre-move account even when the old provider\'s disconnect throws', function () {
        $user = User::factory()->create();
        $old = chChannel($user, ['provider' => 'outstand', 'provider_account_id' => 'out_bsky']);
        Http::fake(['*' => Http::response(['message' => 'down'], 500)]);

        expect($old->isLegacy())->toBeTrue();
        chList($this, $user)->assertJsonPath('data.0.status', 'disconnected');

        $this->actingAs($user)->deleteJson("/api/v1/social-accounts/{$old->ulid}")->assertNoContent();

        expect($old->fresh()->removed_at)->not->toBeNull()
            ->and($old->fresh()->status)->toBe(SocialAccountStatus::Disconnected);
        chList($this, $user)->assertJsonCount(0, 'data');
    });

    it('comes back when the same account is connected again', function () {
        $user = User::factory()->create();
        $gone = chChannel($user, ['status' => 'disconnected', 'provider_account_id' => 'acc_bsky', 'removed_at' => now()]);
        Http::fake(['api.postforme.test/*' => Http::response(['data' => [
            ['id' => 'acc_bsky', 'platform' => 'bluesky', 'username' => 'creatifspace.bsky.social', 'status' => 'connected'],
        ]])]);

        $this->actingAs($user)->postJson('/api/v1/social-accounts/sync')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $gone->ulid);

        expect($gone->fresh())->removed_at->toBeNull()
            ->and($gone->fresh()->status)->toBe(SocialAccountStatus::Connected)
            ->and(VerifyChannels::due()->pluck('id')->all())->toContain($gone->id);
    });

    it('still disconnects a connected channel first, without removing it', function () {
        $user = User::factory()->create();
        $live = chChannel($user, ['provider_account_id' => 'acc_live']);
        chRelay([]);

        $this->actingAs($user)->deleteJson("/api/v1/social-accounts/{$live->ulid}")->assertNoContent();

        expect($live->fresh())->removed_at->toBeNull()
            ->and($live->fresh()->status)->toBe(SocialAccountStatus::Disconnected);
        chList($this, $user)->assertJsonCount(1, 'data');
    });
});
