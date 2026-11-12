<?php

use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * The routes behind the MCP post housekeeping tools, reached with a bearer
 * token: discard_draft → DELETE /social-posts/{ulid}/draft (draft; drafts
 * only), list_approval_links → GET /social-approval-links (read).
 */

beforeEach(function () {
    ensurePassportKeys();
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
    Http::fake();
});

function housekeepingKey(TestCase $test, User $user, array $scopes = ['read', 'draft']): string
{
    $key = $test->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Housekeeping key', 'scopes' => $scopes])
        ->assertCreated()
        ->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

it('discards a draft through a draft token', function () {
    $user = User::factory()->create();
    $draft = SocialPost::factory()->for($user)->create(['status' => 'draft']);
    $key = housekeepingKey($this, $user);

    bearer($this, $key)->deleteJson("/api/v1/social-posts/{$draft->ulid}/draft")->assertNoContent();

    expect(SocialPost::query()->whereKey($draft->id)->exists())->toBeFalse();
});

it('refuses to discard anything that is not a draft', function (string $status) {
    $user = User::factory()->create();
    $post = SocialPost::factory()->for($user)->create(['status' => $status]);
    $key = housekeepingKey($this, $user);

    bearer($this, $key)->deleteJson("/api/v1/social-posts/{$post->ulid}/draft")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Only a draft can be discarded.');

    expect(SocialPost::query()->whereKey($post->id)->exists())->toBeTrue();
})->with(['scheduled', 'published', 'failed']);

it('needs draft to discard', function () {
    $user = User::factory()->create();
    $draft = SocialPost::factory()->for($user)->create(['status' => 'draft']);
    $key = housekeepingKey($this, $user, ['read']);

    bearer($this, $key)->deleteJson("/api/v1/social-posts/{$draft->ulid}/draft")
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope');

    expect(SocialPost::query()->whereKey($draft->id)->exists())->toBeTrue();
});

it('answers 404 for another workspace’s draft', function () {
    $draft = SocialPost::factory()->for(User::factory()->create())->create(['status' => 'draft']);
    $key = housekeepingKey($this, User::factory()->create());

    bearer($this, $key)->deleteJson("/api/v1/social-posts/{$draft->ulid}/draft")->assertNotFound();
    expect(SocialPost::query()->whereKey($draft->id)->exists())->toBeTrue();
});

it('lists approval links with a read-only token', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    $draft = SocialPost::factory()->for($user)->create(['status' => 'draft']);
    $this->actingAs($user, 'web')
        ->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [$draft->ulid]])
        ->assertCreated();
    app('auth')->forgetGuards();
    $key = housekeepingKey($this, $user, ['read']);

    bearer($this, $key)->getJson('/api/v1/social-approval-links')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'active')
        ->assertJsonPath('data.0.approver_email', 'client@example.test')
        ->assertJsonPath('data.0.posts', 1);
});
