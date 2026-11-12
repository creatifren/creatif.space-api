<?php

use App\Jobs\BuildInsightReport;
use App\Models\InsightReport;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\Subscription;
use App\Models\TeamMember;
use App\Models\User;
use App\Support\Insight\InsightRenderer;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InsightFixture;

/*
 * The contract the export sheet is built against:
 *
 *   GET  /api/v1/insight/clients
 *   POST /api/v1/insight/reports   → 202 {id, status: "queued", file_name, …}
 *   GET  /api/v1/insight/reports/{id} → {status, download_url, share_url, file_name, …}
 */

beforeEach(function () {
    Workspace::forget();
    $this->travelTo(CarbonImmutable::parse(InsightFixture::NOW, config('app.timezone')));
    InsightFixture::fakeRelay();
    Storage::fake('s3');
});

function reportBody(User $owner, array $overrides = []): array
{
    return array_merge([
        'client_id' => $owner->ulid,
        'period' => ['month' => '2026-10'],
        'format' => 'onepage',
    ], $overrides);
}

it('lists the workspace as the client, with its connected channels', function () {
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace();

    $this->actingAs($owner)->getJson('/api/v1/insight/clients')
        ->assertOk()
        ->assertJsonPath('data.0.id', $owner->ulid)
        ->assertJsonPath('data.0.name', 'Kopi Senja')
        ->assertJsonPath('data.0.slug', 'kopi-senja')
        ->assertJsonCount(5, 'data.0.channels')
        ->assertJsonPath('data.0.channels.0.id', $accounts['instagram']->ulid)
        ->assertJsonPath('data.0.channels.0.network', 'Instagram');
});

it('refuses Free with 402 plan_required, feature insight', function () {
    Queue::fake();
    ['owner' => $owner] = InsightFixture::workspace('free');

    $this->actingAs($owner)->postJson('/api/v1/insight/reports', reportBody($owner))
        ->assertStatus(402)
        ->assertJsonPath('reason', 'plan_required')
        ->assertJsonPath('code', 'plan_required')
        ->assertJsonPath('feature', 'insight')
        ->assertJsonPath('required_plan', 'pro');

    Queue::assertNothingPushed();
    expect(InsightReport::count())->toBe(0);
});

it('refuses Free on the clients list and on a report it made before downgrading', function () {
    ['owner' => $owner] = InsightFixture::workspace('free');
    $report = InsightReport::create([
        'user_id' => $owner->id, 'owner_id' => $owner->id, 'client_id' => $owner->ulid,
        'format' => 'deck', 'locale' => 'en', 'period_from' => '2026-10-01', 'period_to' => '2026-10-31',
        'options' => ['period_kind' => 'month', 'channel_ids' => [], 'show_logo' => false, 'hide_branding' => false, 'next_month_notes' => null],
        'status' => 'done', 'file_name' => 'x.pdf', 'path' => 'insight-reports/x.pdf', 'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($owner)->getJson('/api/v1/insight/clients')
        ->assertStatus(402)->assertJsonPath('feature', 'insight');
    $this->actingAs($owner)->getJson("/api/v1/insight/reports/{$report->ulid}")
        ->assertStatus(402)->assertJsonPath('feature', 'insight')->assertJsonMissingPath('download_url');
});

it('accepts Pro and Max, queues the build and answers 202', function (string $plan) {
    Queue::fake();
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace($plan);

    $response = $this->actingAs($owner)->postJson('/api/v1/insight/reports', reportBody($owner, ['format' => 'deck']))
        ->assertStatus(202)
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('file_name', 'kopi-senja-2026-10-deck.pdf')
        ->assertJsonPath('download_url', null)
        ->assertJsonPath('share_url', null);

    $report = InsightReport::query()->where('ulid', $response->json('id'))->firstOrFail();
    expect($report->options['channel_ids'])->toBe(collect($accounts)->pluck('ulid')->values()->all())
        ->and($report->locale)->toBe('en')
        ->and($report->period_from->toDateString())->toBe('2026-10-01')
        ->and($report->period_to->toDateString())->toBe('2026-10-31');

    Queue::assertPushed(BuildInsightReport::class, fn ($job) => $job->report->is($report));
})->with(['pro', 'max']);

it('lets a teammate export on the owner\'s plan', function () {
    Queue::fake();
    ['owner' => $owner] = InsightFixture::workspace('pro');
    $member = User::factory()->create();
    Subscription::query()->where('user_id', $owner->id)->update(['seats' => 2]);
    TeamMember::factory()->active($member)->create(['owner_id' => $owner->id]);
    Workspace::forget();

    $this->actingAs($member)->postJson('/api/v1/insight/reports', reportBody($owner))->assertStatus(202);
});

it('keeps "Made with Creatif Space" on Free even when asked to hide it', function () {
    Queue::fake();
    // A plan row that switches Insight on for Free (as plans v2 could).
    Plan::query()->where('key', 'free')->first()->forceFill(['features' => ['insight' => true]])->save();
    ['owner' => $owner] = InsightFixture::workspace('free');

    $id = $this->actingAs($owner)->postJson('/api/v1/insight/reports', reportBody($owner, ['hide_branding' => true]))
        ->assertStatus(202)->json('id');

    expect(InsightReport::query()->where('ulid', $id)->value('options')['hide_branding'] ?? null)->toBeFalse();
});

it('lets Pro hide the branding', function () {
    Queue::fake();
    ['owner' => $owner] = InsightFixture::workspace('pro');

    $id = $this->actingAs($owner)->postJson('/api/v1/insight/reports', reportBody($owner, ['hide_branding' => true, 'show_logo' => true, 'locale' => 'id', 'next_month_notes' => 'Fokus TikTok']))
        ->assertStatus(202)->json('id');

    $options = InsightReport::query()->where('ulid', $id)->firstOrFail()->options;
    expect($options['hide_branding'])->toBeTrue()
        ->and($options['show_logo'])->toBeTrue()
        ->and($options['next_month_notes'])->toBe('Fokus TikTok');
});

it('validates the period: a month, or a range of at most 92 days, not in the future', function (array $period, string $field) {
    Queue::fake();
    ['owner' => $owner] = InsightFixture::workspace();

    $this->actingAs($owner)->postJson('/api/v1/insight/reports', reportBody($owner, ['period' => $period]))
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    'bad month' => [['month' => '2026-13'], 'period.month'],
    'future month' => [['month' => '2026-12'], 'period.month'],
    '93 days' => [['from' => '2026-07-01', 'to' => '2026-10-01'], 'period.to'],
    'reversed' => [['from' => '2026-10-10', 'to' => '2026-10-01'], 'period.to'],
    'future range' => [['from' => '2026-11-10', 'to' => '2026-11-20'], 'period.from'],
    'nothing' => [[], 'period'],
]);

it('accepts a 92-day range and names the file by its first month', function () {
    Queue::fake();
    ['owner' => $owner] = InsightFixture::workspace();

    $this->actingAs($owner)->postJson('/api/v1/insight/reports', reportBody($owner, ['period' => ['from' => '2026-08-01', 'to' => '2026-10-31'], 'format' => 'details']))
        ->assertStatus(202)
        ->assertJsonPath('file_name', 'kopi-senja-2026-08-details.pdf');
});

it('refuses an unknown client, a foreign channel and an unknown format', function () {
    Queue::fake();
    ['owner' => $owner] = InsightFixture::workspace();
    $foreign = SocialAccount::factory()->create();

    $this->actingAs($owner)->postJson('/api/v1/insight/reports', reportBody($owner, ['client_id' => User::factory()->create()->ulid]))
        ->assertStatus(422)->assertJsonValidationErrors('client_id');
    $this->actingAs($owner)->postJson('/api/v1/insight/reports', reportBody($owner, ['channel_ids' => [$foreign->ulid]]))
        ->assertStatus(422)->assertJsonValidationErrors('channel_ids');
    $this->actingAs($owner)->postJson('/api/v1/insight/reports', reportBody($owner, ['format' => 'poster']))
        ->assertStatus(422)->assertJsonValidationErrors('format');
});

it('goes queued → done and hands out a short-lived download link', function () {
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace();

    // The queue runs synchronously in tests: POST builds it right away.
    $id = $this->actingAs($owner)->postJson('/api/v1/insight/reports', reportBody($owner, [
        'channel_ids' => [$accounts['instagram']->ulid, $accounts['facebook']->ulid],
    ]))->assertStatus(202)->json('id');

    $response = $this->actingAs($owner)->getJson("/api/v1/insight/reports/{$id}")
        ->assertOk()
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('page_count', 1)
        ->assertJsonPath('file_name', 'kopi-senja-2026-10-onepage.pdf')
        ->assertJsonPath('share_url', null);

    expect($response->json('download_url'))->toBeString()->not->toBeEmpty()
        ->and($response->json('download_expires_at'))->toBeString();
});

it('ends failed when the build keeps failing', function () {
    ['owner' => $owner] = InsightFixture::workspace();
    $renderer = Mockery::mock(InsightRenderer::class);
    $renderer->shouldReceive('render')->andThrow(new RuntimeException('boom'));
    app()->instance(InsightRenderer::class, $renderer);

    $report = InsightReport::create([
        'user_id' => $owner->id, 'owner_id' => $owner->id, 'client_id' => $owner->ulid,
        'format' => 'onepage', 'locale' => 'en', 'period_from' => '2026-10-01', 'period_to' => '2026-10-31',
        'options' => ['period_kind' => 'month', 'channel_ids' => [], 'show_logo' => false, 'hide_branding' => false, 'next_month_notes' => null],
        'status' => 'queued', 'file_name' => 'kopi-senja-2026-10-onepage.pdf',
    ]);

    $job = new BuildInsightReport($report);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
    expect($report->fresh()->status)->toBe('building');

    $job->failed(new RuntimeException('boom'));

    $this->actingAs($owner)->getJson("/api/v1/insight/reports/{$report->ulid}")
        ->assertOk()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('failure_reason', 'build_failed')
        ->assertJsonPath('download_url', null);
});

it('stops handing out the link once the file has expired', function () {
    ['owner' => $owner] = InsightFixture::workspace();
    $report = InsightReport::create([
        'user_id' => $owner->id, 'owner_id' => $owner->id, 'client_id' => $owner->ulid,
        'format' => 'deck', 'locale' => 'en', 'period_from' => '2026-10-01', 'period_to' => '2026-10-31',
        'options' => ['period_kind' => 'month', 'channel_ids' => [], 'show_logo' => false, 'hide_branding' => false, 'next_month_notes' => null],
        'status' => 'done', 'file_name' => 'x.pdf', 'path' => 'insight-reports/x.pdf', 'expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($owner)->getJson("/api/v1/insight/reports/{$report->ulid}")
        ->assertOk()
        ->assertJsonPath('expired', true)
        ->assertJsonPath('download_url', null);
});

it("does not show another workspace's report", function () {
    ['owner' => $owner] = InsightFixture::workspace();
    $report = InsightReport::create([
        'user_id' => $owner->id, 'owner_id' => $owner->id, 'client_id' => $owner->ulid,
        'format' => 'deck', 'locale' => 'en', 'period_from' => '2026-10-01', 'period_to' => '2026-10-31',
        'options' => ['period_kind' => 'month', 'channel_ids' => [], 'show_logo' => false, 'hide_branding' => false, 'next_month_notes' => null],
        'status' => 'queued', 'file_name' => 'x.pdf',
    ]);

    $this->actingAs(User::factory()->create())->getJson("/api/v1/insight/reports/{$report->ulid}")->assertNotFound();
});
