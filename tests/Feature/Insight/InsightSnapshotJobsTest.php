<?php

use App\Jobs\PruneInsightReports;
use App\Jobs\RefreshFollowerCount;
use App\Jobs\SnapshotFollowerCounts;
use App\Models\FollowerSnapshot;
use App\Models\InsightReport;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
    ]);
});

it('keeps a daily follower snapshot whenever the count is refreshed', function () {
    Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'data' => ['followers_count' => 4321]])]);
    $account = SocialAccount::factory()->create(['provider' => 'outstand']);

    RefreshFollowerCount::dispatchSync($account->id);

    expect(FollowerSnapshot::query()->where('social_account_id', $account->id)->sole())
        ->followers->toBe(4321)
        ->on->toDateString()->toBe(now()->toDateString());
});

it('keeps no snapshot when the network does not report followers', function () {
    Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'data' => ['followers_count' => null]])]);
    $account = SocialAccount::factory()->create(['provider' => 'outstand']);

    RefreshFollowerCount::dispatchSync($account->id);

    expect(FollowerSnapshot::count())->toBe(0);
});

it('queues a refresh for every usable account once a day', function () {
    Queue::fake();
    $usable = SocialAccount::factory()->count(2)->create(['provider' => 'outstand']);
    SocialAccount::factory()->create(['provider' => 'postforme']); // another relay's row

    (new SnapshotFollowerCounts)->handle();

    Queue::assertPushed(RefreshFollowerCount::class, 2);
    Queue::assertPushed(RefreshFollowerCount::class, fn ($job) => $job->accountId === $usable[0]->id);
});

it('removes expired report files and keeps the row', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('insight-reports/a.pdf', '%PDF-');
    Storage::disk('s3')->put('insight-reports/b.pdf', '%PDF-');
    $owner = User::factory()->create();
    $make = fn (string $path, $expires) => InsightReport::create([
        'user_id' => $owner->id, 'owner_id' => $owner->id, 'client_id' => $owner->ulid,
        'format' => 'deck', 'locale' => 'en', 'period_from' => '2026-10-01', 'period_to' => '2026-10-31',
        'options' => ['period_kind' => 'month', 'channel_ids' => [], 'show_logo' => false, 'hide_branding' => false, 'next_month_notes' => null],
        'status' => 'done', 'file_name' => 'x.pdf', 'path' => $path, 'expires_at' => $expires,
    ]);
    $old = $make('insight-reports/a.pdf', now()->subHour());
    $fresh = $make('insight-reports/b.pdf', now()->addHour());

    (new PruneInsightReports)->handle();

    Storage::disk('s3')->assertMissing('insight-reports/a.pdf');
    Storage::disk('s3')->assertExists('insight-reports/b.pdf');
    expect($old->fresh()->path)->toBeNull()
        ->and($old->fresh()->status)->toBe('done')
        ->and($fresh->fresh()->path)->toBe('insight-reports/b.pdf');
});
