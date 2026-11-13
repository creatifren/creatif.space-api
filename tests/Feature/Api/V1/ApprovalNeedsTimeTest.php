<?php

use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;

/*
 * UX P1 (13 Nov): a post with no publish time never reaches a client. The
 * client could not approve it (no time to go out at) and hit a dead end.
 * Putting one on an approval link, adding one to a link, or sending one
 * for client approval answers 422 needs_publish_time with the next step.
 */

function antOwner(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, 30_000, 'Test');

    return $user;
}

function antDraft(User $user, $at = null): SocialPost
{
    $post = SocialPost::factory()->for($user)->create([
        'status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => $at, 'approval' => null,
    ]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($user)->create()->id]);

    return $post;
}

it('refuses an approval link for a post with no time: needs_publish_time, nothing created', function () {
    $user = antOwner();
    $post = antDraft($user);

    $this->actingAs($user)->postJson('/api/v1/social-approval-links', [
        'post_ids' => [$post->ulid], 'approver_email' => 'client@example.com',
    ])->assertStatus(422)
        ->assertJsonPath('code', 'needs_publish_time')
        ->assertJsonPath('post_ids', [$post->ulid])
        ->assertJsonValidationErrors('post_ids')
        ->assertJson(fn ($json) => $json->where('next_step', fn ($s) => str_contains($s, 'update_post') && str_contains($s, 'schedule_post'))->etc());

    expect(SocialApprovalLink::query()->count())->toBe(0)
        ->and($post->fresh()->approval)->toBeNull();
});

it('refuses the whole link when any post has no time, naming only those', function () {
    $user = antOwner();
    $timed = antDraft($user, now()->addDays(2));
    $timeless = antDraft($user);

    $this->actingAs($user)->postJson('/api/v1/social-approval-links', [
        'post_ids' => [$timed->ulid, $timeless->ulid], 'approver_email' => 'client@example.com',
    ])->assertStatus(422)->assertJsonPath('post_ids', [$timeless->ulid]);

    expect(SocialApprovalLink::query()->count())->toBe(0);
});

it('makes the link when every post has a time', function () {
    $user = antOwner();
    $post = antDraft($user, now()->addDays(2));

    $this->actingAs($user)->postJson('/api/v1/social-approval-links', [
        'post_ids' => [$post->ulid], 'approver_email' => 'client@example.com',
    ])->assertCreated();
});

it('refuses to send a draft for client approval without a time', function () {
    $user = antOwner();
    $post = antDraft($user);

    $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/send", ['approval' => 'client'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'needs_publish_time')
        ->assertJsonValidationErrors('scheduled_at');

    expect($post->fresh()->status->value)->toBe('draft')
        ->and($post->fresh()->approval)->toBeNull();
});

it('still holds a draft for client approval when it has a time', function () {
    $user = antOwner();
    $post = antDraft($user);

    $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/send", ['approval' => 'client', 'scheduled_at' => now()->addDays(2)->toIso8601String()])
        ->assertSuccessful();

    expect($post->fresh()->approval?->value)->toBe('client');
});

it('refuses to add a post with no time to an existing link', function () {
    $user = antOwner();
    $timed = antDraft($user, now()->addDays(2));
    $link = SocialApprovalLink::query()->create(['user_id' => $user->id, 'approver_email' => 'client@example.com']);
    $link->posts()->attach($timed->id);
    $held = antDraft($user);
    $held->update(['status' => 'scheduled', 'approval' => 'awaiting']);

    $this->actingAs($user)->patchJson("/api/v1/social-approval-links/{$link->ulid}", ['add_post_ids' => [$held->ulid]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'needs_publish_time');

    expect($link->posts()->count())->toBe(1);
});
