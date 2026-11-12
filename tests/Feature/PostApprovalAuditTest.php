<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialApprovalDecision;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\SocialPostComment;
use App\Models\Subscription;
use App\Models\TeamMember;
use App\Models\User;
use App\Support\Credits;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * GET /social-posts/{id}/approval-audit — every decision on one post, with
 * the approver's name, masked email, time and comment (post detail page,
 * MCP get_approval_history). Workspace only.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
    Http::fake(['api.postforme.test/*' => fn () => Http::response(['id' => 'post_'.Str::random(10), 'status' => 'scheduled'])]);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 03:00:00', 'UTC'));
    Workspace::forget();
    ensurePassportKeys();
});

/** @return array{User, SocialPost, SocialApprovalLink} */
function paLink(): array
{
    $owner = User::factory()->create();
    Subscription::factory()->for($owner)->create();
    Credits::grant($owner, 30_000, 'Test');
    $post = SocialPost::factory()->for($owner)->create([
        'provider_post_id' => null, 'status' => 'scheduled', 'approval' => 'client',
        'scheduled_at' => CarbonImmutable::parse('2026-11-22 03:00:00', 'UTC'), 'caption' => 'Launch day',
    ]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($owner)->create()->id, 'status' => 'pending']);
    $link = SocialApprovalLink::create(['user_id' => $owner->id, 'title' => 'November', 'approver_email' => 'rani@client.test']);
    $link->posts()->attach($post->id);

    return [$owner, $post, $link];
}

it('lists every decision on the post, oldest first, with a masked email and the comment', function () {
    [$owner, $post, $link] = paLink();

    asApprover($this, 'rani@client.test')->withHeaders(['User-Agent' => 'AuditUA/1.0'])
        ->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/decision", ['decision' => 'revise', 'reason' => 'Swap the photo'])
        ->assertOk();
    $this->travel(1)->hours();
    asApprover($this, 'rani@client.test')
        ->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/decision", ['decision' => 'approve'])
        ->assertOk();
    app('auth')->forgetGuards();
    $this->flushSession();

    $res = $this->actingAs($owner)->getJson("/api/v1/social-posts/{$post->ulid}/approval-audit")->assertOk();
    $res->assertJsonPath('data.post_id', $post->ulid)
        ->assertJsonPath('data.approval', 'approved')
        ->assertJsonCount(2, 'data.decisions')
        ->assertJsonPath('data.decisions.0', [
            'decided_at' => '2026-11-20T03:00:00Z',
            'decision' => 'changes_requested',
            'approver' => ['name' => $res->json('data.decisions.0.approver.name'), 'email' => 'r***@c***.test'],
            'comment' => 'Swap the photo',
            'source' => 'web',
            'link' => ['id' => $link->ulid, 'title' => 'November', 'kind' => 'list'],
        ])
        ->assertJsonPath('data.decisions.1.decision', 'approved')
        ->assertJsonPath('data.decisions.1.decided_at', '2026-11-20T04:00:00Z')
        ->assertJsonPath('data.decisions.1.comment', null);

    $body = $res->getContent();
    expect($body)->not->toContain('rani@client.test')->not->toContain('AuditUA')->not->toContain('127.0.0.1');
});

it('finds the comment of an older decision from the approver note', function () {
    [$owner, $post, $link] = paLink();
    SocialPostComment::create(['social_post_id' => $post->id, 'author_name' => 'Rani', 'role' => 'APPROVER', 'body' => 'Older reason']);
    SocialApprovalDecision::create([
        'social_approval_link_id' => $link->id, 'social_post_id' => $post->id, 'decision' => 'changes_requested',
        'approver_email' => 'rani@client.test', 'client_name' => 'Rani', 'source' => 'mcp',
    ]);

    $this->actingAs($owner)->getJson("/api/v1/social-posts/{$post->ulid}/approval-audit")
        ->assertOk()
        ->assertJsonPath('data.decisions.0.comment', 'Older reason')
        ->assertJsonPath('data.decisions.0.source', 'mcp');
});

it('is empty for a post nobody decided on', function () {
    [$owner, $post] = paLink();
    $this->actingAs($owner)->getJson("/api/v1/social-posts/{$post->ulid}/approval-audit")
        ->assertOk()->assertJsonPath('data.decisions', []);
});

it('is the workspace’s: a team member reads it, another workspace gets 404, signed out 401', function () {
    [$owner, $post] = paLink();
    $owner->subscriptions()->update(['plan_id' => Plan::query()->where('key', 'team')->value('id'), 'seats' => 3]);
    $member = User::factory()->create();
    TeamMember::factory()->create([
        'owner_id' => $owner->id, 'member_id' => $member->id, 'email' => $member->email,
        'role' => 'viewer', 'status' => 'active', 'joined_at' => now(),
    ]);
    Workspace::forget();

    $this->actingAs($member)->getJson("/api/v1/social-posts/{$post->ulid}/approval-audit")->assertOk();
    Workspace::forget();
    $this->actingAs(User::factory()->create())->getJson("/api/v1/social-posts/{$post->ulid}/approval-audit")->assertNotFound();
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->getJson("/api/v1/social-posts/{$post->ulid}/approval-audit")->assertUnauthorized();
});

it('is reachable with a read token (get_approval_history)', function () {
    [$owner, $post] = paLink();
    $key = $this->actingAs(User::query()->findOrFail($owner->id), 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'scopes' => ['read']])->assertCreated()->json('data.key');
    app('auth')->forgetGuards();

    bearer($this, $key)->getJson("/api/v1/social-posts/{$post->ulid}/approval-audit")->assertOk();
});
