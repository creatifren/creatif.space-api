<?php

use App\Contracts\SocialProvider;
use App\Jobs\BuildInsightReport;
use App\Models\FollowerSnapshot;
use App\Models\InsightReport;
use App\Models\SocialAccount;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Insight\InsightRenderer;
use App\Support\SocialProviders;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\InsightFixture;

/*
 * Before #52 can be merged: the Insight PDF path fails closed for Free
 * with the plans v2 402 shape everywhere (including a downgrade between
 * the request and the build), refuses bearer tokens, and stays fast on a
 * big workspace.
 */

beforeEach(function () {
    Workspace::forget();
    $this->travelTo(CarbonImmutable::parse(InsightFixture::NOW, config('app.timezone')));
    Storage::fake('s3');
});

/** Exactly the plans v2 refusal body, nothing more. */
function assertInsight402(TestResponse $response): void
{
    $response->assertStatus(402);
    $body = $response->json();

    expect(array_keys($body))->toEqualCanonicalizing(['message', 'reason', 'feature', 'required_plan', 'code', 'billing_url', 'errors'])
        ->and($body['reason'])->toBe('plan_required')
        ->and($body['code'])->toBe('plan_required')
        ->and($body['feature'])->toBe('insight')
        ->and($body['required_plan'])->toBe('pro')
        ->and($body['message'])->toContain('Pro and Max')
        ->and($body['billing_url'])->toEndWith('/settings?section=subscription')
        ->and($body['errors'])->toBe(['plan' => [$body['message']]]);
}

function insightReportRow(User $owner, array $channelIds, string $format = 'onepage', string $from = '2026-10-01', string $to = '2026-10-31', string $kind = 'month'): InsightReport
{
    return InsightReport::create([
        'user_id' => $owner->id, 'owner_id' => $owner->id, 'client_id' => $owner->ulid,
        'format' => $format, 'locale' => 'en', 'period_from' => $from, 'period_to' => $to,
        'options' => ['period_kind' => $kind, 'channel_ids' => $channelIds, 'show_logo' => false, 'hide_branding' => false, 'next_month_notes' => null],
        'status' => 'queued', 'file_name' => "x-{$format}.pdf",
    ]);
}

describe('fails closed on Free', function () {
    it('answers the 402 shape on every Insight route and queues nothing', function () {
        Queue::fake();
        InsightFixture::fakeRelay();
        ['owner' => $owner] = InsightFixture::workspace('free');
        $report = insightReportRow($owner, []);

        assertInsight402($this->actingAs($owner)->postJson('/api/v1/insight/reports', [
            'client_id' => $owner->ulid, 'period' => ['month' => '2026-10'], 'format' => 'details',
        ]));
        assertInsight402($this->actingAs($owner)->getJson('/api/v1/insight/clients'));
        assertInsight402($this->actingAs($owner)->getJson("/api/v1/insight/reports/{$report->ulid}"));

        Queue::assertNothingPushed();
        expect(InsightReport::count())->toBe(1); // only the row made by hand above
    });

    it('refuses the 402 even before validating a bad body', function () {
        ['owner' => $owner] = InsightFixture::workspace('free', false);

        assertInsight402($this->actingAs($owner)->postJson('/api/v1/insight/reports', []));
    });

    it('builds nothing when the workspace dropped to Free after asking', function () {
        InsightFixture::fakeRelay();
        ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace('pro');
        $report = insightReportRow($owner, collect($accounts)->pluck('ulid')->all());
        Subscription::query()->where('user_id', $owner->id)->update(['status' => 'expired', 'current_period_end' => now()->subDay(), 'grace_ends_at' => null]);

        $renderer = Mockery::mock(InsightRenderer::class);
        $renderer->shouldNotReceive('render');
        app()->instance(InsightRenderer::class, $renderer);

        dispatch_sync(new BuildInsightReport($report));

        expect($report->fresh())->status->toBe('failed')
            ->and($report->fresh()->failure_reason)->toBe('plan_required')
            ->and($report->fresh()->path)->toBeNull()
            ->and(Storage::disk('s3')->allFiles())->toBe([]);
    });

    it('keeps bearer tokens (API keys, connected apps) out of the PDF routes', function () {
        Queue::fake();
        ['owner' => $owner] = InsightFixture::workspace('pro', false);
        $key = $this->actingAs($owner, 'web')
            ->postJson('/api/v1/me/api-keys', ['name' => 'k', 'scopes' => ['read', 'draft', 'approve', 'publish']])
            ->json('data.key');
        app('auth')->forgetGuards();

        bearer($this, $key)->getJson('/api/v1/insight/clients')->assertForbidden();
        bearer($this, $key)->postJson('/api/v1/insight/reports', [
            'client_id' => $owner->ulid, 'period' => ['month' => '2026-10'], 'format' => 'deck',
        ])->assertForbidden();
        Queue::assertNothingPushed();
    });
});

describe('a big workspace', function () {
    /*
     * 10 channels, a 30-day range, four posts a day on each (1,200 posts in
     * the period and as many before it, each with every metric), follower
     * history, real thumbnails. Every layout must build in under 10 s.
     */
    beforeEach(function () {
        $owner = User::factory()->create(['name' => 'Big Agency']);
        Subscription::factory()->for($owner)->create();
        $platforms = ['instagram', 'facebook', 'tiktok', 'linkedin', 'threads', 'youtube', 'pinterest', 'bluesky', 'instagram', 'facebook'];
        $tz = config('app.timezone');
        $feeds = [];
        $accounts = [];

        foreach ($platforms as $i => $platform) {
            $account = SocialAccount::factory()->for($owner)->create([
                'platform' => $platform, 'username' => "brand{$i}",
                'provider' => SocialAccount::currentProvider(), 'provider_account_id' => "big_{$i}",
            ]);
            $accounts[] = $account;
            FollowerSnapshot::query()->create(['social_account_id' => $account->id, 'on' => '2026-09-01', 'followers' => 10000 + $i]);
            FollowerSnapshot::query()->create(['social_account_id' => $account->id, 'on' => '2026-10-31', 'followers' => 11000 + $i]);

            $items = [];
            $day = CarbonImmutable::parse('2026-10-31 20:00', $tz);
            foreach (range(0, 59 * 4) as $n) {
                $at = $day->subHours(6 * $n);
                $items[] = [
                    'platform_post_id' => "p{$i}_{$n}",
                    'caption' => "Post {$n} on channel {$i} — a caption long enough to need cutting at eighty characters for the report.",
                    'platform_url' => "https://example.test/{$i}/{$n}",
                    'posted_at' => $at->utc()->toIso8601String(),
                    'media' => ["https://cdn.example.test/big/{$i}/{$n}.jpg"],
                    'provider_post_id' => "op_{$i}_{$n}",
                    'metrics' => ['reach' => 1000 + $n, 'impressions' => 1500 + $n, 'likes' => 50 + $n % 40, 'comments' => 5, 'shares' => 2, 'saved' => 3],
                ];
            }
            $feeds["big_{$i}"] = $items;
        }

        $provider = Mockery::mock(SocialProvider::class);
        $provider->shouldReceive('feed')->andReturnUsing(function (string $id, ?string $cursor = null, int $limit = 25) use ($feeds) {
            $offset = (int) $cursor;

            return ['data' => array_slice($feeds[$id], $offset, $limit), 'cursor' => $offset + $limit < count($feeds[$id]) ? (string) ($offset + $limit) : null];
        });
        $providers = Mockery::mock(SocialProviders::class);
        $providers->shouldReceive('for')->andReturn($provider);
        app()->instance(SocialProviders::class, $providers);

        Http::fake(['cdn.example.test/*' => fn ($r) => Http::response(InsightFixture::jpeg(crc32($r->url())), 200, ['Content-Type' => 'image/jpeg'])]);

        $this->owner = $owner;
        $this->channelIds = collect($accounts)->pluck('ulid')->all();
    });

    it('builds every layout for 10 channels over 30 days in under 10 seconds', function (string $format, int $pages) {
        $report = insightReportRow($this->owner, $this->channelIds, $format, '2026-10-02', '2026-10-31', 'range');

        $started = hrtime(true);
        dispatch_sync(new BuildInsightReport($report));
        $seconds = (hrtime(true) - $started) / 1e9;

        $report->refresh();
        expect($report->status)->toBe('done')
            ->and($report->page_count)->toBe($pages)
            ->and($report->size_bytes)->toBeLessThan(5 * 1024 * 1024)
            ->and($seconds)->toBeLessThan(10.0);
    })->with([
        'one page' => ['onepage', 1],
        'deck' => ['deck', 3 + 10 + 1],        // cover, summary, workflow, 10 channels, top posts
        'details' => ['details', 2 + 10 + 1],  // cover, summary, 10 channels, method
    ]);
});
