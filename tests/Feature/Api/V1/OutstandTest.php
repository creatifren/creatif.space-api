<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OutstandService;
use App\Support\Credits;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Outstand as the relay: what we send it, what we make of what it sends.
 * Payload shapes are from https://www.outstand.so/docs (2026-11-08); no
 * real key was used.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0]]);
    }
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
        'services.outstand.webhook_secret' => 'whsec_out',
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
        'app.frontend_url' => 'https://app.test',
    ]);
});

function outstandUser(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, 30_000, 'Test');

    return $user;
}

function outstandAccount(User $user, string $platform = 'instagram', array $attrs = []): SocialAccount
{
    return SocialAccount::factory()->for($user)->create(['platform' => $platform, 'provider' => 'outstand'] + $attrs);
}

function outstandHook($test, array $payload, ?string $secret = 'whsec_out')
{
    $body = json_encode($payload);
    $headers = ['Content-Type' => 'application/json'];
    if ($secret !== null) {
        $headers['X-Outstand-Signature'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return $test->call('POST', '/api/v1/webhooks/outstand', [], [], [], $test->transformHeadersToServerVars($headers), $body);
}

describe('connecting', function () {
    it('asks for an auth URL tagged with the user and our sealed return', function () {
        Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'data' => ['auth_url' => 'https://www.outstand.so/app/api/socials/instagram/o?state=s']])]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'instagram'])
            ->assertOk()
            ->assertJsonPath('data.url', 'https://www.outstand.so/app/api/socials/instagram/o?state=s');

        Http::assertSent(function (HttpRequest $r) use ($user) {
            parse_str((string) parse_url($r['redirect_uri'], PHP_URL_QUERY), $q);

            return $r->url() === 'https://api.outstand.test/v1/social-networks/instagram/auth-url'
                && $r->hasHeader('Authorization', 'Bearer sk_test')
                && $r['tenant_id'] === $user->ulid
                && $r['force_account_selection'] === true
                && str_starts_with($r['redirect_uri'], route('api.v1.webhooks.outstand.return'))
                && OutstandService::unseal($q['state'] ?? null) === $user->ulid;
        });
    });

    it('connects Bluesky directly and points the browser at the connect page', function () {
        Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'data' => ['id' => 'bs1']], 201)]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'bluesky', 'handle' => '@rani.bsky.social', 'app_password' => 'xxxx-xxxx'])
            ->assertOk()
            ->assertJsonPath('data.url', 'https://app.test/social/connect?social=connected');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/social-accounts/bluesky')
            && $r['handle'] === 'rani.bsky.social'
            && $r['appPassword'] === 'xxxx-xxxx'
            && $r['tenantId'] === $user->ulid);
    });

    it('syncs the tenant\'s accounts and retires rows another relay holds', function () {
        $user = User::factory()->create();
        $old = SocialAccount::factory()->for($user)->create(['provider' => 'postforme']);
        Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'data' => [[
            'id' => 'Kx7vQ', 'network' => 'threads', 'username' => 'rani', 'nickname' => 'Rani',
            'profile_picture_url' => 'https://cdn/p.jpg', 'tenant_id' => $user->ulid, 'isActive' => 1,
        ]], 'count' => 1, 'total' => 1])]);

        $this->actingAs($user)->postJson('/api/v1/social-accounts/sync')->assertOk();

        $new = $user->socialAccounts()->where('provider_account_id', 'Kx7vQ')->first();
        expect($new->provider)->toBe('outstand')
            ->and($new->platform->value)->toBe('threads')
            ->and($new->profile_photo_url)->toBe('https://cdn/p.jpg')
            ->and($old->fresh()->status->value)->toBe('disconnected');

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'tenantId='.$user->ulid));
    });

    it('lands a plain return on the connect page', function () {
        $this->get('/api/v1/webhooks/outstand?state='.urlencode(OutstandService::seal('u1')).'&success=true&account_id=Kx7vQ')
            ->assertRedirect('https://app.test/social/connect?social=connected&success=true');
    });

    it('refuses a return without a good state', function () {
        $this->get('/api/v1/webhooks/outstand?state=forged&session=abc')
            ->assertRedirect('https://app.test/social/connect?social=connected&isSuccess=false&error=The+sign-in+expired.+Try+connecting+again.');
    });

    it('finalizes a pending sign-in that offers one page', function () {
        Http::fake([
            'api.outstand.test/v1/social-accounts/pending/sess1/finalize' => Http::response(['success' => true, 'connectedAccounts' => []]),
            'api.outstand.test/v1/social-accounts/pending/sess1' => Http::response(['success' => true, 'data' => [
                'network' => 'facebook', 'availablePages' => [['id' => 'pg1', 'type' => 'page', 'name' => 'Acme']],
            ]]),
        ]);

        $this->get('/api/v1/webhooks/outstand?state='.urlencode(OutstandService::seal('u1')).'&session=sess1')
            ->assertRedirect('https://app.test/social/connect?social=connected');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/finalize') && $r['selectedPageIds'] === ['pg1']);
    });

    it('parks a many-page sign-in for the user to choose, then connects the chosen', function () {
        $user = outstandUser();
        Http::fake([
            'api.outstand.test/v1/social-accounts/pending/sess2/finalize' => Http::response(['success' => true, 'connectedAccounts' => []]),
            'api.outstand.test/v1/social-accounts/pending/sess2' => Http::response(['success' => true, 'data' => [
                'network' => 'linkedin', 'availablePages' => [
                    ['id' => 'me', 'type' => 'personal', 'name' => 'Rani'],
                    ['id' => 'org1', 'type' => 'organization', 'name' => 'Acme'],
                ],
            ]]),
        ]);

        $this->get('/api/v1/webhooks/outstand?state='.urlencode(OutstandService::seal($user->ulid)).'&session=sess2')
            ->assertRedirect('https://app.test/social/connect?social=pending&network=linkedin');

        $this->actingAs($user)->getJson('/api/v1/social-accounts/pending')
            ->assertOk()
            ->assertJsonPath('data.network', 'linkedin')
            ->assertJsonCount(2, 'data.pages');

        $this->actingAs($user)->postJson('/api/v1/social-accounts/pending', ['page_ids' => ['nope']])
            ->assertUnprocessable();

        $this->actingAs($user)->postJson('/api/v1/social-accounts/pending', ['page_ids' => ['org1']])
            ->assertOk()
            ->assertJsonPath('data.connected', 1);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/finalize') && $r['selectedPageIds'] === ['org1']);
        expect(Cache::has("outstand-pending:{$user->ulid}"))->toBeFalse();
    });

    it('claims by a fresh sign-in under the claimant, with no re-tagging', function () {
        $user = outstandUser();
        Http::fake([
            'api.outstand.test/v1/social-accounts?*' => Http::response(['success' => true, 'data' => [[
                'id' => 'Kx7vQ', 'network' => 'instagram', 'username' => 'rani', 'tenant_id' => 'someone-else', 'isActive' => 1,
            ]]]),
            'api.outstand.test/v1/social-networks/instagram/auth-url' => Http::response(['success' => true, 'data' => ['auth_url' => 'https://auth.test/ig']]),
        ]);

        $this->actingAs($user)->postJson('/api/v1/social-accounts/claim', ['account_id' => 'Kx7vQ'])
            ->assertOk()
            ->assertJsonPath('data.url', 'https://auth.test/ig');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/auth-url') && $r['tenant_id'] === $user->ulid);
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'PATCH');
    });
});

describe('posting', function () {
    it('translates the post, charges, and keeps the Outstand id', function () {
        Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'post' => ['id' => '9mTx4', 'publishedAt' => null]])]);
        $user = outstandUser();
        $ig = outstandAccount($user, 'instagram');
        $tt = outstandAccount($user, 'tiktok');
        $at = now()->addDay()->setTime(9, 30);

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', [
                'caption' => 'Winter Noel.',
                'account_ids' => [$ig->ulid, $tt->ulid],
                'captions' => [$ig->ulid => 'For the gram.'],
                'media' => [['url' => 'https://assets.creatif.space/reel.mp4', 'thumbnail_url' => 'https://assets.creatif.space/cover.jpg']],
                'type' => 'reels',
                'scheduled_at' => $at->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled');

        $post = SocialPost::query()->first();
        expect($post->provider)->toBe('outstand')
            ->and($post->provider_post_id)->toBe('9mTx4')
            ->and(Credits::balance($user))->toBe(30_000 - 2 * Credits::PER_SEND);

        Http::assertSent(function (HttpRequest $r) use ($ig, $tt, $at, $post) {
            return $r->url() === 'https://api.outstand.test/v1/posts'
                && $r->hasHeader('Idempotency-Key', $post->ulid)
                && $r['accounts'] === [$ig->provider_account_id, $tt->provider_account_id]
                && $r['containers'] === [['content' => 'Winter Noel.', 'media' => [['url' => 'https://assets.creatif.space/reel.mp4', 'filename' => 'reel.mp4']]]]
                && $r['scheduledAt'] === $at->utc()->format('Y-m-d\TH:i:s\Z')
                && $r['instagram'] === ['content' => 'For the gram.', 'reelCoverUrl' => 'https://assets.creatif.space/cover.jpg']
                && $r['tiktok'] === ['postMode' => 'DIRECT_POST', 'privacyLevel' => 'PUBLIC_TO_EVERYONE'];
        });
    });

    it('gives the Credits back when Outstand refuses', function () {
        Http::fake(['api.outstand.test/*' => Http::response(['success' => false, 'error' => 'Invalid payload'], 400)]);
        $user = outstandUser();
        $ig = outstandAccount($user);

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', ['caption' => 'Hi', 'account_ids' => [$ig->ulid]])
            ->assertServerError();

        expect(SocialPost::query()->count())->toBe(0)
            ->and(Credits::balance($user))->toBe(30_000);
    });

    it('will not post to an account another relay holds', function () {
        Http::fake();
        $user = outstandUser();
        $old = SocialAccount::factory()->for($user)->create(['provider' => 'postforme']);

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', ['caption' => 'Hi', 'account_ids' => [$old->ulid]])
            ->assertUnprocessable();

        Http::assertNothingSent();
    });

    it('reschedules with a PATCH and no accounts', function () {
        Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'post' => ['id' => 'p1']])]);
        $user = outstandUser();
        $ig = outstandAccount($user);
        $post = SocialPost::factory()->for($user)->create(['provider' => 'outstand', 'provider_post_id' => 'p1', 'scheduled_at' => now()->addDay()]);
        $post->targets()->create(['social_account_id' => $ig->id]);
        $to = now()->addDays(2)->setTime(8, 0);

        $this->actingAs($user)
            ->patchJson("/api/v1/social-posts/{$post->ulid}", ['scheduled_at' => $to->toIso8601String()])
            ->assertOk();

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/posts/p1')
            && ! isset($r['accounts'])
            && $r['scheduledAt'] === $to->utc()->format('Y-m-d\TH:i:s\Z'));
    });

    it('cancels through the relay that holds the post, and pays back', function () {
        Http::fake(['*' => Http::response(['success' => true])]);
        $user = outstandUser();
        $ig = outstandAccount($user);
        $post = SocialPost::factory()->for($user)->create(['provider' => 'outstand', 'provider_post_id' => 'p2', 'scheduled_at' => now()->addDay()]);
        $post->targets()->create(['social_account_id' => $ig->id]);
        Credits::chargeSend($post);
        $old = SocialPost::factory()->for($user)->create(['provider' => 'postforme', 'provider_post_id' => 'pfm_1', 'scheduled_at' => now()->addDay()]);

        $this->actingAs($user)->deleteJson("/api/v1/social-posts/{$post->ulid}")->assertNoContent();
        $this->actingAs($user)->deleteJson("/api/v1/social-posts/{$old->ulid}")->assertNoContent();

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.outstand.test/v1/posts/p2');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.postforme.test/v1/social-posts/pfm_1');
        expect(Credits::balance($user))->toBe(30_000);
    });

    it('re-creates a post when a disconnect drops one of its destinations', function () {
        $user = outstandUser();
        $ig = outstandAccount($user, 'instagram');
        $x = outstandAccount($user, 'x');
        $post = SocialPost::factory()->for($user)->create(['provider' => 'outstand', 'provider_post_id' => 'old1', 'scheduled_at' => now()->addDay()]);
        $post->targets()->create(['social_account_id' => $ig->id]);
        $post->targets()->create(['social_account_id' => $x->id]);
        Credits::chargeSend($post);

        Http::fake([
            'api.outstand.test/v1/posts/old1' => Http::sequence()
                ->push(['success' => true, 'post' => ['id' => 'old1', 'socialAccounts' => [['id' => $ig->provider_account_id], ['id' => $x->provider_account_id]]]])
                ->push(['success' => true]),
            'api.outstand.test/v1/posts' => Http::response(['success' => true, 'post' => ['id' => 'new1', 'publishedAt' => null]]),
            'api.outstand.test/*' => Http::response(['success' => true]),
        ]);

        $this->actingAs($user)->deleteJson("/api/v1/social-accounts/{$x->ulid}")->assertNoContent();

        expect($post->fresh()->provider_post_id)->toBe('new1')
            ->and($post->targets()->count())->toBe(1)
            ->and(Credits::balance($user))->toBe(30_000 - Credits::PER_SEND);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/posts')
            && $r['accounts'] === [$ig->provider_account_id]);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/posts/old1'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/social-accounts/'.$x->provider_account_id));
    });

    it('asks Outstand what happened to an overdue post', function () {
        $user = outstandUser();
        $ig = outstandAccount($user);
        $post = SocialPost::factory()->for($user)->create(['provider' => 'outstand', 'provider_post_id' => 'p3', 'scheduled_at' => now()->subHour()]);
        $post->targets()->create(['social_account_id' => $ig->id]);
        Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'post' => ['id' => 'p3', 'socialAccounts' => [[
            'id' => $ig->provider_account_id, 'status' => 'published', 'platformPostUrl' => 'https://instagram.com/p/1',
        ]]]])]);

        $this->actingAs($user)->getJson('/api/v1/social-posts')->assertOk();

        expect($post->fresh()->status->value)->toBe('published')
            ->and($post->targets()->first()->platform_url)->toBe('https://instagram.com/p/1');
    });
});

describe('the outstand webhook', function () {
    it('rejects a missing or wrong signature, and everything when no secret is set', function () {
        outstandHook($this, ['event' => 'test'], null)->assertForbidden();
        outstandHook($this, ['event' => 'test'], 'wrong')->assertForbidden();

        config(['services.outstand.webhook_secret' => '']);
        outstandHook($this, ['event' => 'test'], '')->assertForbidden();
    });

    it('records a published destination, and only on the post Outstand holds', function () {
        $user = outstandUser();
        $ig = outstandAccount($user);
        $post = SocialPost::factory()->for($user)->create(['provider' => 'outstand', 'provider_post_id' => '9dyJS']);
        $post->targets()->create(['social_account_id' => $ig->id]);
        $twin = SocialPost::factory()->for($user)->create(['provider' => 'postforme', 'provider_post_id' => 'pfm_9dyJS']);

        outstandHook($this, ['event' => 'post.published', 'timestamp' => now()->toIso8601String(), 'data' => [
            'postId' => '9dyJS', 'socialAccounts' => [[
                'accountId' => $ig->provider_account_id, 'network' => 'instagram',
                'platformPostId' => '1785', 'platformPostUrl' => 'https://www.instagram.com/p/DAb/',
            ]],
        ]])->assertOk();

        expect($post->fresh()->status->value)->toBe('published')
            ->and($post->targets()->first()->platform_url)->toBe('https://www.instagram.com/p/DAb/')
            ->and($twin->fresh()->status->value)->toBe('scheduled');
    });

    it('fails every destination of a post.error and pays them back once', function () {
        $user = outstandUser();
        $ig = outstandAccount($user);
        $post = SocialPost::factory()->for($user)->create(['provider' => 'outstand', 'provider_post_id' => 'bad1']);
        $post->targets()->create(['social_account_id' => $ig->id]);
        Credits::chargeSend($post);
        $payload = ['event' => 'post.error', 'data' => ['postId' => 'bad1', 'socialAccounts' => [[
            'accountId' => $ig->provider_account_id, 'error' => 'Access token has expired',
        ]]]];

        outstandHook($this, $payload)->assertOk();
        outstandHook($this, $payload)->assertOk();

        expect($post->fresh()->status->value)->toBe('failed')
            ->and($post->targets()->first()->fail_reason)->toBe('Access token has expired')
            ->and(Credits::balance($user))->toBe(30_000);
    });

    it('disconnects an account whose token expired', function () {
        $user = User::factory()->create();
        $ig = outstandAccount($user, 'instagram', ['provider_account_id' => 'g7H8i9']);

        outstandHook($this, ['event' => 'account.token_expired', 'data' => ['accountId' => 'g7H8i9', 'network' => 'instagram']])
            ->assertOk();

        expect($ig->fresh()->status->value)->toBe('disconnected');
    });

    it('answers 200 to events it does not use', function () {
        outstandHook($this, ['event' => 'comment.received', 'data' => ['accountId' => 'x']])->assertOk();
    });
});

it('reads an Outstand account feed from its posts and their analytics', function () {
    $user = User::factory()->create();
    $ig = outstandAccount($user);
    $ours = SocialPost::factory()->for($user)->create(['provider' => 'outstand', 'provider_post_id' => 'f1']);
    Http::fake([
        'api.outstand.test/v1/posts/f1/analytics' => Http::response(['success' => true, 'metrics_by_account' => [[
            'social_account' => ['id' => $ig->provider_account_id], 'metrics' => ['views' => 120, 'likes' => 9, 'comments' => 2, 'shares' => null, 'reach' => 80],
        ]]]),
        'api.outstand.test/v1/posts?*' => Http::response(['success' => true, 'data' => [
            ['id' => 'f1', 'publishedAt' => '2026-11-01T10:00:00Z', 'containers' => [['content' => 'Ours', 'media' => [['url' => 'https://cdn/a.jpg']]]],
                'socialAccounts' => [['id' => $ig->provider_account_id, 'status' => 'published', 'platformPostId' => 'ig1', 'platformPostUrl' => 'https://instagram.com/p/ig1']]],
            ['id' => 'f2', 'containers' => [['content' => 'Later']], 'socialAccounts' => [['id' => $ig->provider_account_id, 'status' => 'pending']]],
        ]]),
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/social-accounts/{$ig->ulid}/feed")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', 'ig1')
        ->assertJsonPath('data.0.post_id', $ours->ulid)
        ->assertJsonPath('data.0.url', 'https://instagram.com/p/ig1')
        ->assertJsonPath('data.0.media.0', 'https://cdn/a.jpg')
        ->assertJsonPath('data.0.metrics.views', 120)
        ->assertJsonPath('data.0.metrics.reach', 80)
        ->assertJsonPath('data.0.metrics.shares', null)
        ->assertJsonPath('meta.next_cursor', null);
});
