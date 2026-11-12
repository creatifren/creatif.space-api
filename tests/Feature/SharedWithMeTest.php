<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * "Reports shared with you" — GET /me/reports/shared and the MCP tool
 * list_shared_with_me: live Content Plans whose approver_email is the
 * caller's verified email. Insight share links do not exist yet.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    $this->travelTo(CarbonImmutable::parse('2026-11-20 03:00:00', 'UTC'));
    ensurePassportKeys();
});

/** A December plan (Asia/Jakarta) from $owner to $approver, with one waiting post. */
function swPlan(User $owner, ?string $approver = 'rani@client.test', array $attrs = []): SocialApprovalLink
{
    $link = SocialApprovalLink::create($attrs + [
        'user_id' => $owner->id, 'title' => 'December', 'approver_email' => $approver,
        'scope_from' => '2026-12-01', 'scope_to' => '2026-12-31', 'scope_timezone' => 'Asia/Jakarta',
        'scope_starts_at' => CarbonImmutable::parse('2026-11-30 17:00:00', 'UTC'),
        'scope_ends_at' => CarbonImmutable::parse('2026-12-31 17:00:00', 'UTC'),
    ]);
    $post = SocialPost::factory()->for($owner)->create([
        'provider_post_id' => null, 'status' => 'scheduled', 'approval' => 'client',
        'scheduled_at' => CarbonImmutable::parse('2026-12-05 03:00:00', 'UTC'), 'caption' => 'Launch day',
    ]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($owner)->create()->id, 'status' => 'pending']);

    return $link;
}

function swOwner(string $name = 'Bro'): User
{
    $owner = User::factory()->create(['name' => $name]);
    Subscription::factory()->for($owner)->create();

    return $owner;
}

it('lists Content Plans sent to the signed-in user, in the documented shape', function () {
    $link = swPlan(swOwner());

    asApprover($this, 'rani@client.test')->getJson('/api/v1/me/reports/shared')
        ->assertOk()
        ->assertExactJson(['data' => [[
            'report_id' => $link->ulid,
            'kind' => 'content_plan',
            'title' => 'December',
            'period' => ['from' => '2026-12-01', 'to' => '2026-12-31', 'timezone' => 'Asia/Jakarta'],
            'owner' => ['name' => 'Bro'],
            'url' => $link->url(),
            'posts' => 1,
            'waiting' => 1,
            'expires_at' => null,
            'created_at' => $link->created_at->toIso8601String(),
        ]]]);
});

it('leaves out fixed lists, other approvers, revoked and expired plans', function () {
    $owner = swOwner();
    $keep = swPlan($owner);
    SocialApprovalLink::create(['user_id' => $owner->id, 'title' => 'List', 'approver_email' => 'rani@client.test']);
    swPlan($owner, 'someone@else.test');
    swPlan($owner, 'rani@client.test', ['revoked_at' => now()]);
    swPlan($owner, 'rani@client.test', ['expires_at' => now()->subMinute()]);

    $ids = asApprover($this, 'rani@client.test')->getJson('/api/v1/me/reports/shared')->assertOk()->json('data.*.report_id');
    expect($ids)->toBe([$keep->ulid]);
});

it('matches the approver email without case or spaces, and needs a verified email', function () {
    swPlan(swOwner(), 'rani@client.test');
    User::factory()->create(['email' => 'Rani@Client.test']);

    asApprover($this, 'Rani@Client.test')->getJson('/api/v1/me/reports/shared')->assertOk()->assertJsonCount(1, 'data');

    User::query()->where('email', 'Rani@Client.test')->update(['email_verified_at' => null]);
    app('auth')->forgetGuards();
    asApprover($this, 'Rani@Client.test')->getJson('/api/v1/me/reports/shared')->assertOk()->assertExactJson(['data' => []]);
});

it('is empty for a user nobody shared anything with, and 401 signed out', function () {
    swPlan(swOwner());
    asApprover($this, 'nobody@client.test')->getJson('/api/v1/me/reports/shared')->assertOk()->assertExactJson(['data' => []]);
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->getJson('/api/v1/me/reports/shared')->assertUnauthorized();
});

it('needs approvals:decide on a token (list_shared_with_me)', function () {
    swPlan(swOwner());
    $user = User::query()->findOrFail(User::factory()->create(['email' => 'rani@client.test'])->id);

    $withScope = $this->actingAs($user, 'web')->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'scopes' => ['read', 'approvals:decide']])->json('data.key');
    $readOnly = $this->actingAs($user, 'web')->postJson('/api/v1/me/api-keys', ['name' => 'Other', 'scopes' => ['read']])->json('data.key');
    app('auth')->forgetGuards();

    bearer($this, $withScope)->getJson('/api/v1/me/reports/shared')->assertOk()->assertJsonCount(1, 'data');
    app('auth')->forgetGuards();
    bearer($this, $readOnly)->getJson('/api/v1/me/reports/shared')->assertForbidden();
});
