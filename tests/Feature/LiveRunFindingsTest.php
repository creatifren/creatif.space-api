<?php

use App\Enums\SocialAccountStatus;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Support\McpConsent;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * QA live run, 11 Nov: R12 analytics 0 posts, R9 delete a published post,
 * R14 consent footer, P3 connected date and the doubled Pinterest.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
    ]);
});

function lrAccount(User $user, string $platform, string $providerId): SocialAccount
{
    return SocialAccount::factory()->for($user)->create([
        'provider' => 'outstand', 'platform' => $platform, 'provider_account_id' => $providerId,
    ]);
}

/** A post that went out to these accounts, through Outstand post $remoteId. */
function lrPublished(User $user, string $remoteId, SocialAccount ...$accounts): SocialPost
{
    $post = SocialPost::factory()->for($user)->create(['status' => 'published', 'provider_post_id' => $remoteId]);
    foreach ($accounts as $account) {
        $post->targets()->create([
            'social_account_id' => $account->id,
            'status' => 'published',
            'platform_url' => "https://{$account->platform->value}.test/p/1",
        ]);
    }

    return $post;
}

describe('R12 analytics counts a post that is still live on the network', function () {
    // Prod zvQKF: a remote delete Instagram refused left the destination
    // "failed" with the post id still set; the post is live on Instagram.
    it('reads a refused remote delete as live, a deleted one and a failed publish as not', function () {
        planWith('free', ['insight' => true]); // metrics are Insight; gate: InsightGateTest
        $user = User::factory()->create();
        $ig = lrAccount($user, 'instagram', 'beUgT');
        $post = lrPublished($user, 'zvQKF', $ig);

        Http::fake([
            'api.outstand.test/v1/posts/*/analytics' => Http::response(['metrics_by_account' => [
                ['social_account' => ['id' => 'beUgT'], 'metrics' => ['likes' => 4, 'views' => 30]],
            ]]),
            'api.outstand.test/v1/posts*' => Http::response(['success' => true, 'posts' => [
                ['id' => 'zvQKF', 'publishedAt' => '2026-11-10T19:14:38Z', 'socialAccounts' => [[
                    'id' => 'beUgT', 'network' => 'instagram', 'status' => 'failed',
                    'error' => 'Instagram does not support deleting feed posts via API',
                    'platformPostId' => '18643621087053123', 'platformPostUrl' => 'https://www.instagram.com/p/DfkohdeFMpz/',
                    'publishedAt' => '2026-11-10T19:14:45Z',
                ]], 'containers' => [['content' => 'QA test post 2']]],
                ['id' => 'gone', 'socialAccounts' => [[
                    'id' => 'beUgT', 'status' => 'deleted', 'platformPostId' => '1', 'publishedAt' => '2026-11-09T10:00:00Z',
                ]]],
                ['id' => 'neverWent', 'socialAccounts' => [[
                    'id' => 'beUgT', 'status' => 'failed', 'error' => 'Token expired', 'platformPostId' => null, 'publishedAt' => null,
                ]]],
            ]]),
        ]);

        $this->actingAs($user)->getJson("/api/v1/social-accounts/{$ig->ulid}/feed")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', '18643621087053123')
            ->assertJsonPath('data.0.post_id', $post->ulid)
            ->assertJsonPath('data.0.metrics.likes', 4)
            ->assertJsonPath('data.0.metrics.views', 30);
    });
});

describe('R9 deleting a published post', function () {
    it('takes it down where the network allows, names where it stays, and drops it from the list', function () {
        $user = User::factory()->create();
        $ig = lrAccount($user, 'instagram', 'beUgT');
        $fb = lrAccount($user, 'facebook', 'msMkd');
        $bsky = lrAccount($user, 'bluesky', 'WSNhz');
        $post = lrPublished($user, 'zvQKF', $ig, $fb, $bsky);

        Http::fake([
            'api.outstand.test/v1/posts/zvQKF/remote' => Http::response(['success' => true]),
            'api.outstand.test/v1/posts/zvQKF' => Http::response(['success' => true, 'post' => ['id' => 'zvQKF', 'socialAccounts' => [
                ['id' => 'msMkd', 'status' => 'deleted'],
                ['id' => 'beUgT', 'status' => 'failed', 'error' => 'Instagram does not support deleting feed posts via API'],
                ['id' => 'WSNhz', 'status' => 'deleted'],
            ]]]),
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/v1/social-posts/{$post->ulid}")
            ->assertOk()
            ->assertJsonPath('message', 'Deleted from Facebook and Bluesky. It stays on Instagram — delete it there.')
            ->assertJsonPath('data.removed_from_list', true)
            ->assertJsonPath('data.stays_on', ['Instagram']);

        $byPlatform = collect($response->json('data.targets'))->keyBy('account.platform');
        expect($byPlatform['facebook']['removed'])->toBeTrue()
            ->and($byPlatform['bluesky']['removed'])->toBeTrue()
            ->and($byPlatform['instagram']['removed'])->toBeFalse()
            ->and($byPlatform['instagram']['stays_on'])->toBe('Instagram')
            ->and($byPlatform['instagram']['note'])->toBe('Stays on Instagram: Instagram does not let apps delete posts. Delete it in Instagram.')
            ->and($byPlatform['instagram']['platform_url'])->toBe('https://instagram.test/p/1');

        expect(SocialPost::query()->whereKey($post->id)->exists())->toBeFalse();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/posts/zvQKF/remote'));
        $this->actingAs($user)->getJson('/api/v1/social-posts')->assertOk()->assertJsonCount(0, 'data');
    });

    it('removes an Instagram-only post from the list and says it stays there', function () {
        $user = User::factory()->create();
        $post = lrPublished($user, 'p1', lrAccount($user, 'instagram', 'ig1'));
        Http::fake([
            'api.outstand.test/v1/posts/p1/remote' => Http::response(['success' => true]),
            'api.outstand.test/v1/posts/p1' => Http::response(['post' => ['socialAccounts' => [
                ['id' => 'ig1', 'status' => 'failed', 'error' => 'Instagram does not support deleting feed posts via API'],
            ]]]),
        ]);

        $this->actingAs($user)->deleteJson("/api/v1/social-posts/{$post->ulid}")
            ->assertOk()
            ->assertJsonPath('message', 'Removed from your list. It stays on Instagram — delete it there.');
        expect(SocialPost::query()->whereKey($post->id)->exists())->toBeFalse();
    });

    it('changes nothing when the relay cannot be reached', function () {
        $user = User::factory()->create();
        $post = lrPublished($user, 'p2', lrAccount($user, 'facebook', 'fb1'));
        Http::fake(['api.outstand.test/*' => Http::response(['error' => 'down'], 503)]);

        $this->actingAs($user)->deleteJson("/api/v1/social-posts/{$post->ulid}")->assertStatus(502);
        expect($post->fresh()?->status->value)->toBe('published');
    });

    it('is still the owner\'s alone', function () {
        $post = lrPublished(User::factory()->create(), 'p3');
        Http::fake();

        $this->actingAs(User::factory()->create())->deleteJson("/api/v1/social-posts/{$post->ulid}")->assertNotFound();
        Http::assertNothingSent();
    });
});

describe('R14 the consent footer lists only what the granted scopes do not allow', function () {
    it('builds the sentence from the scopes', function (array $scopes, string $line) {
        expect(McpConsent::never($scopes))->toBe($line);
    })->with([
        'read' => [['read'], 'It can never publish, schedule, approve, delete or spend Credits.'],
        'read draft' => [['read', 'draft'], 'It can never publish, schedule, approve, delete or spend Credits.'],
        'with approve' => [['read', 'approve'], 'It can never publish, schedule, delete or spend Credits.'],
        'with publish' => [['read', 'publish'], 'It can never approve or delete.'],
        'publish + approve' => [['read', 'draft', 'approve', 'publish'], 'It can never delete.'],
    ]);

    it('shows it on the consent screen', function () {
        $client = mcpClient();
        [, $challenge] = pkcePair();

        $page = $this->actingAs(User::factory()->create(), 'web')
            ->get('/oauth/authorize?'.http_build_query([
                'client_id' => $client->getKey(),
                'redirect_uri' => $client->redirect_uris[0],
                'response_type' => 'code',
                'scope' => 'read draft approve publish',
                'state' => 's',
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ]))
            ->assertOk();

        $page->assertSee('It can never delete.')
            ->assertDontSee('never publish');
    });
});

describe('P3 Channels: the connected date and the doubled Pinterest', function () {
    it('keeps the connected date across syncs, taken from the relay', function () {
        $user = User::factory()->create();
        Http::fake(['api.outstand.test/v1/social-accounts*' => Http::response(['success' => true, 'data' => [[
            'id' => 'beUgT', 'network' => 'instagram', 'username' => 'creatif_space', 'tenant_id' => $user->ulid,
            'isActive' => 1, 'network_unique_id' => '281613', 'createdAt' => '2026-11-10T14:23:16.713Z',
        ]]])]);

        $this->travelTo('2026-11-11T09:00:00Z');
        $this->actingAs($user)->postJson('/api/v1/social-accounts/sync')
            ->assertOk()
            ->assertJsonPath('data.0.connected_at', '2026-11-10T14:23:16.000000Z');

        $this->travelTo('2026-11-12T09:00:00Z');
        $this->actingAs($user)->postJson('/api/v1/social-accounts/sync')
            ->assertJsonPath('data.0.connected_at', '2026-11-10T14:23:16.000000Z');
    });

    it('without a relay date, keeps the first one until a reconnect', function () {
        $user = User::factory()->create();
        Http::fake(['api.outstand.test/v1/social-accounts*' => Http::response(['success' => true, 'data' => [[
            'id' => 'x1', 'network' => 'x', 'username' => 'rani', 'tenant_id' => $user->ulid, 'isActive' => 1,
        ]]])]);

        $this->travelTo('2026-11-10T20:00:00Z');
        $this->actingAs($user)->postJson('/api/v1/social-accounts/sync');
        $this->travelTo('2026-11-11T08:00:00Z');
        $this->actingAs($user)->postJson('/api/v1/social-accounts/sync')
            ->assertJsonPath('data.0.connected_at', '2026-11-10T20:00:00.000000Z');

        $user->socialAccounts()->update(['status' => SocialAccountStatus::Disconnected]);
        $this->actingAs($user)->postJson('/api/v1/social-accounts/sync')
            ->assertJsonPath('data.0.connected_at', '2026-11-11T08:00:00.000000Z');
    });

    it('lists the same Pinterest account connected twice once', function () {
        $user = User::factory()->create();
        // Prod 10 Nov 14:25: Rf9dH and EycSA, 150 ms apart, same network id.
        $twin = lrAccount($user, 'pinterest', 'EycSA');
        $remote = fn (string $id, string $at) => [
            'id' => $id, 'network' => 'pinterest', 'username' => 'creatifren', 'tenant_id' => $user->ulid,
            'isActive' => 1, 'network_unique_id' => 'creatifren', 'createdAt' => $at,
        ];
        Http::fake(['api.outstand.test/v1/social-accounts*' => Http::response(['success' => true, 'data' => [
            $remote('Rf9dH', '2026-11-10T14:25:35.523Z'),
            $remote('EycSA', '2026-11-10T14:25:35.672Z'),
        ]])]);

        $this->actingAs($user)->postJson('/api/v1/social-accounts/sync')->assertOk()->assertJsonCount(1, 'data');

        $kept = $user->socialAccounts()->where('provider_account_id', 'Rf9dH')->first();
        expect($kept->status)->toBe(SocialAccountStatus::Connected)
            ->and($twin->fresh()->status)->toBe(SocialAccountStatus::Disconnected)
            ->and($twin->fresh()->network_unique_id)->toBe('creatifren');

        Cache::flush();
        $this->actingAs($user)->getJson('/api/v1/social-accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $kept->ulid);
    });

    it('still lists two different accounts on one network', function () {
        $user = User::factory()->create();
        Http::fake(['api.outstand.test/v1/social-accounts*' => Http::response(['success' => true, 'data' => [
            ['id' => 'a', 'network' => 'pinterest', 'username' => 'one', 'tenant_id' => $user->ulid, 'isActive' => 1, 'network_unique_id' => 'one'],
            ['id' => 'b', 'network' => 'pinterest', 'username' => 'two', 'tenant_id' => $user->ulid, 'isActive' => 1, 'network_unique_id' => 'two'],
        ]])]);

        $this->actingAs($user)->postJson('/api/v1/social-accounts/sync')->assertOk()->assertJsonCount(2, 'data');
    });
});
