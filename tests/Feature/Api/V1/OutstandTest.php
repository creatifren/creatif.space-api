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
use Illuminate\Support\Facades\Log;

/*
 * Outstand as the relay: what we send it, what we make of what it sends.
 * Payload shapes are from https://www.outstand.so/docs (2026-11-08); no
 * real key was used.
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

/**
 * A delivery as Outstand sends it: the raw JSON body, signed over exactly
 * those bytes. Server vars are built here (CONTENT_TYPE, HTTP_*), since
 * transformHeadersToServerVars is protected on the TestCase.
 */
function outstandHook($test, array $payload, ?string $secret = 'whsec_out')
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($secret !== null) {
        $server['HTTP_X_OUTSTAND_SIGNATURE'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return $test->call('POST', '/api/v1/webhooks/outstand', [], [], [], $server, $body);
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

    it('sends a body Outstand\'s schema accepts: string scopes, a short URL-safe state, an absolute redirect_uri', function () {
        config(['services.inbox.enabled' => true]);
        Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'data' => ['auth_url' => 'https://www.outstand.so/x']])]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'instagram'])->assertOk();

        Http::assertSent(function (HttpRequest $r) use ($user) {
            parse_str((string) parse_url($r['redirect_uri'], PHP_URL_QUERY), $q);

            return is_string($r['scopes']) && str_contains($r['scopes'], 'instagram_business_manage_messages')
                && ! str_contains($r['scopes'], ' ')
                && filter_var($r['redirect_uri'], FILTER_VALIDATE_URL) !== false
                && str_starts_with($r['redirect_uri'], 'http')
                && preg_match('/^[A-Za-z0-9]{40}$/', $q['state'] ?? '') === 1
                && strlen($r['redirect_uri']) < 200
                && OutstandService::unseal($q['state']) === $user->ulid;
        });
    });

    it('forgets a return state after its hour', function () {
        $state = OutstandService::seal('u1');
        expect(OutstandService::unseal($state))->toBe('u1');

        $this->travel(61)->minutes();

        expect(OutstandService::unseal($state))->toBeNull()
            ->and(OutstandService::unseal('../../etc'))->toBeNull();
    });

    it('logs the full Outstand body and platform on a schema 400, and answers the app clearly', function () {
        Log::spy();
        $echo = ['data' => ['tenant_id' => '01M4MWS19PWZFDW6TVBPFSP0K8', 'redirect_uri' => 'https://api.creatif.space/api/v1/webhooks/outstand?state=x', 'scopes' => ['a']],
            'error' => 'Invalid payload', 'details' => ['scopes' => ['_errors' => ['Expected string, received array']]]];
        Http::fake(['api.outstand.test/*' => Http::response($echo, 400)]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'instagram'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'provider_connect_refused')
            ->assertJsonPath('message', 'Instagram didn’t accept the connection request: Invalid payload');

        Log::shouldHaveReceived('error')->withArgs(fn ($m, $c) => $m === 'social.connect_refused'
            && $c['platform'] === 'instagram' && $c['status'] === 400
            && $c['body'] === json_encode($echo))->once();
    });

    it('turns Outstand refusing the redirect (Quickstart project) into a clear 422, with the body logged', function () {
        Log::spy();
        Http::fake(['api.outstand.test/*' => Http::response(
            ['success' => false, 'error' => 'Redirect URL Override is not allowed for Quickstart Projects'], 400,
        )]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'instagram'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'provider_misconfigured')
            ->assertJsonPath('provider_status', 400)
            ->assertJsonPath('message', 'Connecting Instagram isn’t available right now — our team has been alerted. Please try again later.');

        Log::shouldHaveReceived('error')->withArgs(fn ($m, $c) => $m === 'social.connect_refused'
            && $c['platform'] === 'instagram' && $c['status'] === 400 && $c['misconfigured'] === true
            && str_contains($c['body'], 'not allowed for Quickstart Projects'))->once();
    });

    it('passes any other Outstand refusal on as provider_connect_refused', function () {
        Http::fake(['api.outstand.test/*' => Http::response(['success' => false, 'error' => 'Invalid app password'], 400)]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'bluesky', 'handle' => 'rani.bsky.social', 'app_password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'provider_connect_refused')
            ->assertJsonPath('message', 'Bluesky didn’t accept the connection request: Invalid app password');
    });

    it('treats a network missing at Outstand as misconfigured', function () {
        Http::fake(['api.outstand.test/*' => Http::response(['success' => false, 'error' => 'Social network not configured'], 404)]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'tiktok'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'provider_misconfigured');
    });

    it('still fails loudly on an Outstand outage', function () {
        Http::fake(['api.outstand.test/*' => Http::response(['success' => false, 'error' => 'boom'], 503)]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'instagram'])
            ->assertServerError();
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

    // Prod 10 Nov 01:28 UTC: a 656-char Instagram CDN avatar → MySQL 1406
    // on varchar(255); the column is TEXT now. On the MySQL CI leg this
    // insert is the real check.
    it('connects an account whose avatar URL or name is longer than its column', function () {
        Log::spy();
        $user = User::factory()->create();
        $avatar = 'https://scontent-iad3-1.cdninstagram.com/v/t51.2885-19/'.str_repeat('a', 600).'.jpg?_nc_ht=x';
        expect(strlen($avatar))->toBeGreaterThan(255);
        Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'data' => [
            ['id' => 'Ig1', 'network' => 'instagram', 'username' => str_repeat('r', 300),
                'profile_picture_url' => $avatar, 'tenant_id' => $user->ulid, 'isActive' => 1],
            ['id' => 'Th1', 'network' => 'threads', 'username' => 'rani',
                'profile_picture_url' => 'javascript:alert(1)', 'tenant_id' => $user->ulid, 'isActive' => 1],
            ['id' => 'Li1', 'network' => 'linkedin', 'username' => 'andi',
                'profile_picture_url' => 'https://media.licdn.com/'.str_repeat('b', 2100), 'tenant_id' => $user->ulid, 'isActive' => 1],
            ['id' => str_repeat('x', 65), 'network' => 'tiktok', 'username' => 'toolong',
                'tenant_id' => $user->ulid, 'isActive' => 1],
        ], 'count' => 4, 'total' => 4])]);

        $this->actingAs($user)->postJson('/api/v1/social-accounts/sync')->assertOk();

        $ig = $user->socialAccounts()->where('provider_account_id', 'Ig1')->first();
        expect($ig->status->value)->toBe('connected')
            ->and($ig->profile_photo_url)->toBe($avatar)
            ->and($user->socialAccounts()->where('provider_account_id', 'Li1')->value('profile_photo_url'))->toBeNull()
            ->and($ig->username)->toBe(str_repeat('r', 255))
            ->and($user->socialAccounts()->where('provider_account_id', 'Th1')->value('profile_photo_url'))->toBeNull()
            ->and($user->socialAccounts()->where('platform', 'tiktok')->exists())->toBeFalse();
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => $m === 'social.provider_id_too_long'
            && $c['field'] === 'social_accounts.provider_account_id' && $c['length'] === 65)->once();
    });

    // Prod 10 Nov (QA): Instagram, Threads, X, TikTok … are connected by
    // Outstand directly — the return has no session, or one that 404s.
    it('saves a directly connected account when the return has no session', function () {
        Log::spy();
        $user = User::factory()->create();
        Http::fake(['api.outstand.test/v1/social-accounts*' => Http::response(['success' => true, 'data' => [[
            'id' => 'k7gqh', 'network' => 'instagram', 'username' => 'loyaldigicard',
            'profile_picture_url' => 'https://cdn/p.jpg', 'tenant_id' => $user->ulid, 'isActive' => 1,
        ]], 'count' => 1, 'total' => 1])]);

        $this->get('/api/v1/webhooks/outstand?state='.urlencode(OutstandService::seal($user->ulid)).'&success=true')
            ->assertRedirect('https://app.test/social/connect?social=connected&count=1&connect=success');

        $row = $user->socialAccounts()->where('provider_account_id', 'k7gqh')->first();
        expect($row->provider)->toBe('outstand')
            ->and($row->platform->value)->toBe('instagram')
            ->and($row->username)->toBe('loyaldigicard')
            ->and($row->status->value)->toBe('connected');
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/pending/'));
        Log::shouldHaveReceived('info')->withArgs(fn ($m, $c) => $m === 'social.connect_return'
            && $c['tenant'] === $user->ulid && $c['had_session'] === false && $c['pending_status'] === 'none'
            && $c['accounts_found'] === 1 && $c['outcome'] === 'synced')->once();
    });

    it('saves the tenant\'s existing account when the pending session 404s', function () {
        Log::spy();
        $user = User::factory()->create();
        Http::fake([
            'api.outstand.test/v1/social-accounts/pending/*' => Http::response(['success' => false, 'error' => 'Not found'], 404),
            'api.outstand.test/v1/social-accounts*' => Http::response(['success' => true, 'data' => [[
                'id' => 'k7gqh', 'network' => 'instagram', 'username' => 'loyaldigicard', 'tenant_id' => $user->ulid, 'isActive' => 1,
            ]], 'count' => 1, 'total' => 1]),
        ]);

        $this->get('/api/v1/webhooks/outstand?state='.urlencode(OutstandService::seal($user->ulid)).'&session=gone')
            ->assertRedirect('https://app.test/social/connect?social=connected&count=1&connect=success');

        expect($user->socialAccounts()->where('provider_account_id', 'k7gqh')->exists())->toBeTrue();
        Log::shouldHaveReceived('info')->withArgs(fn ($m, $c) => $m === 'social.connect_return'
            && $c['had_session'] === true && $c['pending_status'] === '404' && $c['accounts_found'] === 1)->once();
    });

    it('says clearly when the sign-in left nothing to connect', function () {
        $user = User::factory()->create();
        Http::fake(['api.outstand.test/v1/social-accounts*' => Http::response(['success' => true, 'data' => [], 'count' => 0, 'total' => 0])]);

        $this->get('/api/v1/webhooks/outstand?state='.urlencode(OutstandService::seal($user->ulid)))
            ->assertRedirect('https://app.test/social/connect?'.http_build_query([
                'social' => 'connected', 'isSuccess' => 'false',
                'error' => 'The sign-in finished, but no account came back from it. Try connecting again.',
                'connect' => 'failed', 'reason' => 'no_account',
            ]));

        expect($user->socialAccounts()->count())->toBe(0);
    });

    it('refuses an account another Creatif Space user already holds', function () {
        $other = User::factory()->create();
        outstandAccount($other, 'instagram', ['provider_account_id' => 'k7gqh']);
        $user = User::factory()->create();
        Http::fake(['api.outstand.test/v1/social-accounts*' => Http::response(['success' => true, 'data' => [[
            'id' => 'k7gqh', 'network' => 'instagram', 'username' => 'loyaldigicard', 'tenant_id' => $user->ulid, 'isActive' => 1,
        ]], 'count' => 1, 'total' => 1])]);
        $elsewhere = 'https://app.test/social/connect?'.http_build_query([
            'social' => 'connected', 'isSuccess' => 'false', 'code' => 'already_connected_elsewhere',
            'error' => 'This account is already connected to another Creatif Space account',
            'connect' => 'failed', 'reason' => 'already_connected_elsewhere',
        ]);

        $this->get('/api/v1/webhooks/outstand?state='.urlencode(OutstandService::seal($user->ulid)))->assertRedirect($elsewhere);
        expect($user->socialAccounts()->count())->toBe(0);

        // Or when Outstand says so itself.
        $this->get('/api/v1/webhooks/outstand?state='.urlencode(OutstandService::seal($user->ulid))
            .'&error='.urlencode('Account is already connected to another tenant'))->assertRedirect($elsewhere);
    });

    it('re-syncs when Channels loads, at most every few minutes', function () {
        $user = User::factory()->create();
        Http::fake(['api.outstand.test/v1/social-accounts*' => Http::response(['success' => true, 'data' => [[
            'id' => 'k7gqh', 'network' => 'instagram', 'username' => 'loyaldigicard', 'tenant_id' => $user->ulid, 'isActive' => 1,
        ]], 'count' => 1, 'total' => 1])]);

        $this->actingAs($user)->getJson('/api/v1/social-accounts')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($user)->getJson('/api/v1/social-accounts')->assertOk();
        Http::assertSentCount(1);

        // A provider that is down never takes the list with it.
        Http::fake(['api.outstand.test/*' => Http::response('', 503)]);
        $this->travel(6)->minutes();
        $this->actingAs($user)->getJson('/api/v1/social-accounts')->assertOk()->assertJsonCount(1, 'data');
    });

    it('refuses a return without a good state', function () {
        $this->get('/api/v1/webhooks/outstand?state=forged&session=abc')
            ->assertRedirect('https://app.test/social/connect?'.http_build_query([
                'social' => 'connected', 'isSuccess' => 'false',
                'error' => 'That sign-in link is not valid any more. Try connecting again.',
                'connect' => 'failed', 'reason' => 'invalid_state',
            ]));
    });

    it('finalizes a pending sign-in that offers one page', function () {
        Http::fake([
            'api.outstand.test/v1/social-accounts/pending/sess1/finalize' => Http::response(['success' => true, 'connectedAccounts' => []]),
            'api.outstand.test/v1/social-accounts/pending/sess1' => Http::response(['success' => true, 'data' => [
                'network' => 'facebook', 'availablePages' => [['id' => 'pg1', 'type' => 'page', 'name' => 'Acme']],
            ]]),
        ]);

        $this->get('/api/v1/webhooks/outstand?state='.urlencode(OutstandService::seal(User::factory()->create()->ulid)).'&session=sess1')
            ->assertRedirect('https://app.test/social/connect?social=connected&connect=success&network=facebook');

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
            ->assertRedirect('https://app.test/social/connect?social=pending&network=linkedin&connect=pending');

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
            ->postJson('/api/v1/social-posts', ['caption' => 'Hi', 'media' => [['url' => 'https://cdn.example.test/photo.jpg']], 'account_ids' => [$ig->ulid]])
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
            // A reason a person can act on; the platform's words stay server-side.
            ->and($post->targets()->first()->fail_reason)->toBe('Instagram needs you to reconnect this account in Creatif Space → Channels.')
            ->and($post->targets()->first()->fail_code)->toBe('auth')
            ->and($post->targets()->first()->provider_error)->toBe('Access token has expired')
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
