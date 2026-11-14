<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics;
use App\Support\ChannelHealth;
use App\Support\Credits;
use App\Support\PublishFailure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * First-user UX test (14 Nov 2026), P1s on the API side:
 * - b202611141400: a post with 5 of 8 destinations published read
 *   "Published", Posts › Failed showed 0 and Today's counts were off. One
 *   definition now (PostDelivery): partial = some out, some failed; it is
 *   in ?status=failed and in meta.counts.failed.
 * - b202611141401: each destination carries its failure code, coarse group,
 *   the person-readable reason and the network's own words separately.
 * - b202611141403: Channels said "verified" while publishing to the account
 *   failed for its sign-in. A refusal for the sign-in now fails the
 *   channel's health (reconnect_required); a destination that goes out
 *   makes it ok again.
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

function pdsUser(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, 30_000, 'Test');

    return $user;
}

function pdsAccount(User $user, string $platform = 'instagram', array $attrs = []): SocialAccount
{
    return SocialAccount::factory()->for($user)->create(['platform' => $platform, 'provider' => 'outstand', ...$attrs]);
}

/**
 * A post in a final or open state with destinations in the given states.
 *
 * @param  list<string>  $targetStates
 */
function pdsPost(User $user, string $status, array $targetStates, ?string $platform = 'instagram'): SocialPost
{
    $post = SocialPost::factory()->for($user)->create([
        'provider' => 'outstand', 'provider_post_id' => null, 'status' => $status, 'scheduled_at' => null,
    ]);
    foreach ($targetStates as $state) {
        $post->targets()->create([
            'social_account_id' => pdsAccount($user, $platform)->id,
            'status' => $state,
            'fail_code' => $state === 'failed' ? 'media_spec' : null,
            'fail_reason' => $state === 'failed' ? PublishFailure::reason('media_spec', $platform) : null,
        ]);
    }

    return $post;
}

function pdsHook(TestCase $test, array $payload): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return $test->call('POST', '/api/v1/webhooks/outstand', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_OUTSTAND_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'whsec_out'),
    ], $body);
}

/** A post handed to Outstand, one pending destination per account. */
function pdsSent(User $user, array $accounts, string $providerId): SocialPost
{
    $post = SocialPost::factory()->for($user)->create([
        'provider' => 'outstand', 'provider_post_id' => $providerId, 'status' => 'scheduled', 'scheduled_at' => null,
    ]);
    foreach ($accounts as $a) {
        $post->targets()->create(['social_account_id' => $a->id]);
    }
    Credits::chargeSend($post);

    return $post;
}

function pdsError(SocialPost $post, SocialAccount $account, string $error): array
{
    return ['event' => 'post.error', 'data' => ['postId' => $post->provider_post_id, 'socialAccounts' => [[
        'accountId' => $account->provider_account_id, 'network' => $account->platform->value, 'error' => $error,
    ]]]];
}

function pdsPublished(SocialPost $post, SocialAccount $account): array
{
    return ['event' => 'post.published', 'timestamp' => now()->toIso8601String(), 'data' => [
        'postId' => $post->provider_post_id, 'socialAccounts' => [[
            'accountId' => $account->provider_account_id, 'network' => $account->platform->value,
            'platformPostUrl' => 'https://bsky.app/profile/x/post/1',
        ]],
    ]];
}

function pdsChannels(TestCase $test, User $user): TestResponse
{
    Cache::put('social-self-heal:'.$user->ulid, true, now()->addHour());

    return $test->actingAs($user)->getJson('/api/v1/social-accounts')->assertOk();
}

describe('delivery state (b202611141400)', function () {
    it('reads 5 of 8 published as partial, lists it under Failed, and the counts agree', function () {
        $user = pdsUser();
        $partial = pdsPost($user, 'published', [...array_fill(0, 5, 'published'), ...array_fill(0, 3, 'failed')]);
        $all = pdsPost($user, 'published', ['published', 'published']);
        $none = pdsPost($user, 'failed', ['failed']);
        $open = pdsPost($user, 'scheduled', ['published', 'pending']);
        pdsPost($user, 'draft', []);

        $list = $this->actingAs($user)->getJson('/api/v1/social-posts')->assertOk();
        $byId = collect($list->json('data'))->keyBy('id');

        expect($byId[$partial->ulid]['delivery'])->toBe(['state' => 'partial', 'published' => 5, 'failed' => 3, 'pending' => 0, 'total' => 8])
            ->and($byId[$partial->ulid]['status'])->toBe('published')
            ->and($byId[$all->ulid]['delivery']['state'])->toBe('published')
            ->and($byId[$none->ulid]['delivery']['state'])->toBe('failed')
            ->and($byId[$open->ulid]['delivery'])->toBe(['state' => 'pending', 'published' => 1, 'failed' => 0, 'pending' => 1, 'total' => 2])
            ->and(collect($list->json('data'))->firstWhere('status', 'draft')['delivery'])->toBeNull();

        $failed = $this->actingAs($user)->getJson('/api/v1/social-posts?status=failed')->assertOk();
        expect(collect($failed->json('data'))->pluck('id')->sort()->values()->all())
            ->toBe(collect([$partial->ulid, $none->ulid])->sort()->values()->all());

        $this->actingAs($user)->getJson('/api/v1/social-posts?status=partial')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $partial->ulid);

        // Published keeps every post that went out, partial ones too.
        expect(collect($this->actingAs($user)->getJson('/api/v1/social-posts?status=published')->json('data'))->pluck('id')->all())
            ->toContain($partial->ulid, $all->ulid)->toHaveCount(2);

        $counts = $list->json('meta.counts');
        expect($counts)->toBe(['draft' => 1, 'scheduled' => 1, 'published' => 2, 'failed' => 2, 'partial' => 1, 'cancelled' => 0]);
        foreach (['draft', 'scheduled', 'published', 'failed', 'partial', 'cancelled'] as $status) {
            $rows = $this->actingAs($user)->getJson('/api/v1/social-posts?status='.$status)->assertOk();
            expect(count($rows->json('data')))->toBe($counts[$status], $status)
                ->and($rows->json('meta.counts'))->toBe($counts);
        }
    });

    it('counts under the same window and channel as the list, not the limit', function () {
        $user = pdsUser();
        pdsPost($user, 'published', ['published', 'failed']);
        pdsPost($user, 'failed', ['failed']);
        $old = pdsPost($user, 'failed', ['failed']);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->actingAs($user)->getJson('/api/v1/social-posts?status=failed&from='.urlencode(now()->subDay()->toIso8601String()).'&limit=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.counts.failed', 2)
            ->assertJsonPath('meta.counts.partial', 1);
    });

    it('turns a post partial when one destination fails after another went out', function () {
        $user = pdsUser();
        $ig = pdsAccount($user, 'instagram');
        $x = pdsAccount($user, 'x');
        $post = pdsSent($user, [$ig, $x], 'op_part');

        pdsHook($this, pdsPublished($post, $ig))->assertOk();
        pdsHook($this, pdsError($post, $x, 'Caption is too long'))->assertOk();

        expect($post->fresh()->status->value)->toBe('published');
        $this->actingAs($user)->getJson('/api/v1/social-posts/'.$post->ulid)->assertOk()
            ->assertJsonPath('data.delivery.state', 'partial')
            ->assertJsonPath('data.delivery.published', 1)
            ->assertJsonPath('data.delivery.failed', 1);
        $this->actingAs($user)->getJson('/api/v1/social-posts?status=failed')->assertOk()
            ->assertJsonPath('data.0.id', $post->ulid);
    });

    it('refuses an unknown status filter', function () {
        $this->actingAs(pdsUser())->getJson('/api/v1/social-posts?status=nope')->assertUnprocessable();
    });
});

describe('failure detail per destination (b202611141401)', function () {
    it('exposes the code, its group, the reason and the network words separately', function () {
        $user = pdsUser();
        $bsky = pdsAccount($user, 'bluesky');
        $ig = pdsAccount($user, 'instagram');
        $pin = pdsAccount($user, 'pinterest');
        $post = pdsSent($user, [$bsky, $ig, $pin], 'op_codes');

        pdsHook($this, pdsError($post, $bsky, 'Failed to upload video: 401 for @bsky_private'))->assertOk();
        pdsHook($this, pdsError($post, $pin, 'Unsupported codec h265 [code:42]'))->assertOk();
        pdsHook($this, pdsPublished($post, $ig))->assertOk();

        $res = $this->actingAs($user)->getJson('/api/v1/social-posts/'.$post->ulid)->assertOk()->assertDontSee('bsky_private');
        $targets = collect($res->json('data.targets'))->keyBy('account.platform');

        expect($targets['bluesky'])
            ->fail_code->toBe('video_upload_refused')
            ->error_code->toBe('media')
            ->fail_reason->toBe(PublishFailure::reason('video_upload_refused', 'bluesky'))
            // Not a detail code: the raw words stay server-side.
            ->provider_message->toBeNull()
            ->retryable->toBeFalse()
            ->reconnect_required->toBeFalse()
            ->retry_at->toBeNull()
            ->retry_reason->toBeNull()
            ->and($targets['pinterest'])
            ->fail_code->toBe('media_spec')
            ->error_code->toBe('media')
            ->provider_message->toBe('Unsupported codec h265')
            ->and($targets['instagram'])
            ->fail_code->toBeNull()
            ->error_code->toBeNull()
            ->provider_message->toBeNull()
            ->retryable->toBeFalse();
    });

    it('marks a waiting destination retryable with its retry time and reason', function () {
        $user = pdsUser();
        $yt = pdsAccount($user, 'youtube');
        $post = pdsSent($user, [$yt], 'op_wait');

        pdsHook($this, pdsError($post, $yt, 'Too many requests, slow down'))->assertOk();

        $this->actingAs($user)->getJson('/api/v1/social-posts/'.$post->ulid)->assertOk()
            ->assertJsonPath('data.delivery.state', 'pending')
            ->assertJsonPath('data.targets.0.status', 'pending')
            ->assertJsonPath('data.targets.0.fail_code', 'rate_limited')
            ->assertJsonPath('data.targets.0.error_code', 'rate_limited')
            ->assertJsonPath('data.targets.0.retryable', true)
            ->assertJsonPath('data.targets.0.retry_at', fn ($v) => is_string($v) && $v !== '')
            ->assertJsonPath('data.targets.0.retry_reason', fn ($v) => is_string($v) && str_contains($v, 'sent again automatically'));
    });

    it('lists every reason code with a coarse group', function () {
        foreach (PublishFailure::codes() as $code) {
            expect(PublishFailure::coarse($code))->toBeString()->not->toBe('');
        }
        expect(PublishFailure::codes())->toContain('auth', 'video_upload_refused', 'capacity_pending', 'not_confirmed');
    });
});

describe('channel health from publishing (b202611141403)', function () {
    it('fails the channel when a publish is refused for its sign-in, until one goes out', function () {
        $user = pdsUser();
        $bsky = pdsAccount($user, 'bluesky', ['connected_at' => now()->subDay()]);
        $bsky->forceFill(['health_status' => 'ok', 'last_verified_at' => now()->subHour()])->save();

        $post = pdsSent($user, [$bsky], 'op_auth');
        pdsHook($this, pdsError($post, $bsky, 'Request failed: 401 Unauthorized'))->assertOk();

        expect($bsky->fresh())->health_status->toBe('failed')->health_reason->toBe('publish_unauthorized');
        $this->actingAs($user)->getJson('/api/v1/social-posts/'.$post->ulid)->assertOk()
            ->assertJsonPath('data.targets.0.fail_code', 'auth')
            ->assertJsonPath('data.targets.0.error_code', 'auth')
            ->assertJsonPath('data.targets.0.reconnect_required', true);
        pdsChannels($this, $user)
            ->assertJsonPath('data.0.status', 'connected')
            ->assertJsonPath('data.0.health_status', 'failed')
            ->assertJsonPath('data.0.health_reason', ChannelHealth::REASONS['publish_unauthorized'])
            ->assertJsonPath('data.0.needs_attention', true)
            ->assertJsonPath('data.0.reconnect_required', true);

        $next = pdsSent($user, [$bsky], 'op_ok');
        pdsHook($this, pdsPublished($next, $bsky))->assertOk();

        expect($bsky->fresh())->health_status->toBe('ok')->health_reason->toBeNull();
        pdsChannels($this, $user)
            ->assertJsonPath('data.0.health_status', 'ok')
            ->assertJsonPath('data.0.needs_attention', false)
            ->assertJsonPath('data.0.reconnect_required', false);
    });

    it('leaves the channel healthy for a refusal that is not about its sign-in', function (string $error) {
        $user = pdsUser();
        $bsky = pdsAccount($user, 'bluesky');
        $bsky->forceFill(['health_status' => 'ok', 'last_verified_at' => now()])->save();

        pdsHook($this, pdsError(pdsSent($user, [$bsky], 'op_media'), $bsky, $error))->assertOk();

        expect($bsky->fresh())->health_status->toBe('ok')->health_reason->toBeNull();
    })->with([
        'Bluesky video needs a verified email' => ['Failed to upload video: 401'],
        'media spec' => ['Video aspect ratio not supported'],
    ]);

    it('clears a publish failure once the channel is reconnected', function () {
        $user = pdsUser();
        $bsky = pdsAccount($user, 'bluesky', ['connected_at' => now()->subDay()]);
        pdsHook($this, pdsError(pdsSent($user, [$bsky], 'op_auth2'), $bsky, 'Token has expired'))->assertOk();
        expect($bsky->fresh()->health_status)->toBe('failed');

        $this->travel(1)->minutes();
        $bsky->forceFill(['connected_at' => now()])->save();

        pdsChannels($this, $user)
            ->assertJsonPath('data.0.health_status', null)
            ->assertJsonPath('data.0.reconnect_required', false);
    });
});
