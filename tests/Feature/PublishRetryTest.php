<?php

use App\Models\Plan;
use App\Models\PublishAttempt;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics;
use App\Support\Credits;
use App\Support\PublishFailure;
use App\Support\SendConfirmation;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * Item 6: a failed publish keeps a stable failure_code and a plain reason
 * (per attempt, in social_post_publish_attempts), and the owner can try
 * it again — POST /social-posts/{id}/retry, MCP retry_post (preview →
 * confirm). Credits work as on any send: the failure paid the destination
 * back, the retry charges it again.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
        'services.outstand.webhook_secret' => 'whsec_out',
    ]);
    Cache::flush();
    Notification::fake();
    Analytics::fake();
});

function rtUser(int $credits = 30_000): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    if ($credits > 0) {
        Credits::grant($user, $credits, 'Test');
    }

    return $user;
}

function rtAccount(User $user, string $platform = 'pinterest'): SocialAccount
{
    return SocialAccount::factory()->for($user)->create(['platform' => $platform, 'provider' => 'outstand']);
}

/** A post Outstand already took, one pending target per account, paid for. */
function rtSentPost(User $user, array $accounts): SocialPost
{
    $post = SocialPost::factory()->for($user)->create([
        'provider' => 'outstand', 'provider_post_id' => 'op_1', 'status' => 'scheduled', 'scheduled_at' => null,
        'media' => [['url' => 'https://cdn.example.test/pic.jpg']],
    ]);
    foreach ($accounts as $a) {
        $post->targets()->create(['social_account_id' => $a->id]);
    }
    Credits::chargeSend($post);

    return $post;
}

function rtHook($test, array $payload)
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return $test->call('POST', '/api/v1/webhooks/outstand', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_OUTSTAND_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'whsec_out'),
    ], $body);
}

function rtError(SocialAccount $account, string $error, string $postId = 'op_1'): array
{
    return ['event' => 'post.error', 'data' => ['postId' => $postId, 'socialAccounts' => [[
        'accountId' => $account->provider_account_id, 'network' => $account->platform->value, 'error' => $error,
    ]]]];
}

/** A post whose only destination failed for good (and was paid back). */
function rtFailedPost($test, User $user, string $error = 'Duplicate content', string $platform = 'pinterest'): SocialPost
{
    $account = rtAccount($user, $platform);
    $post = rtSentPost($user, [$account]);
    rtHook($test, rtError($account, $error))->assertOk();

    expect($post->fresh()->status->value)->toBe('failed');

    return $post->fresh();
}

describe('a stable failure code and a plain reason', function () {
    it('maps each Outstand or network error to a code', function (?string $raw, string $code) {
        expect(PublishFailure::classify($raw, 'pinterest')['reason_code'])->toBe($code);
    })->with([
        'no reason' => [null, 'network_rejected'],
        'empty' => ['', 'network_rejected'],
        'refused, nothing said' => ['the platform refused it', 'network_rejected'],
        'Outstand 503' => ["HTTP request returned status code 503:\n{\"error\":\"Service Unavailable\"}", 'platform_unavailable'],
        'connection reset' => ['cURL error 56: Recv failure: Connection reset by peer', 'platform_unavailable'],
        'timed out' => ['cURL error 28: Operation timed out after 30001 milliseconds', 'platform_unavailable'],
        'ECONNRESET' => ['socket hang up (ECONNRESET)', 'platform_unavailable'],
        'DNS' => ['cURL error 6: Could not resolve host: api.outstand.so', 'platform_unavailable'],
        'rate limit' => ['Rate limit exceeded', 'rate_limited'],
        'quota' => ['You have exceeded your quota', 'quota_exceeded'],
        'token' => ['Access token has expired', 'auth'],
        'duplicate' => ['Duplicate content', 'duplicate'],
        'board' => ['board_id is required', 'missing_board'],
        'media spec' => ['Unsupported media format', 'media_spec'],
        'media fetch' => ['Could not download media from URL', 'media_unreachable'],
        'too long' => ['Caption exceeds the maximum length', 'content_too_long'],
        'policy' => ['Violates community guidelines', 'content_rejected'],
        'other' => ['Something odd', 'other'],
    ]);

    it('keeps the coarse analytics code "unknown" for a refusal without a reason', function () {
        expect(PublishFailure::classify(null))->toMatchArray(['error_code' => 'unknown', 'reason_code' => 'network_rejected'])
            ->and(PublishFailure::classify(null, 'tiktok')['reason'])->toBe('TikTok refused it without saying why. Try again, or contact support.');
    });

    it('records each failed attempt once, with its code and reason and never the raw text', function () {
        $user = rtUser();
        $account = rtAccount($user);
        $post = rtSentPost($user, [$account]);

        rtHook($this, rtError($account, 'Duplicate content'))->assertOk();
        rtHook($this, rtError($account, 'Duplicate content'))->assertOk(); // redelivered

        $rows = PublishAttempt::query()->where('social_post_id', $post->id)->get();
        expect($rows)->toHaveCount(1)
            ->and($rows[0]->outcome)->toBe('failed')
            ->and($rows[0]->attempt)->toBe(0)
            ->and($rows[0]->failure_code)->toBe('duplicate')
            ->and($rows[0]->reason)->toBe('Pinterest refused it as a duplicate of a recent post. Change the caption or media and send again.')
            ->and($rows[0]->source)->toBe('webhook');

        $this->actingAs($user)->getJson("/api/v1/social-posts/{$post->ulid}")
            ->assertOk()
            ->assertJsonPath('data.targets.0.fail_code', 'duplicate')
            ->assertJsonMissingPath('data.targets.0.provider_error');
    });

    it('stores network_rejected for a refusal that says nothing', function () {
        $user = rtUser();
        $account = rtAccount($user);
        $post = rtSentPost($user, [$account]);

        rtHook($this, rtError($account, ''))->assertOk();

        expect($post->targets()->sole()->fail_code)->toBe('network_rejected');
    });
});

describe('retry preview', function () {
    it('shows each failed destination with its code, reason and price, and a confirm_token', function () {
        $user = rtUser();
        $post = rtFailedPost($this, $user);

        $this->actingAs($user)->getJson("/api/v1/social-posts/{$post->ulid}/retry-preview")
            ->assertOk()
            ->assertJsonPath('data.retryable', true)
            ->assertJsonPath('data.blocked', null)
            ->assertJsonPath('data.targets.0.platform', 'pinterest')
            ->assertJsonPath('data.targets.0.failure_code', 'duplicate')
            ->assertJsonPath('data.credits.price', Credits::perSend('pinterest'))
            ->assertJsonStructure(['data' => ['confirm_token', 'confirm_expires_at', 'targets' => [['channel_id', 'username', 'usable', 'reason', 'credits']]]]);
    });

    it('says not_failed, with no token, when nothing failed', function () {
        $user = rtUser();
        $post = rtSentPost($user, [rtAccount($user)]);

        $this->actingAs($user)->getJson("/api/v1/social-posts/{$post->ulid}/retry-preview")
            ->assertOk()
            ->assertJsonPath('data.retryable', false)
            ->assertJsonPath('data.blocked.code', 'not_failed')
            ->assertJsonMissingPath('data.confirm_token');
    });
});

describe('POST /social-posts/{id}/retry', function () {
    it('re-queues the failed destination, charges it again and settles on its result', function () {
        Http::fake(['api.outstand.test/v1/posts' => Http::response(['success' => true, 'post' => ['id' => 'op_retry', 'publishedAt' => null]])]);
        $user = rtUser();
        $start = Credits::balance($user);
        $post = rtFailedPost($this, $user);
        $target = $post->targets()->sole();
        expect(Credits::balance($user))->toBe($start); // paid back on failure

        $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/retry")
            ->assertOk()
            ->assertJsonPath('retried', 1)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.targets.0.status', 'pending')
            ->assertJsonPath('data.targets.0.fail_code', null);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.outstand.test/v1/posts'
            && $r['accounts'] === [$target->account->provider_account_id]
            && $r->header('Idempotency-Key')[0] === $post->ulid.'-r'.$target->id.'-1');
        expect(Credits::balance($user))->toBe($start - Credits::priceFor($post, $target->fresh()))
            ->and($target->fresh()->retry_provider_post_id)->toBe('op_retry');

        rtHook($this, ['event' => 'post.published', 'data' => ['postId' => 'op_retry', 'socialAccounts' => [[
            'accountId' => $target->account->provider_account_id, 'platformPostUrl' => 'https://pin.it/x',
        ]]]])->assertOk();

        expect($post->fresh()->status->value)->toBe('published')
            ->and(PublishAttempt::query()->where('social_post_target_id', $target->id)->orderBy('id')->get()
                ->map(fn ($a) => [$a->attempt, $a->outcome, $a->failure_code])->all())
            ->toBe([[0, 'failed', 'duplicate'], [1, 'retry_requested', null], [1, 'published', null]]);
    });

    it('answers 409 not_failed when nothing failed, and a double submit retries once', function () {
        Http::fake(['api.outstand.test/v1/posts' => Http::response(['success' => true, 'post' => ['id' => 'op_retry', 'publishedAt' => null]])]);
        $user = rtUser();
        $post = rtFailedPost($this, $user);

        $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/retry")->assertOk();
        $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/retry")
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_failed');

        Http::assertSentCount(1);

        $sent = rtSentPost($user, [rtAccount($user)]);
        $this->actingAs($user)->postJson("/api/v1/social-posts/{$sent->ulid}/retry")
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_failed');
    });

    it('waits out a network error on the retry itself, still paid for', function () {
        Http::fake(['api.outstand.test/v1/posts' => Http::response(['error' => 'Service Unavailable'], 503)]);
        $user = rtUser();
        $post = rtFailedPost($this, $user);
        $before = Credits::balance($user);

        $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/retry")
            ->assertOk()
            ->assertJsonPath('retried', 0);

        $target = $post->targets()->sole();
        expect($target->status->value)->toBe('pending')
            ->and($target->fail_code)->toBe('platform_unavailable')
            ->and($target->retry_at)->not->toBeNull()
            ->and(Credits::balance($user))->toBe($before - Credits::priceFor($post, $target));
    });

    it('fails again and pays back when the provider refuses the retry for good', function () {
        Http::fake(['api.outstand.test/v1/posts' => Http::response(['error' => 'Duplicate content'], 400)]);
        $user = rtUser();
        $post = rtFailedPost($this, $user);
        $before = Credits::balance($user);

        $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/retry")->assertOk();

        $target = $post->targets()->sole();
        expect($target->status->value)->toBe('failed')
            ->and($target->fail_code)->toBe('duplicate')
            ->and($post->fresh()->status->value)->toBe('failed')
            ->and(Credits::balance($user))->toBe($before);
        expect(PublishAttempt::query()->where('social_post_target_id', $target->id)->orderBy('id')->get()
            ->map(fn ($a) => [$a->attempt, $a->outcome, $a->failure_code])->all())
            ->toBe([[0, 'failed', 'duplicate'], [1, 'retry_requested', null], [1, 'failed', 'duplicate']]);
    });

    it('refuses before charging when the Credits are short or the account must reconnect', function () {
        Http::fake();
        $user = rtUser(Credits::perSend('pinterest'));
        $post = rtFailedPost($this, $user);

        // Spend the balance elsewhere: the retry cannot be paid.
        $other = rtSentPost($user, [rtAccount($user)]);
        expect(Credits::balance($user))->toBe(0);
        $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/retry")->assertUnprocessable();
        expect($post->targets()->sole()->status->value)->toBe('failed');

        $rich = rtUser();
        $stale = rtFailedPost($this, $rich);
        $stale->targets()->sole()->account->update(['status' => 'disconnected']);
        $this->actingAs($rich)->postJson("/api/v1/social-posts/{$stale->ulid}/retry")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'account_reconnect_required');

        Http::assertNothingSent();
        expect($other->targets()->sole()->status->value)->toBe('pending');
    });

    it('needs the retry preview\'s confirm_token from a connected app', function () {
        Http::fake(['api.outstand.test/v1/posts' => Http::response(['success' => true, 'post' => ['id' => 'op_retry', 'publishedAt' => null]])]);
        $user = rtUser();
        $post = rtFailedPost($this, $user);
        $retry = fn (array $body = []) => $this->actingAs($user)->withToken('cs_live_test')
            ->postJson("/api/v1/social-posts/{$post->ulid}/retry", $body);

        $retry()->assertUnprocessable()->assertJsonPath('code', 'confirm_token_required');

        // A send preview's token does not confirm a retry.
        $retry(['confirm_token' => SendConfirmation::issue($user, $post)])->assertStatus(409)->assertJsonPath('code', 'preview_stale');
        Http::assertNothingSent();

        $token = $this->actingAs($user)->getJson("/api/v1/social-posts/{$post->ulid}/retry-preview")->json('data.confirm_token');
        $retry(['confirm_token' => $token])->assertOk()->assertJsonPath('retried', 1);
        Http::assertSentCount(1);
    });

    it('answers 404 for another workspace\'s post', function () {
        $user = rtUser();
        $post = rtFailedPost($this, $user);
        $stranger = rtUser();

        $this->actingAs($stranger)->getJson("/api/v1/social-posts/{$post->ulid}/retry-preview")->assertNotFound();
        $this->actingAs($stranger)->postJson("/api/v1/social-posts/{$post->ulid}/retry")->assertNotFound();
        expect($post->targets()->sole()->status->value)->toBe('failed');
    });
});

describe('token scopes (MCP retry_post)', function () {
    it('lets a read key preview, and only a publish key retry', function () {
        Http::fake(['api.outstand.test/v1/posts' => Http::response(['success' => true, 'post' => ['id' => 'op_retry', 'publishedAt' => null]])]);
        $user = rtUser();
        $post = rtFailedPost($this, $user);
        $key = fn (array $scopes) => $this->actingAs(User::query()->findOrFail($user->id), 'web')
            ->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'scopes' => $scopes])->assertCreated()->json('data.key');
        $read = $key(['read']);
        $publish = $key(['read', 'publish']);
        app('auth')->forgetGuards();

        $token = bearer($this, $read)->getJson("/api/v1/social-posts/{$post->ulid}/retry-preview")
            ->assertOk()->json('data.confirm_token');
        bearer($this, $read)->postJson("/api/v1/social-posts/{$post->ulid}/retry", ['confirm_token' => $token])->assertForbidden();
        Http::assertNothingSent();

        bearer($this, $publish)->postJson("/api/v1/social-posts/{$post->ulid}/retry", ['confirm_token' => $token])
            ->assertOk()->assertJsonPath('retried', 1);
    });
});
