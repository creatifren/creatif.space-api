<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Support\Credits;
use App\Support\SendConfirmation;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * The send preview's confirm_token: a send from a connected app or API key
 * goes out only if it brings back the token of a preview that still
 * matches (user, post, destinations, Credit price, 10 minutes). The app's
 * cookie session is unaffected.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
    Http::fake(['api.postforme.test/*' => fn () => Http::response(['id' => 'post_'.str()->random(10), 'status' => 'scheduled'])]);
});

function ctUser(): User
{
    $user = User::factory()->create();
    Credits::grant($user, 100_000, 'Test');

    return $user;
}

function ctDraft(User $user, array $platforms = ['instagram'], string $caption = 'Winter set'): SocialPost
{
    $post = SocialPost::factory()->for($user)->create([
        'status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => null, 'caption' => $caption,
    ]);
    foreach ($platforms as $platform) {
        $post->targets()->create([
            'social_account_id' => SocialAccount::factory()->for($user)->create(['platform' => $platform])->id,
            'status' => 'pending',
        ]);
    }

    return $post;
}

function ctToken($test, User $user, SocialPost $post): string
{
    return $test->actingAs($user)->getJson("/api/v1/social-posts/{$post->ulid}/send-preview")
        ->assertOk()->json('data.confirm_token');
}

/** POST /send as a bearer-token caller. */
function ctSend($test, User $user, SocialPost $post, array $body = [])
{
    return $test->actingAs($user)->withToken('cs_live_test')->postJson("/api/v1/social-posts/{$post->ulid}/send", $body);
}

it('hands out a confirm_token with the preview of a draft, and none for a sent post', function () {
    $user = ctUser();

    $this->actingAs($user)->getJson('/api/v1/social-posts/'.ctDraft($user)->ulid.'/send-preview')
        ->assertOk()
        ->assertJsonStructure(['data' => ['confirm_token', 'confirm_expires_at']]);

    $sent = SocialPost::factory()->for($user)->create();
    $this->actingAs($user)->getJson("/api/v1/social-posts/{$sent->ulid}/send-preview")
        ->assertOk()
        ->assertJsonMissingPath('data.confirm_token');
});

it('sends from a token with the preview\'s confirm_token', function () {
    $user = ctUser();
    $post = ctDraft($user);

    ctSend($this, $user, $post, ['confirm_token' => ctToken($this, $user, $post)])
        ->assertOk()
        ->assertJsonPath('data.status', 'scheduled');

    Http::assertSentCount(1);
});

it('schedules from a token with the confirm_token', function () {
    $user = ctUser();
    $post = ctDraft($user);

    ctSend($this, $user, $post, [
        'confirm_token' => ctToken($this, $user, $post),
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ])->assertOk();

    Http::assertSentCount(1);
});

it('requires the confirm_token from a token caller', function () {
    $user = ctUser();
    $post = ctDraft($user);

    ctSend($this, $user, $post)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'confirm_token_required');

    Http::assertNothingSent();
    expect($post->fresh()->status->value)->toBe('draft');
});

it('also requires it behind the MCP token guard (no bearer header seen here)', function () {
    Event::listen(RouteMatched::class, fn (RouteMatched $e) => $e->request->attributes->set('mcp_token', true));
    $user = ctUser();
    $post = ctDraft($user);

    $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/send")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'confirm_token_required');
    Http::assertNothingSent();
});

it('is preview_stale (409) when a destination was added since the preview', function () {
    $user = ctUser();
    $post = ctDraft($user);
    $token = ctToken($this, $user, $post);
    $post->targets()->create([
        'social_account_id' => SocialAccount::factory()->for($user)->create(['platform' => 'facebook'])->id,
        'status' => 'pending',
    ]);

    ctSend($this, $user, $post, ['confirm_token' => $token])
        ->assertStatus(409)->assertJsonPath('code', 'preview_stale');
    Http::assertNothingSent();
});

it('is preview_stale when the Credit price changed (a link on X)', function () {
    config(['services.social.per_send.x_link' => 2000]);
    $user = ctUser();
    $post = ctDraft($user, ['x'], 'No link here');
    $token = ctToken($this, $user, $post);
    $post->update(['caption' => 'Now with https://example.com']);

    ctSend($this, $user, $post, ['confirm_token' => $token])
        ->assertStatus(409)->assertJsonPath('code', 'preview_stale');
    Http::assertNothingSent();
});

it('is preview_stale after 10 minutes', function () {
    $user = ctUser();
    $post = ctDraft($user);
    $token = ctToken($this, $user, $post);

    $this->travel(SendConfirmation::TTL_MINUTES + 1)->minutes();

    ctSend($this, $user, $post, ['confirm_token' => $token])
        ->assertStatus(409)->assertJsonPath('code', 'preview_stale');
    Http::assertNothingSent();
});

it('is preview_stale for a token of another post, another user, or one it did not sign', function () {
    $user = ctUser();
    $post = ctDraft($user);
    $other = ctDraft($user);
    $token = ctToken($this, $user, $post);
    [$body] = explode('.', $token);

    foreach ([
        ctToken($this, $user, $other),
        SendConfirmation::issue(ctUser(), $post),
        $body.'.'.str_repeat('a', 64),
        'garbage',
    ] as $bad) {
        ctSend($this, $user, $post, ['confirm_token' => $bad])
            ->assertStatus(409)->assertJsonPath('code', 'preview_stale');
    }

    Http::assertNothingSent();
});

it('does not let one token send twice', function () {
    $user = ctUser();
    $post = ctDraft($user);
    $token = ctToken($this, $user, $post);

    ctSend($this, $user, $post, ['confirm_token' => $token])->assertOk();
    ctSend($this, $user, $post, ['confirm_token' => $token])->assertUnprocessable();

    Http::assertSentCount(1);
});

it('leaves the app alone: the cookie session sends without a token', function () {
    $user = ctUser();
    $post = ctDraft($user);

    $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/send")->assertOk();

    Http::assertSentCount(1);
});

it('needs no token to hold a draft for approval (nothing is sent)', function () {
    $user = ctUser();
    $post = ctDraft($user);

    ctSend($this, $user, $post, ['approval' => 'awaiting'])
        ->assertOk()->assertJsonPath('data.approval', 'awaiting');

    Http::assertNothingSent();
});
