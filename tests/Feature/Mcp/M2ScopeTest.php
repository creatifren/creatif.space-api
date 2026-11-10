<?php

use App\Http\Middleware\CapTokenSends;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * The M2 routes behind the token fence: which scope reaches which route,
 * and the daily send cap for a real API key.
 */

beforeEach(function () {
    ensurePassportKeys();
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
    Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_m2', 'status' => 'scheduled'])]);
});

/** A personal API key with these scopes (made through the session, as the app does). */
function m2Key(TestCase $test, User $user, array $scopes): string
{
    $key = $test->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'M2 key', 'scopes' => $scopes])
        ->assertCreated()
        ->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

function m2Draft(User $user): SocialPost
{
    $post = SocialPost::factory()->for($user)->create(['status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => null]);
    $account = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
    $post->targets()->create(['social_account_id' => $account->id, 'status' => 'pending']);

    return $post;
}

it('offers the approve and publish scopes for API keys', function () {
    $user = User::factory()->create();

    expect(m2Key($this, $user, ['read', 'draft', 'approve', 'publish']))->toStartWith('cs_live_');
});

it('previews with read, but sends only with publish', function () {
    $user = User::factory()->create();
    Credits::grant($user, 30_000, 'Test');
    $post = m2Draft($user);
    $draftKey = m2Key($this, $user, ['read', 'draft']);
    $publishKey = m2Key($this, $user, ['read', 'publish']);

    bearer($this, $draftKey)->getJson("/api/v1/social-posts/{$post->ulid}/send-preview")
        ->assertOk()
        ->assertJsonPath('data.sendable', true);

    bearer($this, $draftKey)->postJson("/api/v1/social-posts/{$post->ulid}/send")
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope');
    expect($post->fresh()->status->value)->toBe('draft');

    bearer($this, $publishKey)->postJson("/api/v1/social-posts/{$post->ulid}/send")->assertOk();
    expect($post->fresh()->status->value)->not->toBe('draft')
        ->and(CapTokenSends::status($user)['used'])->toBe(1);
});

it('caps an API key at 20 sends a day', function () {
    $user = User::factory()->create();
    Credits::grant($user, 1_000_000, 'Test');
    $key = m2Key($this, $user, ['read', 'publish']);
    Cache::put('token-sends:'.$user->id.':'.now('UTC')->toDateString(), CapTokenSends::DAILY_CAP, 3600);

    $post = m2Draft($user);
    bearer($this, $key)->postJson("/api/v1/social-posts/{$post->ulid}/send")
        ->assertStatus(429)
        ->assertJsonPath('code', 'daily_send_cap');
});

it('creates an approval link only with approve', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    $post = m2Draft($user);

    bearer($this, m2Key($this, $user, ['read', 'draft']))
        ->postJson('/api/v1/social-approval-links', ['post_ids' => [$post->ulid]])
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope');

    $id = bearer($this, m2Key($this, $user, ['read', 'approve']))
        ->postJson('/api/v1/social-approval-links', ['post_ids' => [$post->ulid]])
        ->assertCreated()
        ->json('data.id');

    bearer($this, m2Key($this, $user, ['read']))
        ->getJson("/api/v1/social-approval-links/{$id}")
        ->assertOk();
});

it('updates a draft with draft scope', function () {
    $user = User::factory()->create();
    $post = m2Draft($user);

    bearer($this, m2Key($this, $user, ['read']))
        ->patchJson("/api/v1/social-posts/{$post->ulid}", ['caption' => 'Nope'])
        ->assertForbidden();

    bearer($this, m2Key($this, $user, ['draft']))
        ->patchJson("/api/v1/social-posts/{$post->ulid}", ['caption' => 'Better'])
        ->assertOk()
        ->assertJsonPath('data.caption', 'Better');
});
