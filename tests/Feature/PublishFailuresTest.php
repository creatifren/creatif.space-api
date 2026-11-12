<?php

use App\Jobs\RetryRateLimitedPublishes;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics;
use App\Support\Credits;
use App\Support\PublishFailure;
use App\Support\SocialPostRelay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * Publish failures (CTO queue, 12 Nov): the 10 Nov 02:05:12Z
 * post_publish_failed trio, and the hardening for real sends — preflight
 * per network, backoff for platform limits, specific reasons, raw provider
 * text kept server-side.
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
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
    Cache::flush();
    Notification::fake();
    $this->analytics = Analytics::fake();
});

function pfUser(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, 30_000, 'Test');

    return $user;
}

function pfAccount(User $user, string $platform, string $provider = 'outstand'): SocialAccount
{
    return SocialAccount::factory()->for($user)->create(['platform' => $platform, 'provider' => $provider]);
}

function pfHook($test, array $payload)
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return $test->call('POST', '/api/v1/webhooks/outstand', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_OUTSTAND_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'whsec_out'),
    ], $body);
}

/** A post already handed to Outstand, one pending target per account. */
function pfSentPost(User $user, array $accounts, string $providerId = 'op_1'): SocialPost
{
    $post = SocialPost::factory()->for($user)->create([
        'provider' => 'outstand', 'provider_post_id' => $providerId, 'status' => 'scheduled', 'scheduled_at' => null,
        'media' => [['url' => 'https://cdn.example.test/clip.mp4']],
    ]);
    foreach ($accounts as $a) {
        $post->targets()->create(['social_account_id' => $a->id]);
    }
    Credits::chargeSend($post);

    return $post;
}

function pfError(SocialPost $post, SocialAccount $account, string $error, ?string $postId = null): array
{
    return ['event' => 'post.error', 'data' => ['postId' => $postId ?? $post->provider_post_id, 'socialAccounts' => [[
        'accountId' => $account->provider_account_id, 'network' => $account->platform->value, 'error' => $error,
    ]]]];
}

describe('classifying a refusal', function () {
    it('reads each platform message as a specific reason', function (?string $raw, string $coarse, string $reason) {
        $f = PublishFailure::classify($raw, 'youtube');

        expect($f['error_code'])->toBe($coarse)->and($f['reason_code'])->toBe($reason);
    })->with([
        'rate limit' => ['Rate limit exceeded, try again later', 'rate_limited', 'rate_limited'],
        'HTTP 429' => ['Request failed with status 429 Too Many Requests', 'rate_limited', 'rate_limited'],
        'YouTube shared quota' => ['Shared YouTube uploads have reached the daily limit. Retry after the quota resets. [youtube_upload_error:daily_limit_reached; retryAt=2026-10-07T07:00:00.000Z]', 'rate_limited', 'quota_exceeded'],
        'YouTube quotaExceeded' => ['The request cannot be completed because you have exceeded your quota.', 'rate_limited', 'quota_exceeded'],
        'YouTube needs video' => ['YouTube requires a video file', 'media', 'needs_video'],
        'Pinterest board' => ['board_ids is required for Pinterest', 'missing_field', 'missing_board'],
        'Pinterest board 2' => ['Missing board for pin', 'missing_field', 'missing_board'],
        'aspect ratio' => ['Video aspect ratio not supported', 'media', 'media_spec'],
        'expired token' => ['Access token has expired', 'auth', 'auth'],
        'too long' => ['Caption is too long', 'content', 'content_too_long'],
        'duplicate' => ['Duplicate content', 'duplicate', 'duplicate'],
        'empty' => [null, 'unknown', 'network_rejected'],
        'odd' => ['Something odd', 'other', 'other'],
    ]);

    // The old substring match read these as rate limits / auth problems.
    it('no longer mistakes words that merely contain "rate" or "auth"', function (string $raw) {
        expect(PublishFailure::classify($raw)['reason_code'])->toBe('other');
    })->with([
        'Failed to generate the upload session',
        'The author of this page has restricted posting',
        'Separate the thumbnail from the video',
    ]);

    it('names the network and keeps the platform words for an unmatched refusal', function () {
        expect(PublishFailure::classify('Something odd [code:42]', 'pinterest')['reason'])
            ->toBe('Pinterest refused it: Something odd');
    });
});

describe('the 10 Nov 02:05:12Z burst (Post for Me backlog)', function () {
    // Post ids 01M4MN…/01M4MM… were made on 11 Oct, before Outstand
    // (8 Nov): Post for Me posts. Reconcile settled them on 10 Nov.
    it('settles a legacy relay\'s failures without a post_publish_failed event or a retry', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['data' => [
            ['post_id' => 'pfm_1', 'social_account_id' => 'pfm_yt', 'success' => false, 'error' => 'Rate limit exceeded'],
            ['post_id' => 'pfm_1', 'social_account_id' => 'pfm_pin', 'success' => false, 'error' => 'board_ids is required'],
        ]])]);
        $user = pfUser();
        $yt = SocialAccount::factory()->for($user)->create(['platform' => 'youtube', 'provider' => 'postforme', 'provider_account_id' => 'pfm_yt']);
        $pin = SocialAccount::factory()->for($user)->create(['platform' => 'pinterest', 'provider' => 'postforme', 'provider_account_id' => 'pfm_pin']);
        $post = SocialPost::factory()->for($user)->create([
            'provider' => 'postforme', 'provider_post_id' => 'pfm_1', 'status' => 'scheduled', 'scheduled_at' => now()->subMonth(),
        ]);
        $post->targets()->create(['social_account_id' => $yt->id]);
        $post->targets()->create(['social_account_id' => $pin->id]);

        app(SocialPostRelay::class)->reconcile($user);

        $targets = $post->targets()->orderBy('id')->get();
        expect($targets->pluck('status')->map->value->all())->toBe(['failed', 'failed'])
            ->and($targets->pluck('fail_code')->all())->toBe(['rate_limited', 'missing_board'])
            ->and($targets[0]->retry_at)->toBeNull();
        $this->analytics->assertNotCaptured('post_publish_failed');
    });
});

describe('preflight', function () {
    it('refuses an image for YouTube before anything is charged or sent', function () {
        Http::fake();
        $user = pfUser();
        $yt = pfAccount($user, 'youtube');

        $this->actingAs($user)->postJson('/api/v1/social-posts', [
            'caption' => 'Launch', 'account_ids' => [$yt->ulid],
            'media' => [['url' => 'https://cdn.example.test/cover.jpg']],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'publish_preflight_failed')
            ->assertJsonPath('problems.0.platform', 'youtube')
            ->assertJsonPath('problems.0.code', 'needs_video')
            ->assertJsonPath('message', 'YouTube only takes video. Attach one video file.');

        Http::assertNothingSent();
        expect(SocialPost::query()->count())->toBe(0)->and(Credits::balance($user))->toBe(30_000);
    });

    it('refuses YouTube with more than the one video', function () {
        Http::fake();
        $user = pfUser();
        $yt = pfAccount($user, 'youtube');

        $this->actingAs($user)->postJson('/api/v1/social-posts', [
            'caption' => 'Launch', 'account_ids' => [$yt->ulid],
            'media' => [['url' => 'https://cdn.example.test/a.mp4'], ['url' => 'https://cdn.example.test/b.jpg']],
        ])->assertUnprocessable()->assertJsonPath('problems.0.code', 'needs_video');
    });

    it('needs media on Instagram and TikTok, and 500 characters at most on Threads', function () {
        Http::fake();
        $user = pfUser();
        $ig = pfAccount($user, 'instagram');
        $tt = pfAccount($user, 'tiktok');
        $th = pfAccount($user, 'threads');

        $problems = $this->actingAs($user)->postJson('/api/v1/social-posts', [
            'caption' => str_repeat('a', 501), 'account_ids' => [$ig->ulid, $tt->ulid, $th->ulid],
        ])->assertUnprocessable()->json('problems');

        expect(collect($problems)->mapWithKeys(fn ($p) => [$p['platform'] => $p['code']])->sortKeys()->all())
            ->toBe(['instagram' => 'needs_media', 'threads' => 'content_too_long', 'tiktok' => 'needs_media']);
        Http::assertNothingSent();
    });

    it('asks for a Pinterest board when the account has several', function () {
        Http::fake(['api.outstand.test/v1/pinterest/accounts/*/boards' => Http::response(['success' => true, 'data' => [
            ['id' => 'b1', 'name' => 'Work'], ['id' => 'b2', 'name' => 'Ideas'],
        ]])]);
        $user = pfUser();
        $pin = pfAccount($user, 'pinterest');

        $this->actingAs($user)->postJson('/api/v1/social-posts', [
            'caption' => 'Pin it', 'account_ids' => [$pin->ulid], 'media' => [['url' => 'https://cdn.example.test/a.jpg']],
        ])->assertUnprocessable()
            ->assertJsonPath('problems.0.code', 'missing_board')
            ->assertJsonPath('message', 'Pinterest needs a board for this Pin. Choose a board and send again.');

        $this->getJson("/api/v1/social-accounts/{$pin->ulid}/pinterest-boards")
            ->assertOk()->assertJsonPath('data.1', ['id' => 'b2', 'name' => 'Ideas']);
    });

    it('fills the only board in and sends board, title and link to Outstand', function () {
        Http::fake([
            'api.outstand.test/v1/pinterest/accounts/*/boards' => Http::response(['success' => true, 'data' => [['id' => 'b9', 'name' => 'All']]]),
            'api.outstand.test/v1/posts' => Http::response(['success' => true, 'post' => ['id' => 'op_pin', 'publishedAt' => null]]),
        ]);
        $user = pfUser();
        $pin = pfAccount($user, 'pinterest');

        $this->actingAs($user)->postJson('/api/v1/social-posts', [
            'caption' => 'Pin it', 'account_ids' => [$pin->ulid], 'media' => [['url' => 'https://cdn.example.test/a.jpg']],
            'options' => [$pin->ulid => ['title' => 'Autumn set', 'link' => 'https://creatif.space/p']],
        ])->assertCreated();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.outstand.test/v1/posts'
            && $r['pinterest'] === ['board_id' => 'b9', 'title' => 'Autumn set', 'link' => 'https://creatif.space/p']);
        expect($pin->fresh()->id)->toBe(SocialPost::query()->sole()->targets()->sole()->social_account_id)
            ->and(SocialPost::query()->sole()->targets()->sole()->configuration['board_id'])->toBe('b9');
    });

    it('saves a draft regardless, and checks it when it is sent', function () {
        Http::fake();
        $user = pfUser();
        $yt = pfAccount($user, 'youtube');

        $id = $this->actingAs($user)->postJson('/api/v1/social-posts', [
            'caption' => 'Later', 'account_ids' => [$yt->ulid], 'draft' => true,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/social-posts/{$id}/send")
            ->assertUnprocessable()->assertJsonPath('problems.0.code', 'needs_video');
        Http::assertNothingSent();
        expect(Credits::balance($user))->toBe(30_000);
    });
});

describe('rate limits are waited out, not failed', function () {
    it('keeps the destination pending, paid for and silent, then retries it on its own', function () {
        Http::fake(['api.outstand.test/v1/posts' => Http::response(['success' => true, 'post' => ['id' => 'op_retry', 'publishedAt' => null]])]);
        $user = pfUser();
        $yt = pfAccount($user, 'youtube');
        $pin = pfAccount($user, 'pinterest');
        $post = pfSentPost($user, [$yt, $pin]);
        $balance = Credits::balance($user);

        pfHook($this, pfError($post, $yt, 'Rate limit exceeded'))->assertOk();

        $target = $post->targets()->where('social_account_id', $yt->id)->sole();
        expect($target->status->value)->toBe('pending')
            ->and($target->fail_code)->toBe('rate_limited')
            ->and($target->provider_error)->toBe('Rate limit exceeded')
            ->and($target->retry_at->between(now()->addMinutes(1), now()->addMinutes(3)))->toBeTrue()
            ->and(Credits::balance($user))->toBe($balance);
        $this->analytics->assertNotCaptured('post_publish_failed');

        // Not yet due: nothing goes.
        (new RetryRateLimitedPublishes)->handle(app(SocialPostRelay::class));
        Http::assertNothingSent();

        $this->travel(3)->minutes();
        (new RetryRateLimitedPublishes)->handle(app(SocialPostRelay::class));

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.outstand.test/v1/posts'
            && $r['accounts'] === [$yt->provider_account_id]
            && $r->header('Idempotency-Key')[0] === $post->ulid.'-r'.$target->id.'-1');
        $target->refresh();
        expect($target->attempts)->toBe(1)
            ->and($target->retry_provider_post_id)->toBe('op_retry')
            ->and($target->retry_at)->toBeNull();

        // A redelivered failure of the first attempt changes nothing now.
        pfHook($this, pfError($post, $yt, 'Rate limit exceeded'))->assertOk();
        expect($target->fresh()->status->value)->toBe('pending');

        // The retry's own result settles it.
        pfHook($this, ['event' => 'post.published', 'data' => ['postId' => 'op_retry', 'socialAccounts' => [[
            'accountId' => $yt->provider_account_id, 'platformPostUrl' => 'https://youtu.be/x',
        ]]]])->assertOk();

        expect($target->fresh()->status->value)->toBe('published')
            ->and($target->fresh()->platform_url)->toBe('https://youtu.be/x');
        $this->analytics->assertCapturedOnce('post_published');
    });

    it('waits until the platform\'s own retryAt for a daily quota', function () {
        $user = pfUser();
        $yt = pfAccount($user, 'youtube');
        $post = pfSentPost($user, [$yt]);
        $at = now()->addHours(5)->utc()->format('Y-m-d\TH:i:s.000\Z');

        pfHook($this, pfError($post, $yt, "Shared YouTube uploads have reached the daily limit. [youtube_upload_error:daily_limit_reached; retryAt={$at}]"))->assertOk();

        $target = $post->targets()->sole();
        expect($target->fail_code)->toBe('quota_exceeded')
            ->and($target->retry_at->getTimestamp())->toBe(CarbonImmutable::parse($at)->getTimestamp());
    });

    it('fails for good after the last retry, pays the publish back and reports it once', function () {
        Http::fake(['api.outstand.test/v1/posts' => Http::sequence()
            ->push(['success' => true, 'post' => ['id' => 'r1', 'publishedAt' => null]])
            ->push(['success' => true, 'post' => ['id' => 'r2', 'publishedAt' => null]])
            ->push(['success' => true, 'post' => ['id' => 'r3', 'publishedAt' => null]])]);
        $user = pfUser();
        $yt = pfAccount($user, 'youtube');
        $post = pfSentPost($user, [$yt]);
        $start = Credits::balance($user);

        pfHook($this, pfError($post, $yt, 'Rate limit exceeded'))->assertOk();
        foreach (['r1', 'r2', 'r3'] as $id) {
            $this->travel(2)->hours();
            (new RetryRateLimitedPublishes)->handle(app(SocialPostRelay::class));
            pfHook($this, pfError($post, $yt, 'Rate limit exceeded', $id))->assertOk();
        }

        $target = $post->targets()->sole();
        expect($target->status->value)->toBe('failed')
            ->and($target->attempts)->toBe(3)
            ->and($target->fail_reason)->toBe('YouTube is limiting how often this account can post right now. Try again in a little while.')
            ->and(Credits::balance($user))->toBe($start + Credits::PER_SEND);
        $m = $this->analytics->assertCapturedWithKeys('post_publish_failed', ['post_id', 'network', 'error_code', 'reason_code', 'attempts', 'provider', 'source']);
        expect($m['properties']['reason_code'])->toBe('rate_limited')->and($m['properties']['attempts'])->toBe(4);
        $this->analytics->assertCapturedOnce('post_publish_failed');
    });

    it('does not retry what waiting cannot fix', function () {
        $user = pfUser();
        $pin = pfAccount($user, 'pinterest');
        $post = pfSentPost($user, [$pin]);

        pfHook($this, pfError($post, $pin, 'board_id is required'))->assertOk();

        expect($post->targets()->sole()->status->value)->toBe('failed')
            ->and($post->targets()->sole()->fail_code)->toBe('missing_board');
    });
});

describe('the platform\'s words stay server-side', function () {
    it('shows a person a reason and a code, never the raw provider text', function () {
        $user = pfUser();
        $ig = pfAccount($user, 'instagram');
        $post = pfSentPost($user, [$ig]);

        pfHook($this, pfError($post, $ig, 'Access token has expired for @studio_private'))->assertOk();

        $this->actingAs($user)->getJson("/api/v1/social-posts/{$post->ulid}")
            ->assertOk()
            ->assertJsonPath('data.targets.0.fail_code', 'auth')
            ->assertJsonPath('data.targets.0.fail_reason', 'Instagram needs you to reconnect this account in Creatif Space → Channels.')
            ->assertDontSee('studio_private');

        expect($post->targets()->sole()->provider_error)->toBe('Access token has expired for @studio_private')
            ->and(json_encode($this->analytics->sent))->not->toContain('studio_private');
    });
});
