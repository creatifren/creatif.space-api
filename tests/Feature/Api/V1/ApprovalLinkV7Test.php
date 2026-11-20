<?php

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;
use Illuminate\Support\Facades\Http;

/* v7 approval links (UX review E1, E2, E4, D5): views the client can switch
   between, Link only by default, replaced / turned off / expired pages, the
   client's progress, captions per account, and "Approve with note". */

beforeEach(function () {
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
});

function v7Owner(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, 30_000, 'Test');

    return $user;
}

function v7Held(User $user, ?string $linkedInCaption = null): SocialPost
{
    $post = SocialPost::factory()->for($user)->create(['provider_post_id' => null, 'approval' => 'awaiting', 'caption' => 'Rooftop opens 1 Dec']);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($user)->create()->id, 'status' => 'pending']);
    $post->targets()->create([
        'social_account_id' => SocialAccount::factory()->for($user)->create(['platform' => 'linkedin'])->id,
        'status' => 'pending',
        'configuration' => $linkedInCaption === null ? null : ['caption' => $linkedInCaption],
    ]);

    return $post;
}

it('defaults to Link only and every view, and keeps the views the owner picks', function () {
    $user = v7Owner();
    $post = v7Held($user);

    $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [$post->ulid]])
        ->assertCreated()
        ->assertJsonPath('data.visibility', 'link')
        ->assertJsonPath('data.views', ['grid', 'calendar', 'feed']);

    $url = $this->actingAs($user)->postJson('/api/v1/social-approval-links', [
        'approver_email' => 'client@example.test', 'post_ids' => [v7Held($user)->ulid], 'views' => ['feed', 'grid'],
    ])->assertCreated()->assertJsonPath('data.views', ['grid', 'feed'])->json('data.url');

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/a/'.basename($url))->assertOk()->assertJsonPath('data.views', ['grid', 'feed']);

    $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [v7Held($user)->ulid], 'views' => ['stories']])
        ->assertStatus(422);
});

it('tells the client a link was replaced, turned off or expired — never a bare 404', function () {
    $user = v7Owner();
    $old = $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [v7Held($user)->ulid]])->json('data');
    $new = $this->actingAs($user)->postJson('/api/v1/social-approval-links', [
        'approver_email' => 'client@example.test', 'post_ids' => [v7Held($user)->ulid], 'replaces_link_id' => $old['id'],
    ])->assertCreated()->json('data');
    $off = $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [v7Held($user)->ulid]])->json('data');
    $this->actingAs($user)->deleteJson("/api/v1/social-approval-links/{$off['id']}")->assertNoContent();

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/a/'.basename($old['url']))->assertStatus(409)
        ->assertJsonPath('state', 'replaced')
        ->assertJsonPath('editor', $user->name);
    $this->getJson('/api/v1/a/'.basename($off['url']))->assertStatus(409)->assertJsonPath('state', 'revoked');
    $this->getJson('/api/v1/a/'.basename($new['url']))->assertOk();
});

it('shows each account its own caption and the client their progress', function () {
    Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_ok', 'status' => 'scheduled'])]);
    $user = v7Owner();
    $custom = v7Held($user, 'We are proud to open our rooftop bar.');
    $same = v7Held($user);
    // One account only: approving reaches the (faked) provider like any approval.
    $single = SocialPost::factory()->for($user)->create(['provider_post_id' => null, 'approval' => 'awaiting']);
    $single->targets()->create(['social_account_id' => SocialAccount::factory()->for($user)->create()->id, 'status' => 'pending']);
    $url = $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [$custom->ulid, $same->ulid, $single->ulid]])->json('data.url');
    $token = basename($url);
    $this->app['auth']->forgetGuards();

    $page = $this->getJson("/api/v1/a/{$token}")->assertOk()
        ->assertJsonPath('data.summary.total', 3)
        ->assertJsonPath('data.summary.waiting', 3)
        ->assertJsonPath('data.summary.all_reviewed', false)
        ->json('data.posts');
    $byId = collect($page)->keyBy('id');
    expect($byId[$custom->ulid]['captions_differ'])->toBeTrue()
        ->and(collect($byId[$custom->ulid]['targets'])->firstWhere('platform', 'linkedin')['caption_effective'])->toBe('We are proud to open our rooftop bar.')
        ->and(collect($byId[$custom->ulid]['targets'])->firstWhere('platform', 'instagram')['caption_effective'])->toBe('Rooftop opens 1 Dec')
        ->and($byId[$same->ulid]['captions_differ'])->toBeFalse();

    // Approve with note (E4): the note is optional and stays with the yes.
    asApprover($this)->postJson("/api/v1/a/{$token}/posts/{$single->ulid}/decision", ['decision' => 'approve', 'note' => 'Love the photo'])->assertOk();
    expect($single->comments()->where('role', 'APPROVER')->value('body'))->toBe('Love the photo');
    // Request changes needs its text.
    asApprover($this)->postJson("/api/v1/a/{$token}/posts/{$same->ulid}/decision", ['decision' => 'revise'])->assertStatus(422);
    asApprover($this)->postJson("/api/v1/a/{$token}/posts/{$same->ulid}/decision", ['decision' => 'revise', 'note' => 'x', 'reason' => 'Brighter photo'])->assertStatus(422);
    asApprover($this)->postJson("/api/v1/a/{$token}/posts/{$same->ulid}/decision", ['decision' => 'revise', 'reason' => 'Brighter photo'])->assertOk();

    $this->getJson("/api/v1/a/{$token}")->assertOk()
        ->assertJsonPath('data.summary.reviewed', 2)
        ->assertJsonPath('data.summary.changes_requested', 1)
        ->assertJsonPath('data.summary.waiting', 1)
        ->assertJsonPath('data.summary.all_reviewed', false);
});

it('marks a link opened on the first open by someone other than the owner', function () {
    $user = v7Owner();
    $link = $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [v7Held($user)->ulid]])
        ->assertCreated()->assertJsonPath('data.opened_at', null)->json('data');

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/a/'.basename($link['url']))->assertOk();

    $this->actingAs($user)->getJson('/api/v1/social-approval-links')->assertOk()
        ->assertJsonPath('data.0.opened_at', fn ($v) => is_string($v) && $v !== '');
});
