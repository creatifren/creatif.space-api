<?php

use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\TeamMember;
use App\Models\User;
use Tests\TestCase;

/*
 * GET /social-approvals/pending — the owner's side of the approval loop
 * (MCP list_pending_approvals): posts held for a client, with the link and
 * the client's latest open feedback. And GET /social-posts/{id}/comments
 * (MCP get_client_feedback) behind the MCP token fence with "read".
 */

beforeEach(function () {
    ensurePassportKeys();
});

function paKey(TestCase $test, User $user, array $scopes): string
{
    $key = $test->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Pending key', 'scopes' => $scopes])
        ->assertCreated()
        ->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

/** A post in this approval state, on a link sent to rani@client.test. */
function paHeld(User $owner, string $approval = 'client', array $extra = []): array
{
    $post = SocialPost::factory()->for($owner)->create([
        'provider_post_id' => null, 'status' => 'scheduled', 'approval' => $approval,
        'caption' => 'Winter set.', 'scheduled_at' => now()->addDays(2), ...$extra,
    ]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($owner)->create()->id, 'status' => 'pending']);
    $link = SocialApprovalLink::create(['user_id' => $owner->id, 'title' => 'November', 'approver_email' => 'rani@client.test']);
    $link->posts()->attach($post->id);

    return [$post, $link];
}

it('lists posts waiting on the client and posts with changes requested, with the link and latest client feedback', function () {
    $owner = User::factory()->create();
    [$waiting, $link] = paHeld($owner, 'client', ['scheduled_at' => now()->addDays(3)]);
    [$revision] = paHeld($owner, 'revision', ['scheduled_at' => now()->addDay()]);
    $revision->comments()->create(['author_name' => 'Rani', 'role' => 'COMMENTER', 'body' => 'Older note']);
    $revision->comments()->create(['author_name' => 'Rani', 'role' => 'APPROVER', 'body' => 'Please use the blue photo.', 'suggestion' => 'Blue it is.']);
    $revision->comments()->create(['author_name' => 'Me', 'role' => 'EDITOR', 'body' => 'On it']);
    $revision->comments()->create(['author_name' => 'Rani', 'role' => 'COMMENTER', 'body' => 'Resolved one', 'resolved_at' => now()]);

    $res = $this->actingAs($owner)->getJson('/api/v1/social-approvals/pending')->assertOk();

    expect($res->json('data.*.id'))->toBe([$revision->ulid, $waiting->ulid]);
    $res->assertJsonPath('data.0.state', 'changes_requested')
        ->assertJsonPath('data.0.open_feedback', 2)
        ->assertJsonPath('data.0.latest_feedback.author', 'Rani')
        ->assertJsonPath('data.0.latest_feedback.body', 'Please use the blue photo.')
        ->assertJsonPath('data.0.latest_feedback.suggestion', 'Blue it is.')
        ->assertJsonPath('data.1.state', 'waiting')
        ->assertJsonPath('data.1.latest_feedback', null)
        ->assertJsonPath('data.1.link.id', $link->ulid)
        ->assertJsonPath('data.1.link.approver_email', 'rani@client.test')
        ->assertJsonPath('data.1.link.kind', 'list');
});

it('includes posts on a Content Plan (a link scoped to dates, no attached posts)', function () {
    $owner = User::factory()->create();
    $inPlan = SocialPost::factory()->for($owner)->create([
        'provider_post_id' => null, 'status' => 'scheduled', 'approval' => 'awaiting', 'scheduled_at' => now()->addDays(3),
    ]);
    $outside = SocialPost::factory()->for($owner)->create([
        'provider_post_id' => null, 'status' => 'scheduled', 'approval' => 'client', 'scheduled_at' => now()->addDays(40),
    ]);
    $plan = SocialApprovalLink::create([
        'user_id' => $owner->id, 'title' => 'December plan', 'approver_email' => 'rani@client.test',
        'scope_from' => now()->toDateString(), 'scope_to' => now()->addDays(10)->toDateString(), 'scope_timezone' => 'UTC',
        'scope_starts_at' => now()->startOfDay(), 'scope_ends_at' => now()->addDays(11)->startOfDay(),
    ]);

    $res = $this->actingAs($owner)->getJson('/api/v1/social-approvals/pending')->assertOk();

    expect($res->json('data.*.id'))->toBe([$inPlan->ulid])->not->toContain($outside->ulid);
    $res->assertJsonPath('data.0.state', 'waiting')
        ->assertJsonPath('data.0.link.id', $plan->ulid)
        ->assertJsonPath('data.0.link.kind', 'plan');
});

it('filters by state', function () {
    $owner = User::factory()->create();
    [$waiting] = paHeld($owner, 'client');
    [$revision] = paHeld($owner, 'revision');

    expect($this->actingAs($owner)->getJson('/api/v1/social-approvals/pending?state=waiting')->json('data.*.id'))->toBe([$waiting->ulid])
        ->and($this->actingAs($owner)->getJson('/api/v1/social-approvals/pending?state=changes_requested')->json('data.*.id'))->toBe([$revision->ulid]);

    $this->actingAs($owner)->getJson('/api/v1/social-approvals/pending?state=nope')->assertUnprocessable()->assertJsonValidationErrors('state');
});

it('leaves out drafts, approved, published and owner-held posts', function () {
    $owner = User::factory()->create();
    paHeld($owner, 'approved');
    paHeld($owner, 'client', ['status' => 'published']);
    paHeld($owner, 'client', ['provider_post_id' => 'prov_1']);
    SocialPost::factory()->for($owner)->create(['status' => 'draft', 'approval' => null, 'provider_post_id' => null]);
    // Held for the owner's own yes, on no link.
    SocialPost::factory()->for($owner)->create(['status' => 'scheduled', 'approval' => 'awaiting', 'provider_post_id' => null]);

    expect($this->actingAs($owner)->getJson('/api/v1/social-approvals/pending')->json('data'))->toBe([]);
});

it('shows a team member the workspace’s posts and never another workspace’s', function () {
    $owner = User::factory()->create();
    [$mine] = paHeld($owner);
    paHeld(User::factory()->create());
    $member = User::factory()->create();
    TeamMember::factory()->active($member)->viewer()->create(['owner_id' => $owner->id]);

    expect($this->actingAs($owner)->getJson('/api/v1/social-approvals/pending')->json('data.*.id'))->toBe([$mine->ulid])
        ->and($this->actingAs($member)->getJson('/api/v1/social-approvals/pending')->json('data.*.id'))->toBe([$mine->ulid]);
});

it('is reachable with a read token, as is a post’s feedback thread', function () {
    $owner = User::factory()->create();
    [$post] = paHeld($owner, 'revision');
    $post->comments()->create(['author_name' => 'Rani', 'role' => 'APPROVER', 'body' => 'Shorter please.']);
    $read = paKey($this, $owner, ['read']);

    bearer($this, $read)->getJson('/api/v1/social-approvals/pending')->assertOk()->assertJsonPath('data.0.id', $post->ulid);
    bearer($this, $read)->getJson("/api/v1/social-posts/{$post->ulid}/comments")->assertOk()->assertJsonPath('data.0.body', 'Shorter please.');
});

it('refuses a token without read', function () {
    $owner = User::factory()->create();
    [$post] = paHeld($owner);
    $draftOnly = paKey($this, $owner, ['draft']);

    bearer($this, $draftOnly)->getJson('/api/v1/social-approvals/pending')->assertForbidden()->assertJsonPath('error', 'insufficient_scope');
    bearer($this, $draftOnly)->getJson("/api/v1/social-posts/{$post->ulid}/comments")->assertForbidden()->assertJsonPath('error', 'insufficient_scope');
});
