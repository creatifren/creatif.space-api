<?php

use App\Http\Controllers\Api\V1\InsightSummaryController;
use App\Models\McpAuditLog;
use App\Models\User;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InsightFixture;
use Tests\TestCase;

/*
 * GET /api/v1/insight/summary — the report's numbers without the PDF, read
 * by the MCP tool get_insight_summary. Same fixture as the PDF tests
 * (tests/Support/InsightFixture.php), so the numbers can be checked
 * against InsightAggregationTest.
 */

beforeEach(function () {
    Workspace::forget();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse(InsightFixture::NOW, config('app.timezone')));
    InsightFixture::fakeRelay();
});

/** A personal API key with $scopes, then nothing but the bearer token. */
function insightKey(TestCase $test, User $user, array $scopes): string
{
    $key = $test->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Insight key', 'scopes' => $scopes])
        ->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

it('answers the October numbers, with null where a network said nothing', function () {
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace();

    $res = $this->actingAs($owner)->getJson('/api/v1/insight/summary?month=2026-10')->assertOk();

    $res->assertJsonPath('data.period', ['from' => '2026-10-01', 'to' => '2026-10-31', 'kind' => 'month', 'label' => 'October 2026'])
        ->assertJsonPath('data.compared_with.label', 'September 2026')
        ->assertJsonPath('data.totals.posts', 36)
        ->assertJsonPath('data.totals.reach', 49800 + 15100)
        ->assertJsonPath('data.totals.engagement', 4732 + 1043 + 9919 + 443)
        ->assertJsonPath('data.totals.followers_change', 835)
        ->assertJsonPath('data.totals_previous.posts', 19)
        ->assertJsonPath('data.change_pct.posts', round((36 - 19) / 19 * 100, 1))
        ->assertJsonCount(5, 'data.weekly_reach')
        ->assertJsonPath('data.weekly_reach.0', ['week_start' => '2026-10-01', 'reach' => 11400])
        ->assertJsonCount(5, 'data.channels')
        ->assertJsonPath('data.channels.0.id', $accounts['instagram']->ulid)
        ->assertJsonPath('data.channels.0.saves', 501)
        ->assertJsonPath('data.channels.0.followers.start', 12400)
        ->assertJsonPath('data.channels.0.followers.end', 13150)
        ->assertJsonPath('data.channels.0.followers.change', 750)
        ->assertJsonPath('data.channels.0.followers.history_since', '2026-08-31')
        // Facebook: no saves. TikTok: no reach, so no rate. X: nothing before October, so no change.
        ->assertJsonPath('data.channels.1.saves', null)
        ->assertJsonPath('data.channels.2.reach', null)
        ->assertJsonPath('data.channels.2.engagement_rate', null)
        ->assertJsonPath('data.channels.3.change_pct.posts', null)
        // LinkedIn's feed failed: unavailable, every number null — never 0.
        ->assertJsonPath('data.channels.4.available', false)
        ->assertJsonPath('data.channels.4.posts', null)
        ->assertJsonPath('data.top_posts.0.engagement', 4680)
        ->assertJsonPath('data.top_posts.0.network', 'TikTok')
        ->assertJsonPath('data.workflow.posts_approved', 3)
        ->assertJsonPath('data.workflow.avg_approval_hours', 8.7)
        ->assertJsonPath('data.workflow.on_time', 2)
        ->assertJsonPath('data.workflow.on_time_known', 3)
        ->assertJsonPath('data.workflow.previous.posts_approved', 1);

    expect($res->json('data.totals.engagement_rate'))->toEqualWithDelta((4732 + 1043) / (49800 + 15100), 0.0001)
        ->and($res->json('data.notes'))->toContain('Saves not available from Facebook API')
        ->and($res->json('data.notes'))->toContain('Reach not available from TikTok API')
        ->and($res->json('data.notes'))->toContain('Not available from LinkedIn API')
        ->and($res->json('data.notes'))->toContain('Follower history for TikTok starts 12 Oct 2026.')
        ->and($res->json('data.definitions.0'))->toContain('Nothing is estimated');
});

it('reads a custom range and only the channels asked for', function () {
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace();

    $this->actingAs($owner)
        ->getJson('/api/v1/insight/summary?from=2026-10-01&to=2026-10-14&channel_ids='.$accounts['instagram']->ulid.','.$accounts['facebook']->ulid)
        ->assertOk()
        ->assertJsonPath('data.period.kind', 'range')
        ->assertJsonPath('data.compared_with', ['from' => '2026-09-17', 'to' => '2026-09-30', 'label' => '17 Sep – 30 Sep 2026'])
        ->assertJsonCount(2, 'data.channels')
        // IG 1–14 Oct: 5 posts; FB: 3.
        ->assertJsonPath('data.totals.posts', 8);
});

it('refuses Free with 402 plan_required, feature insight — on the app and with a token', function () {
    ['owner' => $owner] = InsightFixture::workspace('free');

    $this->actingAs($owner)->getJson('/api/v1/insight/summary?month=2026-10')
        ->assertStatus(402)
        ->assertJsonPath('reason', 'plan_required')
        ->assertJsonPath('feature', 'insight');

    $key = insightKey($this, $owner, ['read']);
    bearer($this, $key)->getJson('/api/v1/insight/summary?month=2026-10')
        ->assertStatus(402)
        ->assertJsonPath('code', 'plan_required')
        ->assertJsonPath('feature', 'insight')
        ->assertJsonPath('required_plan', 'pro');
});

it('is open to a read-scoped token on Pro, and says so in the audit log', function () {
    ['owner' => $owner] = InsightFixture::workspace('pro');
    $key = insightKey($this, $owner, ['read']);

    bearer($this, $key)->withHeaders(['X-MCP-Tool' => 'get_insight_summary'])
        ->getJson('/api/v1/insight/summary?month=2026-10')
        ->assertOk()
        ->assertJsonPath('data.totals.posts', 36);

    expect(McpAuditLog::query()->latest('id')->value('tool'))->toBe('get_insight_summary');
});

it('validates the period and the channels', function (string $query, string $field) {
    ['owner' => $owner] = InsightFixture::workspace();

    $this->actingAs($owner)->getJson('/api/v1/insight/summary?'.$query)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    'nothing' => ['', 'month'],
    'bad month' => ['month=2026-13', 'month'],
    'future month' => ['month=2026-12', 'month'],
    '93 days' => ['from=2026-07-01&to=2026-10-01', 'to'],
    'reversed' => ['from=2026-10-10&to=2026-10-01', 'to'],
    'unknown channel' => ['month=2026-10&channel_ids=01JNOTMINENOTMINENOTMINE00', 'channel_ids'],
]);

it('keeps an answer for a few minutes instead of walking the feeds again', function () {
    ['owner' => $owner] = InsightFixture::workspace();

    $this->actingAs($owner)->getJson('/api/v1/insight/summary?month=2026-10')->assertOk()->assertJsonPath('data.totals.posts', 36);

    // The relay now has nothing: a fresh read would say 0 posts.
    InsightFixture::fakeRelay(array_map(fn () => ['oct' => [], 'sep' => []], InsightFixture::posts()));
    $this->actingAs($owner)->getJson('/api/v1/insight/summary?month=2026-10')->assertOk()->assertJsonPath('data.totals.posts', 36);

    $this->travel(InsightSummaryController::CACHE_MINUTES + 1)->minutes();
    $this->actingAs($owner)->getJson('/api/v1/insight/summary?month=2026-10')->assertOk()->assertJsonPath('data.totals.posts', 0);
});
