<?php

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * GET /social-posts/{id}/metrics — Post page "Reach so far" (prototype
 * v10.2 `post`, Builder B 21 Nov). Per published destination the relay's
 * analytics, summed; Free gets null with meta.insight; unknown stays null.
 */

beforeEach(function () {
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
    ]);
    Cache::flush();
    Notification::fake();
    Analytics::fake();
});

function pmPost(User $user, array $targets): SocialPost
{
    $post = SocialPost::factory()->for($user)->create([
        'provider' => 'outstand', 'provider_post_id' => 'op_1', 'status' => 'published', 'scheduled_at' => null,
    ]);
    foreach ($targets as [$platform, $providerId, $status, $demo]) {
        $account = SocialAccount::factory()->for($user)->create([
            'platform' => $platform, 'provider' => 'outstand', 'provider_account_id' => $providerId, 'is_demo' => $demo,
        ]);
        $post->targets()->create(['social_account_id' => $account->id, 'status' => $status]);
    }

    return $post;
}

it('sums reach, views and engagement over the published destinations', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    $post = pmPost($user, [['instagram', 'oa_ig', 'published', false], ['facebook', 'oa_fb', 'published', false], ['linkedin', 'oa_li', 'failed', false]]);
    Http::fake([
        'api.outstand.test/v1/posts/op_1/analytics' => Http::response(['metrics_by_account' => [
            ['social_account' => ['id' => 'oa_ig'], 'metrics' => ['reach' => 2000, 'impressions' => 3100, 'likes' => 120, 'comments' => 10, 'shares' => 5, 'saved' => 15]],
            ['social_account' => ['id' => 'oa_fb'], 'metrics' => ['reach' => 431, 'reactions_total' => 20, 'comments' => 2]],
        ]]),
    ]);

    $res = $this->actingAs($user)->getJson("/api/v1/social-posts/{$post->ulid}/metrics")->assertOk();

    expect($res->json('data.reach'))->toBe(2431)
        ->and($res->json('data.views'))->toBe(3100)
        ->and($res->json('data.engagement'))->toBe(172)
        ->and($res->json('data.accounts'))->toHaveCount(2)
        ->and($res->json('meta.insight'))->toBeNull();
});

it('reads null, never zero, when the relay has no numbers', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    $post = pmPost($user, [['instagram', 'oa_ig', 'published', false]]);
    Http::fake(['api.outstand.test/*' => Http::response([], 500)]);

    $res = $this->actingAs($user)->getJson("/api/v1/social-posts/{$post->ulid}/metrics")->assertOk();

    expect($res->json('data.reach'))->toBeNull()->and($res->json('data.engagement'))->toBeNull();
});

it('skips sample accounts and asks the relay nothing for them', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    $post = pmPost($user, [['instagram', 'sample_ig', 'published', true]]);
    Http::fake();

    $res = $this->actingAs($user)->getJson("/api/v1/social-posts/{$post->ulid}/metrics")->assertOk();

    expect($res->json('data.accounts'))->toBe([]);
    Http::assertNothingSent();
});

it('withholds the numbers on Free, saying why', function () {
    $user = User::factory()->create();
    $post = pmPost($user, [['instagram', 'oa_ig', 'published', false]]);
    Http::fake();

    $res = $this->actingAs($user)->getJson("/api/v1/social-posts/{$post->ulid}/metrics")->assertOk();

    expect($res->json('data'))->toBeNull()->and($res->json('meta.insight'))->not->toBeNull();
    Http::assertNothingSent();
});

it('is 404 for another workspace\'s post', function () {
    $other = User::factory()->create();
    $post = pmPost($other, [['instagram', 'oa_ig', 'published', false]]);
    $me = User::factory()->create();
    Subscription::factory()->for($me)->create();

    $this->actingAs($me)->getJson("/api/v1/social-posts/{$post->ulid}/metrics")->assertNotFound();
});
