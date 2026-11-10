<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * Approval and edit limits are the API's, not the client's:
 * - a draft that needs its client's approval is never sent or scheduled,
 *   from the app or from a token; it can only be held for the client again;
 * - a connected app cannot drop the editor's own approval step;
 * - a connected app edits drafts and posts waiting for approval only.
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
    Http::fake(['api.postforme.test/*' => fn () => Http::response(['id' => 'post_'.str()->random(10), 'status' => 'scheduled'])]);
});

function aeUser(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, 100_000, 'Test');

    return $user;
}

function aePost(User $user, array $attributes = []): SocialPost
{
    $post = SocialPost::factory()->for($user)->create($attributes + [
        'status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => null, 'approval' => null,
    ]);
    $post->targets()->create([
        'social_account_id' => SocialAccount::factory()->for($user)->create(['platform' => 'instagram'])->id,
        'status' => 'pending',
    ]);

    return $post;
}

/** A real personal API key (the S1 token guard), made through the session as the app does. */
function aeKey(TestCase $test, User $user, array $scopes = ['read', 'draft', 'approve', 'publish']): string
{
    $key = $test->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'AE key', 'scopes' => $scopes])
        ->assertCreated()
        ->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

function aeToken(TestCase $test, string $key, SocialPost $post): ?string
{
    return bearer($test, $key)->getJson("/api/v1/social-posts/{$post->ulid}/send-preview")->json('data.confirm_token');
}

describe('a draft that needs client approval', function () {
    it('is never sent or scheduled from the app session', function (string $approval) {
        $user = aeUser();
        $post = aePost($user, ['approval' => $approval]);

        foreach ([[], ['scheduled_at' => now()->addDay()->toIso8601String()], ['approval' => null], ['approval' => 'awaiting']] as $body) {
            $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/send", $body)
                ->assertStatus(409)
                ->assertJsonPath('code', 'approval_required');
        }

        Http::assertNothingSent();
        expect($post->fresh()->status->value)->toBe('draft')
            ->and($post->fresh()->approval->value)->toBe($approval);
    })->with(['client', 'revision']);

    it('can still be held for the client again (nothing sent)', function () {
        $user = aeUser();
        $post = aePost($user, ['approval' => 'client']);

        $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/send", ['approval' => 'client'])
            ->assertOk()
            ->assertJsonPath('data.approval', 'client');

        Http::assertNothingSent();
    });

    it('is never sent from an API key, even with a valid confirm_token', function (string $approval) {
        $user = aeUser();
        $post = aePost($user, ['approval' => $approval]);
        $key = aeKey($this, $user);
        $token = aeToken($this, $key, $post);

        bearer($this, $key)->postJson("/api/v1/social-posts/{$post->ulid}/send", ['confirm_token' => $token])
            ->assertStatus(409)
            ->assertJsonPath('code', 'approval_required');
        bearer($this, $key)->postJson("/api/v1/social-posts/{$post->ulid}/send", ['confirm_token' => $token, 'approval' => 'awaiting'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'approval_required');

        Http::assertNothingSent();
        expect($post->fresh()->status->value)->toBe('draft');
    })->with(['client', 'revision']);
});

describe("a draft waiting for the editor's own approval", function () {
    it('is not sent from an API key', function () {
        $user = aeUser();
        $post = aePost($user, ['approval' => 'awaiting']);
        $key = aeKey($this, $user);

        bearer($this, $key)->postJson("/api/v1/social-posts/{$post->ulid}/send", ['confirm_token' => aeToken($this, $key, $post)])
            ->assertStatus(409)
            ->assertJsonPath('code', 'approval_required');

        Http::assertNothingSent();
    });

    it('is the editor\'s call in the app: sending it there turns the step off and sends', function () {
        $user = aeUser();
        $post = aePost($user, ['approval' => 'awaiting']);

        $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/send")->assertOk();

        Http::assertSentCount(1);
    });
});

it('still sends a plain draft from both guards', function () {
    $user = aeUser();
    $key = aeKey($this, $user);
    $app = aePost($user);
    $token = aePost($user);

    $this->actingAs($user)->postJson("/api/v1/social-posts/{$app->ulid}/send")->assertOk();
    app('auth')->forgetGuards();
    bearer($this, $key)->postJson("/api/v1/social-posts/{$token->ulid}/send", ['confirm_token' => aeToken($this, $key, $token)])->assertOk();

    Http::assertSentCount(2);
});

describe('editing through an API key', function () {
    it('changes drafts and posts waiting for approval', function () {
        $user = aeUser();
        $key = aeKey($this, $user, ['read', 'draft']);
        $draft = aePost($user);
        $held = aePost($user, ['status' => 'scheduled', 'approval' => 'client', 'scheduled_at' => now()->addDay()]);

        bearer($this, $key)->patchJson("/api/v1/social-posts/{$draft->ulid}", ['caption' => 'Better.'])
            ->assertOk()->assertJsonPath('data.caption', 'Better.');
        bearer($this, $key)->patchJson("/api/v1/social-posts/{$held->ulid}", ['caption' => 'Better too.'])
            ->assertOk()->assertJsonPath('data.caption', 'Better too.');
        Http::assertNothingSent();
    });

    it('refuses anything already at the provider or done — caption, time or labels', function (array $attributes) {
        $user = aeUser();
        $key = aeKey($this, $user, ['read', 'draft', 'publish']);
        $post = aePost($user, $attributes);

        foreach ([['caption' => 'Sneaky.'], ['scheduled_at' => now()->addDays(2)->toIso8601String()], ['labels' => ['x']]] as $body) {
            bearer($this, $key)->patchJson("/api/v1/social-posts/{$post->ulid}", $body)
                ->assertStatus(409)
                ->assertJsonPath('code', 'not_editable');
        }

        Http::assertNothingSent();
        expect($post->fresh()->caption)->not->toBe('Sneaky.');
    })->with([
        'queued at the provider' => [['status' => 'scheduled', 'provider_post_id' => 'post_q', 'scheduled_at' => now()->addDay()]],
        'approved and queued' => [['status' => 'scheduled', 'approval' => 'approved', 'provider_post_id' => 'post_a', 'scheduled_at' => now()->addDay()]],
        'published' => [['status' => 'published', 'provider_post_id' => 'post_p']],
    ]);

    it('leaves the app alone: the session can still move a queued post', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_q', 'status' => 'scheduled'])]);
        $user = aeUser();
        $post = aePost($user, ['status' => 'scheduled', 'provider_post_id' => 'post_q', 'scheduled_at' => now()->addDay()]);

        $this->actingAs($user)->patchJson("/api/v1/social-posts/{$post->ulid}", ['caption' => 'Fixed typo.'])
            ->assertOk()->assertJsonPath('data.caption', 'Fixed typo.');
    });
});
