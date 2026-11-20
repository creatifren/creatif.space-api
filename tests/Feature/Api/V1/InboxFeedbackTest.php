<?php

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostComment;
use App\Models\User;
use App\Support\Workspace;

/*
 * Inbox › Feedback (prototype v10.1): client notes from approval links.
 * Open = not done; unread = open and not answered; done = resolved.
 */

beforeEach(fn () => Workspace::forget());

function fbPost(User $owner, string $caption = 'Rooftop bar opening'): SocialPost
{
    $post = SocialPost::factory()->for($owner)->create(['caption' => $caption, 'media' => [['url' => 'https://assets.test/a.jpg']]]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($owner)->create()->id, 'status' => 'pending']);

    return $post;
}

function fbNote(SocialPost $post, string $body, array $attrs = []): SocialPostComment
{
    return SocialPostComment::query()->create($attrs + [
        'social_post_id' => $post->id,
        'author_name' => 'Made Arsana, GM',
        'role' => 'APPROVER',
        'body' => $body,
    ]);
}

it('lists open client notes newest first, with the post, and counts open / unread / done', function () {
    $owner = User::factory()->create();
    $post = fbPost($owner);
    $old = fbNote($post, 'Please use the wider photo.');
    $answered = fbNote($post, 'Can we move it to Monday?');
    SocialPostComment::query()->create(['social_post_id' => $post->id, 'parent_id' => $answered->id, 'author_name' => 'Rizal', 'role' => 'EDITOR', 'body' => 'Done, moved.']);
    fbNote($post, 'Old note', ['resolved_at' => now()]);
    // Someone else's post, and the editor's own comment, never show.
    fbNote(fbPost(User::factory()->create()), 'Not yours');
    SocialPostComment::query()->create(['social_post_id' => $post->id, 'author_name' => 'Rizal', 'role' => 'EDITOR', 'body' => 'My own note']);

    $res = $this->actingAs($owner)->getJson('/api/v1/inbox/feedback')->assertOk();
    expect($res->json('data.*.body'))->toBe(['Can we move it to Monday?', 'Please use the wider photo.'])
        ->and($res->json('data.0.replied'))->toBeTrue()
        ->and($res->json('data.1.replied'))->toBeFalse()
        ->and($res->json('data.1.post.id'))->toBe($post->ulid)
        ->and($res->json('data.1.post.thumb_url'))->toBe('https://assets.test/a.jpg')
        ->and($res->json('meta'))->toBe(['open' => 2, 'unread' => 1, 'done' => 1]);

    expect($this->actingAs($owner)->getJson('/api/v1/inbox/feedback?status=unread')->json('data.*.id'))->toBe([$old->ulid])
        ->and($this->actingAs($owner)->getJson('/api/v1/inbox/feedback?status=done')->json('data.*.body'))->toBe(['Old note']);

    // Mark done (the approval thread's resolve) moves it to Done.
    $this->actingAs($owner)->postJson("/api/v1/social-comments/{$old->ulid}/resolve")->assertOk();
    expect($this->actingAs($owner)->getJson('/api/v1/inbox/feedback')->json('meta'))->toBe(['open' => 1, 'unread' => 0, 'done' => 2]);
});

it('counts unanswered notes on the inbox summary', function () {
    $owner = User::factory()->create();
    fbNote(fbPost($owner), 'Please use the wider photo.');

    $this->actingAs($owner)->getJson('/api/v1/inbox/summary')->assertOk()->assertJsonPath('data.unread_feedback', 1);
});

it('refuses an unknown status', function () {
    $this->actingAs(User::factory()->create())->getJson('/api/v1/inbox/feedback?status=all')->assertUnprocessable();
});
