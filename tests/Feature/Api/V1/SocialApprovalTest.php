<?php

use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SocialPostDecided;
use App\Support\Credits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
});

/** A held post with one destination, owned by a Premium user (a new one unless given). */
function heldPost(string $approval = 'awaiting', ?User $user = null): SocialPost
{
    if ($user === null) {
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create();
        Credits::grant($user, 30_000, 'Test');
    }
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
        ->postJson('/api/v1/social-approval-links', [
            'post_ids' => [$post->ulid, $other->ulid], 'title' => 'Desember', 'client_name' => 'Andi',
        ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Desember')
        ->assertJsonPath('data.client_name', 'Andi')
        ->assertJsonPath('data.posts', 1) // the other user's post is not theirs to share
        ->json('data.url');
    $token = basename($url);

    expect($post->fresh()->approval->value)->toBe('client');

    $this->getJson("/api/v1/a/{$token}")->assertOk()->assertJsonCount(1, 'data.posts')
        ->assertJsonMissingPath('data.client_name');

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

    $editor = $post->user->name;
    $this->getJson("/api/v1/a/{$revoked->token}")->assertStatus(409)->assertJsonPath('editor', $editor);
    $this->getJson("/api/v1/a/{$expired->token}")->assertStatus(410)->assertJsonPath('editor', $editor);
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

/* ── RF-043: managing links, group approval ── */

/** A link holding the given posts, owned by the first post's user. */
function linkFor(SocialPost ...$posts): SocialApprovalLink
{
    $link = SocialApprovalLink::create(['user_id' => $posts[0]->user_id, 'title' => 'Desember', 'client_name' => 'Andi']);
    $link->posts()->attach(collect($posts)->pluck('id'));

    return $link;
}

/** The provider takes every post, each under its own id (provider ids are unique). */
function providerOk(): void
{
    $n = 0;
    Http::fake(['api.postforme.test/*' => function () use (&$n) {
        return Http::response(['id' => 'post_'.++$n, 'status' => 'scheduled']);
    }]);
}

it('lists every link, active, expired and revoked, with where its posts stand', function () {
    $a = heldPost('client');
    $user = $a->user;
    $b = heldPost('approved', $user);
    $c = heldPost('revision', $user);
    $live = linkFor($a, $b, $c);
    linkFor(heldPost('client', $user))->update(['expires_at' => now()->subDay()]);
    linkFor(heldPost('client', $user))->update(['revoked_at' => now()]);

    $this->actingAs($user)->getJson('/api/v1/social-approval-links')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.status', 'revoked')
        ->assertJsonPath('data.1.status', 'expired')
        ->assertJsonPath('data.2.id', $live->ulid)
        ->assertJsonPath('data.2.status', 'active')
        ->assertJsonPath('data.2.posts', 3)
        ->assertJsonPath('data.2.counts', ['approved' => 1, 'waiting' => 1, 'revision' => 1]);
});

it('counts every link in one query, however many there are', function () {
    $post = heldPost('client');
    linkFor($post);
    $queries = function () use ($post): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($post->user)->getJson('/api/v1/social-approval-links')->assertOk();

        return count(DB::getQueryLog());
    };
    $queries(); // warm: the first request also resolves the session user
    $one = $queries();
    foreach (range(1, 4) as $_) {
        linkFor(heldPost('client', $post->user));
    }

    expect($queries())->toBe($one);
});

it("keeps one user's links from another", function () {
    $link = linkFor(heldPost('client'));
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->getJson("/api/v1/social-approval-links/{$link->ulid}")->assertForbidden();
    $this->actingAs($stranger)->patchJson("/api/v1/social-approval-links/{$link->ulid}", ['title' => 'x'])->assertForbidden();
    $this->actingAs($stranger)->postJson("/api/v1/social-approval-links/{$link->ulid}/approve")->assertForbidden();
    $this->actingAs($stranger)->deleteJson("/api/v1/social-approval-links/{$link->ulid}")->assertForbidden();
    $this->actingAs($stranger)->getJson('/api/v1/social-approval-links')->assertOk()->assertJsonCount(0, 'data');
});

it('shows a link with its posts and only the threads still open', function () {
    $post = heldPost('approved');
    $post->update(['approval_by' => 'Andi', 'approval_at' => now()]);
    $link = linkFor($post);
    $post->comments()->create(['author_name' => 'Andi', 'role' => 'COMMENTER', 'body' => 'Open']);
    $post->comments()->create(['author_name' => 'Andi', 'role' => 'COMMENTER', 'body' => 'Done', 'resolved_at' => now()]);

    $this->actingAs($post->user)->getJson("/api/v1/social-approval-links/{$link->ulid}")
        ->assertOk()
        ->assertJsonPath('data.link.title', 'Desember')
        ->assertJsonPath('data.posts.0.id', $post->ulid)
        ->assertJsonPath('data.posts.0.approval_by', 'Andi')
        ->assertJsonCount(1, 'data.posts.0.comments')
        ->assertJsonPath('data.posts.0.comments.0.body', 'Open');
});

it('renames a link and extends it without changing its address', function () {
    $post = heldPost('client');
    $link = linkFor($post);
    $link->update(['expires_at' => now()->subHour()]);
    $this->getJson("/api/v1/a/{$link->token}")->assertStatus(410);

    $this->actingAs($post->user)->patchJson("/api/v1/social-approval-links/{$link->ulid}", [
        'title' => 'Januari', 'client_name' => 'Nadira', 'expires_in_hours' => 168,
    ])->assertOk()
        ->assertJsonPath('data.link.title', 'Januari')
        ->assertJsonPath('data.link.client_name', 'Nadira')
        ->assertJsonPath('data.link.status', 'active')
        ->assertJsonPath('data.link.url', $link->url());

    expect($link->fresh()->extended_at)->not->toBeNull()
        ->and($link->fresh()->expires_at->isFuture())->toBeTrue();
    $this->getJson("/api/v1/a/{$link->token}")->assertOk();

    // No expiry is a choice too.
    $this->actingAs($post->user)->patchJson("/api/v1/social-approval-links/{$link->ulid}", ['expires_in_hours' => null])
        ->assertOk()->assertJsonPath('data.link.expires_at', null);
});

it('refuses to change a revoked link', function () {
    $post = heldPost('client');
    $link = linkFor($post);
    $link->update(['revoked_at' => now()]);

    $this->actingAs($post->user)->patchJson("/api/v1/social-approval-links/{$link->ulid}", ['expires_in_hours' => 48])
        ->assertUnprocessable();
});

it('adds only posts that can still be approved, and asks only about the unasked', function () {
    $on = heldPost('client');
    $user = $on->user;
    $link = linkFor($on);
    $fresh = heldPost('awaiting', $user);
    $asked = heldPost('revision', $user);
    $sent = heldPost('approved', $user);
    $sent->update(['provider_post_id' => 'gone']);
    $foreign = heldPost('awaiting');

    $this->actingAs($user)->patchJson("/api/v1/social-approval-links/{$link->ulid}", [
        'add_post_ids' => [$fresh->ulid, $asked->ulid, $sent->ulid, $foreign->ulid],
    ])->assertOk()->assertJsonPath('data.link.posts', 3);

    expect($fresh->fresh()->approval->value)->toBe('client')
        ->and($asked->fresh()->approval->value)->toBe('revision')
        ->and($foreign->fresh()->approval->value)->toBe('awaiting');
});

it('takes posts off a link and hands the unanswered ones back', function () {
    $waiting = heldPost('client');
    $user = $waiting->user;
    $shared = heldPost('client', $user);
    $approved = heldPost('approved', $user);
    $keep = heldPost('client', $user);
    $link = linkFor($waiting, $shared, $approved, $keep);
    linkFor($shared); // still asked for on another link

    $this->actingAs($user)->patchJson("/api/v1/social-approval-links/{$link->ulid}", [
        'remove_post_ids' => [$waiting->ulid, $shared->ulid, $approved->ulid],
    ])->assertOk()->assertJsonPath('data.link.posts', 1);

    expect($waiting->fresh()->approval->value)->toBe('awaiting')
        ->and($shared->fresh()->approval->value)->toBe('client')
        ->and($approved->fresh()->approval->value)->toBe('approved');

    $this->actingAs($user)->patchJson("/api/v1/social-approval-links/{$link->ulid}", ['remove_post_ids' => [$keep->ulid]])
        ->assertUnprocessable();
});

it('revokes for good: unanswered posts come back, answers stay', function () {
    $waiting = heldPost('client');
    $user = $waiting->user;
    $approved = heldPost('approved', $user);
    $revision = heldPost('revision', $user);
    $elsewhere = heldPost('client', $user);
    $link = linkFor($waiting, $approved, $revision, $elsewhere);
    linkFor($elsewhere);

    $this->actingAs($user)->deleteJson("/api/v1/social-approval-links/{$link->ulid}")->assertNoContent();

    expect($waiting->fresh()->approval->value)->toBe('awaiting')
        ->and($approved->fresh()->approval->value)->toBe('approved')
        ->and($revision->fresh()->approval->value)->toBe('revision')
        ->and($elsewhere->fresh()->approval->value)->toBe('client');
    $this->getJson("/api/v1/a/{$link->token}")->assertStatus(409);

    // Twice is fine.
    $this->actingAs($user)->deleteJson("/api/v1/social-approval-links/{$link->ulid}")->assertNoContent();
});

it('approves every waiting post on a link for its owner, and leaves a request for changes alone', function () {
    providerOk();
    $a = heldPost('client');
    $user = $a->user;
    $b = heldPost('client', $user);
    $c = heldPost('revision', $user);
    $link = linkFor($a, $b, $c);
    $before = Credits::balance($user);

    $this->actingAs($user)->postJson("/api/v1/social-approval-links/{$link->ulid}/approve")
        ->assertOk()
        ->assertJsonCount(2, 'data.results')
        ->assertJsonPath('data.results.0.approved', true)
        ->assertJsonPath('data.link.counts', ['approved' => 2, 'waiting' => 0, 'revision' => 1]);

    expect($c->fresh()->approval->value)->toBe('revision')
        ->and(Credits::balance($user))->toBe($before - 2 * Credits::PER_SEND);
    Http::assertSentCount(2);
});

it('lets a client approve all they were shown in one request, and tells the owner once', function () {
    Notification::fake();
    providerOk();
    $a = heldPost('client');
    $user = $a->user;
    $b = heldPost('revision', $user);
    $late = heldPost('client', $user); // added after the client's page loaded
    $link = linkFor($a, $b, $late);

    $this->postJson("/api/v1/a/{$link->token}/approve-all", ['name' => 'Andi', 'post_ids' => [$a->ulid, $b->ulid]])
        ->assertOk()
        ->assertJsonCount(3, 'data.posts')
        ->assertJsonCount(2, 'data.results')
        ->assertJsonPath('data.results.1.approved', true);

    expect($a->fresh()->approval->value)->toBe('approved')
        ->and($b->fresh()->approval_by)->toBe('Andi')
        ->and($late->fresh()->approval->value)->toBe('client');
    Notification::assertSentToTimes($user, SocialPostDecided::class, 1);
    Notification::assertSentTo($user, SocialPostDecided::class, fn ($n) => $n->decision === 'approved' && $n->count === 2);
});

it("never shows a client the editor's balance, and sends nothing it cannot pay for", function () {
    Notification::fake();
    Http::fake();
    $a = heldPost('client');
    $user = $a->user;
    $b = heldPost('client', $user);
    $link = linkFor($a, $b);
    Credits::chargeSend($a, intdiv(Credits::balance($user), Credits::PER_SEND)); // drain below one send

    $message = $this->postJson("/api/v1/a/{$link->token}/approve-all", ['name' => 'Andi', 'post_ids' => [$a->ulid, $b->ulid]])
        ->assertUnprocessable()
        ->json('message');

    expect($message)->toContain($user->name)->not->toMatch('/\d/');
    Http::assertNothingSent();
    Notification::assertSentTo($user, SocialPostDecided::class,
        fn ($n, $channels) => $n->decision === 'blocked' && $channels === ['mail', 'database']);

    // The single decision too — the leak this replaces.
    $single = $this->postJson("/api/v1/a/{$link->token}/posts/{$a->ulid}/decision", ['name' => 'Andi', 'decision' => 'approve'])
        ->assertUnprocessable()
        ->json('message');
    expect($single)->not->toMatch('/\d/');
});

it('reports a provider failure on the post it happened to, and sends the rest', function () {
    Notification::fake();
    Http::fake(['api.postforme.test/*' => Http::sequence()
        ->push(['id' => 'post_1', 'status' => 'scheduled'])
        ->pushStatus(500)]);
    $a = heldPost('client');
    $b = heldPost('client', $a->user);
    $link = linkFor($a, $b);

    $results = $this->postJson("/api/v1/a/{$link->token}/approve-all", ['name' => 'Andi', 'post_ids' => [$a->ulid, $b->ulid]])
        ->assertOk()
        ->json('data.results');

    expect(collect($results)->pluck('approved')->all())->toBe([true, false])
        ->and($results[1]['message'])->not->toBeNull()
        ->and($b->fresh()->approval->value)->toBe('client');
});

it('does not send a post twice when Approve all is pressed twice', function () {
    Notification::fake();
    providerOk();
    $a = heldPost('client');
    $link = linkFor($a);

    $this->postJson("/api/v1/a/{$link->token}/approve-all", ['name' => 'Andi', 'post_ids' => [$a->ulid]])->assertOk();
    $this->postJson("/api/v1/a/{$link->token}/approve-all", ['name' => 'Andi', 'post_ids' => [$a->ulid]])->assertUnprocessable();

    Http::assertSentCount(1);
});

it('tells the owner when a client asks for changes, and respects the switch', function () {
    Notification::fake();
    $post = heldPost('client');
    $link = linkFor($post);

    $this->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/decision", [
        'name' => 'Andi', 'decision' => 'revise', 'reason' => 'Foto kurang terang.',
    ])->assertOk();
    Notification::assertSentTo($post->user, SocialPostDecided::class,
        fn ($n, $channels) => $n->decision === 'revise' && in_array('database', $channels, true));

    // Both switches off: the next one goes nowhere (no channel, nothing recorded).
    $post->user->notificationPreferences()->create(['type' => 'social.post.decision', 'email_enabled' => false, 'bell_enabled' => false]);
    $other = heldPost('client', $post->user);
    $link->posts()->attach($other->id);
    $this->postJson("/api/v1/a/{$link->token}/posts/{$other->ulid}/decision", [
        'name' => 'Andi', 'decision' => 'revise', 'reason' => 'Lagi.',
    ])->assertOk();
    Notification::assertNotSentTo($post->user, SocialPostDecided::class, fn ($n) => $n->post?->is($other));
});

it('puts a request for changes on the owners to-do list', function () {
    $post = heldPost('revision');
    $post->update(['approval_by' => 'Andi']);

    $this->actingAs($post->user)->getJson('/api/v1/me/attention')
        ->assertOk()
        ->assertJsonPath('data.0.kind', 'approval.changes')
        ->assertJsonPath('data.0.subject.id', $post->ulid);
});
