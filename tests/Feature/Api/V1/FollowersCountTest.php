<?php

use App\Jobs\RefreshFollowerCount;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\OutstandService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * followers_count on each social account: cached on the row, refreshed in
 * the background from Outstand's account metrics at most every 6 h, null
 * when the network does not report it.
 */

beforeEach(function () {
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
    ]);
});

function followersAccount(array $attrs = []): SocialAccount
{
    return SocialAccount::factory()->create(['provider' => 'outstand', 'provider_account_id' => 'Pjnf0'] + $attrs);
}

function metricsReply(mixed $followers, array $platformSpecific = []): array
{
    return ['success' => true, 'data' => [
        'account_id' => 'Pjnf0',
        'network' => 'instagram',
        'followers_count' => $followers,
        'platform_specific' => $platformSpecific,
    ]];
}

describe('GET /api/v1/social-accounts', function () {
    it('answers with the cached count (or null) and never waits on Outstand', function () {
        Queue::fake();
        // Self-heal is not part of this test: it already ran this window.
        $user = User::factory()->create();
        cache()->put('social-self-heal:'.$user->ulid, true, now()->addHour());
        Http::preventStrayRequests();

        followersAccount(['user_id' => $user->id, 'username' => 'a', 'followers_count' => 87, 'followers_synced_at' => now()->subHour()]);
        $stale = followersAccount(['user_id' => $user->id, 'username' => 'b', 'provider_account_id' => 'AKr07', 'platform' => 'youtube']);

        $this->actingAs($user)->getJson('/api/v1/social-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.followers_count', 87)
            ->assertJsonPath('data.1.followers_count', null);

        // Only the stale one is queued, and only once per window.
        Queue::assertPushed(RefreshFollowerCount::class, 1);
        Queue::assertPushed(RefreshFollowerCount::class, fn ($job) => $job->accountId === $stale->id);
        $this->actingAs($user)->getJson('/api/v1/social-accounts')->assertOk();
        Queue::assertPushed(RefreshFollowerCount::class, 1);
    });

    it('still answers when queueing the refresh fails', function () {
        $user = User::factory()->create();
        cache()->put('social-self-heal:'.$user->ulid, true, now()->addHour());
        followersAccount(['user_id' => $user->id]);
        Queue::shouldReceive('push')->andThrow(new RuntimeException('queue down'));
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('queue down'));

        $this->actingAs($user)->getJson('/api/v1/social-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.followers_count', null);
    });
});

describe('RefreshFollowerCount', function () {
    it('stores followers_count from the account metrics', function () {
        Http::fake(['api.outstand.test/v1/social-accounts/Pjnf0/metrics' => Http::response(metricsReply(1234))]);
        $account = followersAccount();

        RefreshFollowerCount::dispatchSync($account->id);

        $account->refresh();
        expect($account->followers_count)->toBe(1234)
            ->and($account->followers_synced_at)->not->toBeNull();
    });

    it('stores null when the network does not report it', function () {
        Http::fake(['api.outstand.test/*' => Http::response(metricsReply(null))]);
        $account = followersAccount(['followers_count' => 50]);

        RefreshFollowerCount::dispatchSync($account->id);

        expect($account->refresh()->followers_count)->toBeNull()
            ->and($account->followers_synced_at)->not->toBeNull();
    });

    it('reads Threads followers from the raw insight when the unified field is null', function () {
        Http::fake(['api.outstand.test/*' => Http::response(metricsReply(null, ['data' => [
            ['name' => 'views', 'values' => []],
            ['name' => 'followers_count', 'total_value' => ['value' => 201]],
        ]]))]);
        $account = followersAccount(['platform' => 'threads']);

        RefreshFollowerCount::dispatchSync($account->id);

        expect($account->refresh()->followers_count)->toBe(201);
    });

    it('keeps the last count when Outstand fails, and waits for the next window', function () {
        Http::fake(['api.outstand.test/*' => Http::response(['success' => false, 'error' => 'boom'], 500)]);
        $account = followersAccount(['followers_count' => 99, 'followers_synced_at' => now()->subHours(7)]);

        RefreshFollowerCount::dispatchSync($account->id);

        $account->refresh();
        expect($account->followers_count)->toBe(99)
            ->and($account->followers_synced_at->gt(now()->subMinute()))->toBeTrue();
    });

    it('does not ask again within 6 hours', function () {
        Http::fake();
        $account = followersAccount(['followers_count' => 10, 'followers_synced_at' => now()->subHours(5)]);

        RefreshFollowerCount::dispatchSync($account->id);

        Http::assertNothingSent();
        expect($account->refresh()->followers_count)->toBe(10);
    });

    it('never asks for X (metrics calls are billed per post read)', function () {
        Http::fake();
        $account = followersAccount(['platform' => 'x']);

        RefreshFollowerCount::dispatchSync($account->id);

        Http::assertNothingSent();
        expect($account->refresh()->followers_count)->toBeNull()
            ->and($account->followers_synced_at)->not->toBeNull();
    });

    it('skips a disconnected account', function () {
        Http::fake();
        $account = followersAccount(['status' => 'disconnected']);

        RefreshFollowerCount::dispatchSync($account->id);

        Http::assertNothingSent();
        expect($account->refresh()->followers_synced_at)->toBeNull();
    });
});

it('parses the per-network shapes', function (array $body, ?int $expected) {
    expect(OutstandService::followersFrom($body))->toBe($expected);
})->with([
    'unified' => [['data' => ['followers_count' => 5]], 5],
    'MCP shape' => [['metrics' => ['followers_count' => 7]], 7],
    'YouTube string' => [['data' => ['followers_count' => null, 'platform_specific' => ['statistics' => ['subscriberCount' => '1500']]]], 1500],
    'Facebook fan_count' => [['data' => ['followers_count' => null, 'platform_specific' => ['fan_count' => 42]]], 42],
    'none' => [['data' => ['followers_count' => null]], null],
    'negative ignored' => [['data' => ['followers_count' => -1]], null],
    'empty' => [[], null],
]);
