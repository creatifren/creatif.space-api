<?php

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
});

it("normalises each platform's metrics and marks the posts made through us", function () {
    $user = User::factory()->create();
    $account = SocialAccount::factory()->for($user)->create();
    $ours = SocialPost::factory()->for($user)->create();

    Http::fake(['api.postforme.test/*' => Http::response([
        'data' => [
            ['platform_post_id' => 'ig1', 'caption' => 'Ours', 'external_post_id' => $ours->ulid,
                'media' => [['url' => 'https://cdn/a.jpg']], 'metrics' => ['views' => 120, 'likes' => 9, 'saved' => 2]],
            ['platform_post_id' => 'x1', 'caption' => 'Outside', 'external_post_id' => null,
                'media' => [], 'metrics' => ['public_metrics' => ['like_count' => 4, 'reply_count' => 1]]],
        ],
        'meta' => ['next_cursor' => null],
    ])]);

    $this->actingAs($user)
        ->getJson("/api/v1/social-accounts/{$account->ulid}/feed")
        ->assertOk()
        ->assertJsonPath('data.0.post_id', $ours->ulid)
        ->assertJsonPath('data.0.metrics.views', 120)
        ->assertJsonPath('data.0.metrics.saves', 2)
        ->assertJsonPath('data.1.post_id', null)
        ->assertJsonPath('data.1.metrics.likes', 4)
        ->assertJsonPath('data.0.media.0', 'https://cdn/a.jpg');
});

it("forbids another user's account feed", function () {
    $account = SocialAccount::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson("/api/v1/social-accounts/{$account->ulid}/feed")
        ->assertForbidden();
});

it('says so when the account has not granted its feed', function () {
    Http::fake(['api.postforme.test/*' => Http::response(['message' => 'no'], 403)]);
    $user = User::factory()->create();
    $account = SocialAccount::factory()->for($user)->create();

    $this->actingAs($user)
        ->getJson("/api/v1/social-accounts/{$account->ulid}/feed")
        ->assertStatus(409);
});
