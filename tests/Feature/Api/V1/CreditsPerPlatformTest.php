<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
    Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_px', 'status' => 'scheduled'])]);
});

function payingUser(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, 30_000, 'Test');

    return $user;
}

it('prices every platform, X included, at 300 by default', function () {
    expect(Credits::perSend('x'))->toBe(300)
        ->and(Credits::perSend('instagram'))->toBe(300)
        ->and(Credits::perSend())->toBe(Credits::PER_SEND)
        ->and(Credits::perSend('x', 'see https://creatif.space'))->toBe(2000)
        ->and(array_unique(Credits::perSendByPlatform()))->toBe(['instagram' => 300, 'x_link' => 2000]);
});

it('charges X its own price and gives back exactly what was paid', function () {
    config(['services.social.per_send.x' => 500]);
    $user = payingUser();
    $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
    $x = SocialAccount::factory()->for($user)->create(['platform' => 'x']);

    $this->actingAs($user)
        ->postJson('/api/v1/social-posts', [
            'caption' => 'Both.',
            'account_ids' => [$ig->ulid, $x->ulid],
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])
        ->assertCreated();

    expect(Credits::balance($user))->toBe(30_000 - 300 - 500)
        ->and(Credits::committed($user)['sends'])->toBe(800);

    // Repriced after the charge: the refund is still what was paid.
    config(['services.social.per_send.x' => 900]);
    $post = SocialPost::query()->first();

    $this->actingAs($user)->deleteJson("/api/v1/social-posts/{$post->ulid}")->assertNoContent();

    expect(Credits::balance($user))->toBe(30_000);
});

it('gives back 300 for a destination charged before the price was recorded', function () {
    $user = payingUser();
    $x = SocialAccount::factory()->for($user)->create(['platform' => 'x']);
    $post = SocialPost::factory()->for($user)->create(['scheduled_at' => now()->addDay()]);
    $post->targets()->create(['social_account_id' => $x->id]);
    Credits::chargeSend($post);
    $post->targets()->update(['charged_credits' => null]);
    config(['services.social.per_send.x' => 500]);

    Credits::returnSend($post, $post->targets()->get(), 'Test');

    expect(Credits::balance($user))->toBe(30_000);
});

it('prices a list of destinations by platform, a bare count at 300', function () {
    config(['services.social.per_send.x' => 500]);
    $user = payingUser();
    $x = SocialAccount::factory()->for($user)->create(['platform' => 'x']);
    $post = SocialPost::factory()->for($user)->create(['provider_post_id' => null]);
    $post->targets()->create(['social_account_id' => $x->id]);

    expect(Credits::sendPrice($user, [$x->platform]))->toBe(500)
        ->and(Credits::sendPrice($user, 1))->toBe(300);
});
