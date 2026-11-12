<?php

use App\Models\SocialAccount;
use App\Models\Subscription;
use App\Models\TeamMember;
use App\Models\User;
use App\Support\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
 * QA (11 Nov 2026): a Free account still saw the Insight overview with
 * data at /post/analytics. Every Insight read answers the `insight` gate on
 * Free: /me/analytics with 402; the account feed (shared with Grid) with
 * its posts but no numbers, and the same plan_required body in meta.insight.
 */

beforeEach(function () {
    Workspace::forget();
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
        'app.frontend_url' => 'https://creatif.space',
    ]);
    Http::fake(['api.postforme.test/*' => Http::response([
        'data' => [
            ['platform_post_id' => 'ig1', 'caption' => 'One', 'media' => [], 'metrics' => ['views' => 120, 'likes' => 9, 'reach' => 80]],
            ['platform_post_id' => 'ig2', 'caption' => 'Two', 'media' => [], 'metrics' => ['views' => 30, 'comments' => 2]],
        ],
        'meta' => ['next_cursor' => null],
    ])]);
});

function igPro(string $status = 'active', int $seats = 1): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['status' => $status, 'seats' => $seats]);

    return $user;
}

function igMember(User $owner): User
{
    $member = User::factory()->create();
    TeamMember::factory()->for($owner, 'owner')->active($member)->create();

    return $member;
}

const IG_INSIGHT_402 = [
    'reason' => 'plan_required',
    'feature' => 'insight',
    'required_plan' => 'pro',
    'code' => 'plan_required',
    'billing_url' => 'https://creatif.space/settings?section=subscription',
];

describe('/me/analytics', function () {
    it('answers 402 insight on Free for every range', function (array $query) {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/me/analytics?'.http_build_query($query))
            ->assertStatus(402)
            ->assertJson(IG_INSIGHT_402);
    })->with([
        'default' => [[]],
        'this month' => [['range' => 'this_month']],
        'last month' => [['range' => 'last_month']],
        'this year' => [['range' => 'this_year']],
        'custom' => [['range' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31']],
    ]);

    it('answers a team member on the owner\'s plan (a Free workspace has no seats to share)', function () {
        $this->actingAs(igMember(igPro(seats: 2)))->getJson('/api/v1/me/analytics')->assertOk();
    });

    it('answers on Pro and on a Pro trial', function (string $status) {
        $this->actingAs(igPro($status))->getJson('/api/v1/me/analytics')->assertOk()->assertJsonPath('data.full', true);
    })->with(['active', 'trialing']);
});

describe('/social-accounts/{account}/feed — post insights', function () {
    it('keeps the posts on Free but withholds every metric and says why', function () {
        $user = User::factory()->create();
        $account = SocialAccount::factory()->for($user)->create();

        $res = $this->actingAs($user)->getJson("/api/v1/social-accounts/{$account->ulid}/feed")->assertOk();

        expect(collect($res->json('data'))->pluck('id')->all())->toBe(['ig1', 'ig2'])
            ->and(collect($res->json('data'))->pluck('metrics')->all())->toBe([null, null])
            ->and($res->json('meta.insight'))->toMatchArray(IG_INSIGHT_402)
            ->and($res->json('meta.insight'))->not->toHaveKey('errors')
            ->and($res->json('meta.insight.message'))->toContain('Insights and analytics is on Pro and Max');
        expect(json_encode($res->json()))->not->toContain('120')->not->toContain('"reach"');
    });

    it('shows the metrics on Pro and a Pro trial, with no lock', function (string $status) {
        $user = igPro($status);
        $account = SocialAccount::factory()->for($user)->create();

        $this->actingAs($user)->getJson("/api/v1/social-accounts/{$account->ulid}/feed")
            ->assertOk()
            ->assertJsonPath('data.0.metrics.views', 120)
            ->assertJsonPath('data.0.metrics.reach', 80)
            ->assertJsonPath('data.1.metrics.comments', 2)
            ->assertJsonPath('meta.insight', null);
    })->with(['active', 'trialing']);

    it('does not leak numbers cached by a Pro read to a later Free read of the same account', function () {
        $owner = igPro();
        $account = SocialAccount::factory()->for($owner)->create();
        $this->actingAs($owner)->getJson("/api/v1/social-accounts/{$account->ulid}/feed")->assertJsonPath('data.0.metrics.views', 120);

        $owner->subscriptions()->update(['status' => 'expired', 'current_period_end' => now()->subDays(8), 'grace_ends_at' => now()->subDay()]);
        Workspace::forget();

        $this->actingAs($owner->fresh())->getJson("/api/v1/social-accounts/{$account->ulid}/feed")
            ->assertJsonPath('data.0.metrics', null)
            ->assertJsonPath('meta.insight.feature', 'insight');
    });
});

it('has no Insight read route outside the gated list (new ones must be added here)', function () {
    $insightReads = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array('GET', $r->methods(), true))
        ->map(fn ($r) => $r->getName())
        ->filter(fn ($name) => $name !== null && preg_match('/analytic|insight|metric|stat(s|istic)/i', $name))
        ->values()
        ->all();

    // Each is gated on `insight`; the export's gate is in InsightExportTest,
    // the PDF report's in tests/Feature/Insight/InsightReportApiTest.php.
    expect($insightReads)->toEqualCanonicalizing([
        'api.v1.me.analytics',
        'api.v1.insight.export',
        'api.v1.insight.clients',
        'api.v1.insight.reports.show',
        // tests/Feature/Insight/InsightSummaryApiTest.php: 402 on Free.
        'api.v1.insight.summary',
        // tests/Feature/Insight/InsightTopPostsTest.php: 402 on Free.
        'api.v1.insight.top-posts',
        // tests/Feature/Insight/InsightBestTimesTest.php: 402 on Free.
        'api.v1.insight.best-times',
        // tests/Feature/Insight/InsightPdfExportTest.php: 402 on Free.
        'api.v1.insight.export.pdf',
        'api.v1.insight.export.signed',
        // tests/Feature/Insight/InsightCsvLinkTest.php: 402 on Free, also on use.
        'api.v1.insight.export.csv.signed',
        // Not Insight data: the monthly-email switch and whether the plan
        // sends it. Turning it on is gated (402), in InsightMonthlyEmailTest.
        'api.v1.me.insight-settings.show',
        // tests/Feature/Insight/InsightShareTest.php: the list is 402 on
        // Free; the public page is 404 once the workspace has no Insight.
        'api.v1.insight.share.index',
        'api.v1.public.insight.show',
    ]);
});
