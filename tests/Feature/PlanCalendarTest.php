<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PlanCalendar;
use App\Support\Workspace;
use Carbon\CarbonImmutable;

/*
 * Content Plan calendar feed — GET/POST/DELETE /social-approval-links/{id}/calendar
 * (owner; GET is MCP get_plan_calendar_link), GET /content-plans/{token}/calendar.ics
 * (signed link). Only scheduled posts in the plan's dates and channels;
 * titles are the first 60 characters of the caption.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    $this->travelTo(CarbonImmutable::parse('2026-11-20 03:00:00', 'UTC'));
    Workspace::forget();
    ensurePassportKeys();
});

/** @return array{User, SocialApprovalLink, SocialAccount, SocialAccount} a December plan (Jakarta) on the first channel only */
function pcPlan(): array
{
    $owner = User::query()->findOrFail(User::factory()->create()->id);
    Subscription::factory()->for($owner)->create();
    $ig = SocialAccount::factory()->for($owner)->create(['platform' => 'instagram', 'username' => 'brand']);
    $fb = SocialAccount::factory()->for($owner)->create(['platform' => 'facebook', 'username' => 'brandfb']);
    $link = SocialApprovalLink::create([
        'user_id' => $owner->id, 'title' => 'December', 'approver_email' => 'rani@client.test',
        'scope_from' => '2026-12-01', 'scope_to' => '2026-12-31', 'scope_timezone' => 'Asia/Jakarta',
        'scope_starts_at' => CarbonImmutable::parse('2026-11-30 17:00:00', 'UTC'),
        'scope_ends_at' => CarbonImmutable::parse('2026-12-31 17:00:00', 'UTC'),
        'scope_account_ids' => [$ig->id],
    ]);

    return [$owner, $link, $ig, $fb];
}

function pcPost(User $owner, SocialAccount $account, string $utc, string $caption, array $attrs = []): SocialPost
{
    $post = SocialPost::factory()->for($owner)->create($attrs + [
        'provider_post_id' => null, 'status' => 'scheduled', 'approval' => 'client',
        'scheduled_at' => CarbonImmutable::parse($utc, 'UTC'), 'caption' => $caption,
    ]);
    $post->targets()->create(['social_account_id' => $account->id, 'status' => 'pending']);

    return $post;
}

function pcFeedUrl($test, User $owner, SocialApprovalLink $link): string
{
    return $test->actingAs($owner)->getJson("/api/v1/social-approval-links/{$link->ulid}/calendar")
        ->assertOk()->assertJsonPath('data.link_id', $link->ulid)->assertJsonPath('data.enabled', true)->json('data.url');
}

function pcPath(string $url): string
{
    return (string) parse_url($url, PHP_URL_PATH);
}

it('lists only scheduled posts inside the plan’s dates and channels, titled with 60 characters of the caption', function () {
    [$owner, $link, $ig, $fb] = pcPlan();
    $in = pcPost($owner, $ig, '2026-12-05 03:00:00', str_repeat('Launch day with a very long caption ', 5));
    $before = pcPost($owner, $ig, '2026-11-30 16:59:00', 'Before the plan (23:59 WIB)');
    $after = pcPost($owner, $ig, '2026-12-31 17:00:00', 'After the plan (00:00 WIB)');
    $otherChannel = pcPost($owner, $fb, '2026-12-06 03:00:00', 'On Facebook only');
    $draft = pcPost($owner, $ig, '2026-12-07 03:00:00', 'A draft', ['status' => 'draft']);
    $published = pcPost($owner, $ig, '2026-12-08 03:00:00', 'Already out', ['status' => 'published', 'provider_post_id' => 'p1']);

    $url = pcFeedUrl($this, $owner, $link);
    app('auth')->forgetGuards();
    $this->flushSession();

    $res = $this->get(pcPath($url))->assertOk();
    expect($res->headers->get('Content-Type'))->toStartWith('text/calendar');
    $ics = $res->getContent();
    $unfolded = str_replace("\r\n ", '', $ics);

    expect(substr_count($ics, 'BEGIN:VEVENT'))->toBe(1)
        ->and($unfolded)->toContain('UID:'.$in->ulid.'@creatif.space')
        ->and($unfolded)->toContain('DTSTART:20261205T030000Z')
        ->and($unfolded)->toContain('SUMMARY:'.mb_substr('Launch day with a very long caption Launch day with a very long caption', 0, 60))
        ->and($unfolded)->toContain('DESCRIPTION:Channels: instagram @brand');
    foreach ([$before, $after, $otherChannel, $draft, $published] as $out) {
        expect($ics)->not->toContain($out->ulid);
    }
    foreach (explode("\r\n", $ics) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }
    expect(str_ends_with($ics, "END:VCALENDAR\r\n"))->toBeTrue();
});

it('escapes caption text and keeps titles to 60 characters', function () {
    expect(PlanCalendar::title("Hello,\n  world; again"))->toBe('Hello, world; again')
        ->and(mb_strlen(PlanCalendar::title(str_repeat('é', 100))))->toBe(60)
        ->and(PlanCalendar::title('   '))->toBe('Post');

    [$owner, $link, $ig] = pcPlan();
    pcPost($owner, $ig, '2026-12-05 03:00:00', 'Sale, 50%; today\\only');
    $ics = str_replace("\r\n ", '', $this->get(pcPath(pcFeedUrl($this, $owner, $link)))->getContent());
    expect($ics)->toContain('SUMMARY:Sale\, 50%\; today\\\\only');
});

it('gives the same URL every time and stores no token', function () {
    [$owner, $link] = pcPlan();
    expect(pcFeedUrl($this, $owner, $link))->toBe(pcFeedUrl($this, $owner, $link))
        ->and(pcFeedUrl($this, $owner, $link))->toContain('/api/v1/content-plans/'.$link->ulid.'-');
});

it('answers 404 once the feed is turned off or rotated, the plan revoked or expired, or the signature is wrong', function () {
    [$owner, $link, $ig] = pcPlan();
    pcPost($owner, $ig, '2026-12-05 03:00:00', 'Launch');

    $first = pcFeedUrl($this, $owner, $link);
    $this->get(pcPath($first))->assertOk();

    // Off: the URL dies, GET says so.
    $this->actingAs($owner)->deleteJson("/api/v1/social-approval-links/{$link->ulid}/calendar")
        ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.url', null);
    $this->get(pcPath($first))->assertNotFound();
    $this->actingAs($owner)->getJson("/api/v1/social-approval-links/{$link->ulid}/calendar")->assertJsonPath('data.enabled', false);

    // On again: a new URL, the old one stays dead.
    $second = $this->actingAs($owner)->postJson("/api/v1/social-approval-links/{$link->ulid}/calendar")
        ->assertOk()->assertJsonPath('data.enabled', true)->json('data.url');
    expect($second)->not->toBe($first);
    $this->get(pcPath($first))->assertNotFound();
    $this->get(pcPath($second))->assertOk();

    // Rotate: a new URL without turning it off.
    $third = $this->actingAs($owner)->postJson("/api/v1/social-approval-links/{$link->ulid}/calendar", ['rotate' => true])->json('data.url');
    $this->get(pcPath($second))->assertNotFound();
    $this->get(pcPath($third))->assertOk();

    // A forged signature, or another link's ulid with this signature.
    [, $other] = pcPlan();
    $this->get(str_replace($link->ulid, $other->ulid, pcPath($third)))->assertNotFound();
    $this->get('/api/v1/content-plans/'.$link->ulid.'-AAAAAAAA/calendar.ics')->assertNotFound();

    $link->update(['revoked_at' => now()]);
    $this->get(pcPath($third))->assertNotFound();
    $link->update(['revoked_at' => null, 'expires_at' => now()->subMinute()]);
    $this->get(pcPath($third))->assertNotFound();
});

it('is for live Content Plans of the caller’s own workspace only', function () {
    [$owner, $link] = pcPlan();
    $list = SocialApprovalLink::create(['user_id' => $owner->id, 'title' => 'List', 'approver_email' => 'rani@client.test']);
    $this->actingAs($owner)->getJson("/api/v1/social-approval-links/{$list->ulid}/calendar")
        ->assertStatus(422)->assertJsonPath('reason', 'not_a_plan');

    Workspace::forget();
    $this->actingAs(User::factory()->create())->getJson("/api/v1/social-approval-links/{$link->ulid}/calendar")->assertNotFound();
    Workspace::forget();
    $this->actingAs(User::factory()->create())->postJson("/api/v1/social-approval-links/{$link->ulid}/calendar")->assertNotFound();
    Workspace::forget();
    $this->actingAs(User::factory()->create())->deleteJson("/api/v1/social-approval-links/{$link->ulid}/calendar")->assertNotFound();

    $link->update(['revoked_at' => now()]);
    Workspace::forget();
    $this->actingAs($owner)->getJson("/api/v1/social-approval-links/{$link->ulid}/calendar")
        ->assertStatus(409)->assertJsonPath('reason', 'link_revoked');
});

it('is reachable with a read token (get_plan_calendar_link); turning it off is not', function () {
    [$owner, $link] = pcPlan();
    $read = $this->actingAs($owner, 'web')->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'scopes' => ['read']])->json('data.key');
    app('auth')->forgetGuards();

    bearer($this, $read)->getJson("/api/v1/social-approval-links/{$link->ulid}/calendar")->assertOk()->assertJsonPath('data.enabled', true);
    app('auth')->forgetGuards();
    bearer($this, $read)->deleteJson("/api/v1/social-approval-links/{$link->ulid}/calendar")->assertForbidden();
    expect($link->fresh()->calendarOn())->toBeTrue();
});
