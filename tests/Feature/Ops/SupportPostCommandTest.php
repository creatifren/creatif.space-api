<?php

use App\Models\PublishAttempt;
use App\Models\SocialAccount;
use App\Models\SocialApprovalDecision;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

/*
 * php artisan support:post {id} — item 10: one post's timeline for
 * launch-day support. Read-only; no tokens or secrets.
 */

function spOutput(string $id): array
{
    $code = Artisan::call('support:post', ['id' => $id]);

    return [$code, Artisan::output()];
}

/** A post that was drafted, sent for approval, revised, approved, failed once and published on retry. */
function spStory(): SocialPost
{
    test()->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'UTC'));
    $owner = User::factory()->create(['email' => 'rani@studio.test']);
    $account = SocialAccount::factory()->for($owner)->create(['platform' => 'pinterest', 'username' => 'studio', 'provider' => SocialAccount::currentProvider()]);
    $post = SocialPost::factory()->for($owner)->create([
        'caption' => 'Winter set is here', 'status' => 'published', 'approval' => 'approved',
        'provider' => 'outstand', 'provider_post_id' => 'op_first',
        'raw_payload' => ['access_token' => 'EAAB-SECRET'],
    ]);
    $target = $post->targets()->create([
        'social_account_id' => $account->id, 'status' => 'published', 'attempts' => 1,
        'retry_provider_post_id' => 'op_retry', 'provider_result_id' => 'res_9',
        'provider_error' => 'raw relay text with api_key=sk_live_SECRET',
    ]);

    test()->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'UTC'));
    $post->forceFill(['review_requested_at' => now()])->save();
    $link = SocialApprovalLink::query()->create(['user_id' => $owner->id, 'title' => 'Nov', 'client_name' => 'Andi', 'token' => SocialApprovalLink::hashToken('tok-SECRET')]);

    test()->travelTo(CarbonImmutable::parse('2026-11-10 11:00:00', 'UTC'));
    SocialApprovalDecision::create([
        'social_approval_link_id' => $link->id, 'social_post_id' => $post->id, 'decision' => 'changes_requested',
        'approver_email' => 'andi@client.test', 'client_name' => 'Andi', 'source' => 'web', 'comment' => 'Swap the photo',
        'ip' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0 SECRET-UA',
    ]);
    test()->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC'));
    SocialApprovalDecision::create([
        'social_approval_link_id' => $link->id, 'social_post_id' => $post->id, 'decision' => 'approved',
        'approver_email' => 'andi@client.test', 'client_name' => 'Andi', 'source' => 'mcp',
    ]);

    test()->travelTo(CarbonImmutable::parse('2026-11-10 12:05:00', 'UTC'));
    $target->attempts = 0;
    PublishAttempt::record($target, 'failed', 'webhook', 'platform_unavailable', 'Pinterest was unavailable. Try again in a few minutes.', 'op_first');
    test()->travelTo(CarbonImmutable::parse('2026-11-10 12:30:00', 'UTC'));
    PublishAttempt::record($target, 'retry_requested', 'owner', attempt: 1);
    test()->travelTo(CarbonImmutable::parse('2026-11-10 12:31:00', 'UTC'));
    $target->attempts = 1;
    PublishAttempt::record($target, 'published', 'webhook', providerPostId: 'op_retry');

    return $post;
}

it('prints the post and its timeline in order, with failure codes and Outstand ids', function () {
    $post = spStory();

    [$code, $out] = spOutput($post->ulid);

    expect($code)->toBe(0)
        ->and($out)->toContain("Post {$post->ulid}")
        ->toContain('rani@studio.test')
        ->toContain('Winter set is here')
        ->toContain('published')
        ->toContain('op_first')
        ->toContain('retry op_retry, result res_9')
        ->toContain('pinterest @studio');

    $order = ['drafted', 'sent for approval', 'approval: changes_requested', 'approval: approved', 'publish #0: failed', 'publish #1: retry_requested', 'publish #1: published'];
    $positions = array_map(fn ($s) => strpos($out, $s), $order);
    expect($positions)->not->toContain(false)
        ->and($positions)->toBe(collect($positions)->sort()->values()->all());

    expect($out)->toContain('2026-11-10 11:00:00')
        ->toContain('by a***@c***.test')
        ->toContain('via mcp')
        ->toContain('“Swap the photo”')
        ->toContain('platform_unavailable')
        ->toContain('Pinterest was unavailable.')
        ->toContain('Outstand op_retry');
});

it('never prints a token, a secret, the approver’s address, IP or user agent, or the raw relay text', function () {
    $post = spStory();

    [, $out] = spOutput($post->ulid);

    expect($out)->not->toContain('SECRET')
        ->not->toContain('access_token')
        ->not->toContain('api_key')
        ->not->toContain('andi@client.test')
        ->not->toContain('203.0.113.7')
        ->not->toContain('raw relay text');
});

it('lists the approval links the post is on — a fixed list and a Content Plan covering it — with each one\'s reminders, by id only', function () {
    $post = spStory();
    $post->forceFill(['scheduled_at' => CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC')])->save();
    $account = $post->targets()->firstOrFail()->social_account_id;

    $list = SocialApprovalLink::query()->where('user_id', $post->user_id)->firstOrFail();
    $list->posts()->attach($post->id);
    test()->travelTo(CarbonImmutable::parse('2026-11-11 08:00:00', 'UTC'));
    $list->forceFill(['last_reminded_at' => now()])->save();

    $plan = SocialApprovalLink::create([
        'user_id' => $post->user_id, 'title' => 'November plan', 'approver_email' => 'plan-approver@client.test',
        'scope_from' => '2026-11-01', 'scope_to' => '2026-11-30', 'scope_timezone' => 'UTC',
        'scope_starts_at' => CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'),
        'scope_ends_at' => CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'),
        'scope_account_ids' => [$account],
    ]);
    // A datetime column holds app-timezone wall time.
    $plan->forceFill(['reminded_at' => CarbonImmutable::parse('2026-11-11 09:30:00', 'UTC')->setTimezone(config('app.timezone'))])->save();
    // A plan for another month does not cover it.
    $december = SocialApprovalLink::create([
        'user_id' => $post->user_id, 'title' => 'December plan',
        'scope_from' => '2026-12-01', 'scope_to' => '2026-12-31', 'scope_timezone' => 'UTC',
        'scope_starts_at' => CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'),
        'scope_ends_at' => CarbonImmutable::parse('2027-01-01 00:00:00', 'UTC'),
        'scope_account_ids' => [$account],
    ]);

    [$code, $out] = spOutput($post->ulid);

    expect($code)->toBe(0)
        ->and($out)->toContain("{$list->ulid} (list)")
        ->toContain("{$plan->ulid} (plan)")
        ->not->toContain($december->ulid)
        ->toContain('approval link created')
        ->toContain('last reminder sent')
        ->toContain('2026-11-11 08:00:00')
        ->toContain('plan reminder sent')
        ->toContain('2026-11-11 09:30:00')
        ->not->toContain('plan-approver@client.test')
        ->not->toContain('andi@client.test');
});

it('finds the post by its Outstand id or its retry\'s', function () {
    $post = spStory();

    expect(spOutput('op_first')[1])->toContain("Post {$post->ulid}")
        ->and(spOutput('op_retry')[1])->toContain("Post {$post->ulid}");
});

it('says so for an unknown id', function () {
    [$code, $out] = spOutput('01JNOPENOPENOPENOPENOPENO1');

    expect($code)->toBe(1)->and($out)->toContain('No post with the id 01JNOPENOPENOPENOPENOPENO1.');
});

it('is read-only', function () {
    $post = spStory();
    $before = [$post->fresh()->updated_at, PublishAttempt::query()->count(), SocialApprovalDecision::query()->count()];

    spOutput($post->ulid);

    expect([$post->fresh()->updated_at, PublishAttempt::query()->count(), SocialApprovalDecision::query()->count()])->toEqual($before);
});
