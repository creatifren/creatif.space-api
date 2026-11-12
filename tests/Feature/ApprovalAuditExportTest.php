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
 * Approval audit export — GET /social-approval-links/{id}/audit.csv and
 * the MCP tool get_approval_audit: who decided, on what, when, the
 * decision and the comment, for the link's workspace only.
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
function aeLink(): array
{
    $owner = User::factory()->create();
    Subscription::factory()->for($owner)->create();
    Credits::grant($owner, 30_000, 'Test');
    $post = SocialPost::factory()->for($owner)->create([
        'provider_post_id' => null, 'status' => 'scheduled', 'approval' => 'client',
        'scheduled_at' => CarbonImmutable::parse('2026-11-22 03:00:00', 'UTC'), 'caption' => "Launch day\nwith a second line",
    ]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($owner)->create()->id, 'status' => 'pending']);
    $link = SocialApprovalLink::create(['user_id' => $owner->id, 'title' => 'November', 'approver_email' => 'rani@client.test']);
    $link->posts()->attach($post->id);

    return [$owner, $post, $link];
}

/** @return list<array<string, string>> */
function aeRows(string $csv): array
{
    $lines = array_map(fn ($l) => str_getcsv($l, escape: ''), array_values(array_filter(preg_split('/\R/', trim($csv)))));
    $header = array_shift($lines);

    return array_map(fn ($row) => array_combine($header, $row), $lines);
}

it('exports every decision, oldest first, with who, what, when, decision and comment', function () {
    [$owner, $post, $link] = aeLink();

    asApprover($this, 'rani@client.test')->withHeaders(['User-Agent' => 'AuditTest/1.0'])
        ->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/decision", ['decision' => 'revise', 'reason' => 'Swap the photo, please'])
        ->assertOk();
    $this->travel(1)->hours();
    asApprover($this, 'rani@client.test')
        ->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/decision", ['decision' => 'approve'])
        ->assertOk();
    app('auth')->forgetGuards();
    $this->flushSession();

    $res = $this->actingAs($owner)->get("/api/v1/social-approval-links/{$link->ulid}/audit.csv")->assertOk();
    expect($res->headers->get('Content-Type'))->toContain('text/csv')
        ->and($res->headers->get('Content-Disposition'))->toContain("approval-audit-{$link->ulid}.csv");

    $csv = $res->streamedContent();
    expect(str_getcsv(strtok($csv, "\n"), escape: ''))->toBe([
        'decided_at_utc', 'approver_name', 'approver_email', 'decision',
        'comment', 'post_id', 'post_caption', 'post_scheduled_at_utc', 'source',
    ]);
    $rows = aeRows($csv);
    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray([
            'decided_at_utc' => '2026-11-20T03:00:00Z',
            'approver_email' => 'rani@client.test',
            'decision' => 'changes_requested',
            'comment' => 'Swap the photo, please',
            'post_id' => $post->ulid,
            'post_caption' => 'Launch day with a second line',
            'post_scheduled_at_utc' => '2026-11-22T03:00:00Z',
            'source' => 'web',
        ])
        ->and($rows[1])->toMatchArray(['decided_at_utc' => '2026-11-20T04:00:00Z', 'decision' => 'approved', 'comment' => ''])
        // Where from stays in the database.
        ->and($csv)->not->toContain('AuditTest/1.0')->not->toContain('127.0.0.1');

    expect(SocialApprovalDecision::query()->where('decision', 'changes_requested')->value('comment'))->toBe('Swap the photo, please');
});

it('finds the comment of a decision recorded before the comment column', function () {
    [$owner, $post, $link] = aeLink();
    SocialPostComment::create(['social_post_id' => $post->id, 'author_name' => 'Rani', 'role' => 'APPROVER', 'body' => 'Older reason']);
    SocialApprovalDecision::create([
        'social_approval_link_id' => $link->id, 'social_post_id' => $post->id, 'decision' => 'changes_requested',
        'approver_email' => 'rani@client.test', 'client_name' => 'Rani', 'source' => 'web',
    ]);

    $rows = aeRows($this->actingAs($owner)->get("/api/v1/social-approval-links/{$link->ulid}/audit.csv")->assertOk()->streamedContent());
    expect($rows[0])->toMatchArray(['decision' => 'changes_requested', 'comment' => 'Older reason']);
});

it('never lets a cell start a spreadsheet formula', function () {
    [$owner, $post, $link] = aeLink();
    SocialApprovalDecision::create([
        'social_approval_link_id' => $link->id, 'social_post_id' => $post->id, 'decision' => 'changes_requested',
        'approver_email' => 'rani@client.test', 'client_name' => '=HYPERLINK("x")', 'comment' => '+1 more', 'source' => 'web',
    ]);

    $rows = aeRows($this->actingAs($owner)->get("/api/v1/social-approval-links/{$link->ulid}/audit.csv")->streamedContent());
    expect($rows[0]['approver_name'])->toBe("'=HYPERLINK(\"x\")")
        ->and($rows[0]['comment'])->toBe("'+1 more");
});

it('gives just the header when nobody has decided', function () {
    [$owner, , $link] = aeLink();
    $csv = $this->actingAs($owner)->get("/api/v1/social-approval-links/{$link->ulid}/audit.csv")->assertOk()->streamedContent();
    expect(aeRows($csv))->toBe([]);
});

it('is the workspace’s: a team member may export, another workspace gets 404, signed out 401', function () {
    [$owner, , $link] = aeLink();
    $owner->subscriptions()->update(['plan_id' => Plan::query()->where('key', 'team')->value('id'), 'seats' => 3]);
    $member = User::factory()->create();
    TeamMember::factory()->create([
        'owner_id' => $owner->id, 'member_id' => $member->id, 'email' => $member->email,
        'role' => 'editor', 'status' => 'active', 'joined_at' => now(),
    ]);
    Workspace::forget();

    $this->actingAs($member)->get("/api/v1/social-approval-links/{$link->ulid}/audit.csv")->assertOk();
    Workspace::forget();
    $this->actingAs(User::factory()->create())->getJson("/api/v1/social-approval-links/{$link->ulid}/audit.csv")->assertNotFound();
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->getJson("/api/v1/social-approval-links/{$link->ulid}/audit.csv")->assertUnauthorized();
});

it('is reachable with a read token (get_approval_audit)', function () {
    [$owner, , $link] = aeLink();
    $key = $this->actingAs(User::query()->findOrFail($owner->id), 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'scopes' => ['read']])->assertCreated()->json('data.key');
    app('auth')->forgetGuards();

    bearer($this, $key)->get("/api/v1/social-approval-links/{$link->ulid}/audit.csv")->assertOk();
});
