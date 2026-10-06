<?php

use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
});

/** A held post with one destination, owned by a Premium user. */
function heldPost(string $approval = 'awaiting'): SocialPost
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, 300, 'Test');
    $post = SocialPost::factory()->for($user)->create([
        'provider_post_id' => null,
        'approval' => $approval,
    ]);
    $post->targets()->create([
        'social_account_id' => SocialAccount::factory()->for($user)->create()->id,
        'status' => 'pending',
    ]);

    return $post;
}

it('holds a post that needs approval and sends nothing', function () {
    Http::fake();
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    $account = SocialAccount::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson('/api/v1/social-posts', [
            'caption' => 'Wait for me.',
            'account_ids' => [$account->ulid],
            'approval' => 'awaiting',
        ])
        ->assertCreated()
        ->assertJsonPath('data.approval', 'awaiting');

    expect(SocialPost::query()->first()->provider_post_id)->toBeNull();
    Http::assertNothingSent();
});

it('sends a held post when its owner approves it', function () {
    Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_ok', 'status' => 'scheduled'])]);
    $post = heldPost();

    $this->actingAs($post->user)
        ->postJson("/api/v1/social-posts/{$post->ulid}/approve")
        ->assertOk()
        ->assertJsonPath('data.approval', 'approved');

    expect($post->fresh()->provider_post_id)->toBe('post_ok');
    Http::assertSentCount(1);
});

it("forbids approving another user's post", function () {
    $post = heldPost();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/v1/social-posts/{$post->ulid}/approve")
        ->assertForbidden();
});

it('lets a client approve on the link, and only posts on that link', function () {
    Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_c', 'status' => 'scheduled'])]);
    $post = heldPost();
    $other = heldPost();

    $url = $this->actingAs($post->user)
        ->postJson('/api/v1/social-approval-links', ['post_ids' => [$post->ulid, $other->ulid]])
        ->assertCreated()
        ->assertJsonPath('data.posts', 1) // the other user's post is not theirs to share
        ->json('data.url');
    $token = basename($url);

    expect($post->fresh()->approval->value)->toBe('client');

    $this->getJson("/api/v1/a/{$token}")->assertOk()->assertJsonCount(1, 'data.posts');

    $this->postJson("/api/v1/a/{$token}/posts/{$other->ulid}/decision", ['name' => 'Andi', 'decision' => 'approve'])
        ->assertNotFound();

    $this->postJson("/api/v1/a/{$token}/posts/{$post->ulid}/decision", ['name' => 'Andi', 'decision' => 'approve'])
        ->assertOk()
        ->assertJsonPath('data.approval_by', 'Andi');

    expect($post->fresh()->provider_post_id)->toBe('post_c');
});

it('needs a reason to ask for changes, and records it as a comment', function () {
    Http::fake();
    $post = heldPost('client');
    $link = SocialApprovalLink::create(['user_id' => $post->user_id]);
    $link->posts()->attach($post->id);

    $this->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/decision", ['name' => 'Andi', 'decision' => 'revise'])
        ->assertUnprocessable();

    $this->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/decision", [
        'name' => 'Andi', 'decision' => 'revise', 'reason' => 'Foto ketiga kurang terang.',
    ])->assertOk()->assertJsonPath('data.approval', 'revision');

    expect($post->comments()->first()->role)->toBe('APPROVER');
    Http::assertNothingSent();
});

it('takes an approval back: pulled from the provider, Credits returned, waiting again', function () {
    Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_r', 'status' => 'scheduled'])]);
    $post = heldPost('client');
    $link = SocialApprovalLink::create(['user_id' => $post->user_id]);
    $link->posts()->attach($post->id);
    $before = Credits::balance($post->user);

    $this->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/decision", ['name' => 'Andi', 'decision' => 'approve'])
        ->assertOk();
    expect(Credits::balance($post->user))->toBe($before - Credits::PER_SEND);

    $this->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/retract", ['name' => 'Andi'])
        ->assertOk()
        ->assertJsonPath('data.approval', 'client')
        ->assertJsonPath('data.comments.0.body', 'Approval taken back.');

    expect($post->fresh()->provider_post_id)->toBeNull()
        ->and(Credits::balance($post->user))->toBe($before);
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/social-posts/post_r'));
});

it('refuses to take back a post that has gone out', function () {
    $post = heldPost('approved');
    $post->update(['status' => 'published']);
    $link = SocialApprovalLink::create(['user_id' => $post->user_id]);
    $link->posts()->attach($post->id);

    $this->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/retract", ['name' => 'Andi'])
        ->assertUnprocessable();
});

it('answers 409 for a revoked link and 410 for an expired one', function () {
    $post = heldPost('client');
    $revoked = SocialApprovalLink::create(['user_id' => $post->user_id, 'revoked_at' => now()]);
    $expired = SocialApprovalLink::create(['user_id' => $post->user_id, 'expires_at' => now()->subDay()]);

    $this->getJson("/api/v1/a/{$revoked->token}")->assertStatus(409);
    $this->getJson("/api/v1/a/{$expired->token}")->assertStatus(410);
    $this->getJson('/api/v1/a/nope')->assertNotFound();
});

it('threads comments, resolves without deleting, and applies a suggestion', function () {
    $post = heldPost('client');
    $link = SocialApprovalLink::create(['user_id' => $post->user_id]);
    $link->posts()->attach($post->id);

    $id = $this->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/comments", [
        'name' => 'Sari', 'body' => 'Lebih pendek?', 'suggestion' => 'Kartu Noel, enam desain.',
    ])->assertCreated()->json('data.id');

    $this->actingAs($post->user)
        ->postJson("/api/v1/social-posts/{$post->ulid}/comments", ['body' => 'Boleh.', 'parent_id' => $id])
        ->assertCreated();

    $this->actingAs($post->user)
        ->postJson("/api/v1/social-comments/{$id}/apply")
        ->assertOk()
        ->assertJsonPath('data.caption', 'Kartu Noel, enam desain.');

    $this->actingAs($post->user)
        ->getJson("/api/v1/social-posts/{$post->ulid}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.resolved', true)
        ->assertJsonCount(1, 'data.0.replies');
});

it('hands back the live link a waiting post is on, for a reminder', function () {
    $post = heldPost('client');
    $link = SocialApprovalLink::create(['user_id' => $post->user_id]);
    $link->posts()->attach($post->id);

    $this->actingAs($post->user)
        ->getJson("/api/v1/social-posts/{$post->ulid}/approval-link")
        ->assertOk()
        ->assertJsonPath('data.url', $link->url());

    $link->update(['revoked_at' => now()]);

    $this->actingAs($post->user)
        ->getJson("/api/v1/social-posts/{$post->ulid}/approval-link")
        ->assertNotFound();
});
