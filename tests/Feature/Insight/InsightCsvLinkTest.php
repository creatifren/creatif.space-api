<?php

use App\Models\SocialAccount;
use App\Models\Subscription;
use App\Support\Workspace;
use Illuminate\Support\Facades\Http;

/*
 * POST /insight/export-csv-link — a 24-hour signed link to the Insight CSV
 * (MCP: get_insight_csv_link). The signature is the credential for that
 * user, range and channels; the plan is checked again on use.
 */

beforeEach(function () {
    Workspace::forget();
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
        'app.timezone' => 'Asia/Jakarta',
    ]);
    $this->travelTo(now()->setDate(2026, 12, 3));
    Http::fake(['api.postforme.test/*' => Http::response([
        'data' => [[
            'platform_post_id' => 'p1', 'caption' => 'Hello', 'platform_url' => 'https://ig.test/p1',
            'posted_at' => '2026-11-10T03:00:00Z', 'media' => [], 'metrics' => ['views' => 120, 'likes' => 9],
        ]],
        'meta' => ['next_cursor' => null],
    ])]);
});

it('gives a 24-hour signed link that downloads the same CSV without a login', function () {
    $user = mediaUser('pro');
    $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram', 'username' => 'kopi']);

    $res = $this->actingAs($user)->postJson('/api/v1/insight/export-csv-link', [
        'from' => '2026-11-01', 'to' => '2026-11-30', 'channels' => $ig->ulid,
    ])->assertOk();

    expect($res->json('file_name'))->toBe('insight-2026-11-01-to-2026-11-30.csv')
        ->and($res->json('columns.0'))->toBe('date')
        ->and(now()->diffInHours($res->json('expires_at')))->toEqualWithDelta(24, 0.01);

    app('auth')->forgetGuards();
    $csv = $this->get($res->json('url'))->assertOk()->streamedContent();
    expect($csv)->toContain('date,posted_at,platform')->toContain('https://ig.test/p1');
});

it('refuses an altered or expired link', function () {
    $user = mediaUser('pro');
    SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
    $url = $this->actingAs($user)->postJson('/api/v1/insight/export-csv-link', ['from' => '2026-11-01', 'to' => '2026-11-30'])->json('url');
    app('auth')->forgetGuards();

    $this->get(str_replace('2026-11-01', '2026-10-01', $url))->assertForbidden();

    $this->travel(25)->hours();
    $this->get($url)->assertForbidden();
});

it('checks the plan again when the link is opened', function () {
    $user = mediaUser('pro');
    SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
    $url = $this->actingAs($user)->postJson('/api/v1/insight/export-csv-link', ['from' => '2026-11-01', 'to' => '2026-11-30'])->json('url');
    app('auth')->forgetGuards();

    Subscription::query()->where('user_id', $user->id)->delete();
    Workspace::forget();

    $this->get($url, ['Accept' => 'application/json'])->assertStatus(402)->assertJsonPath('reason', 'plan_required');
});

it('validates the range and the channels when the link is made, and is Pro / Max', function () {
    $user = mediaUser('pro');
    SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);

    $this->actingAs($user)->postJson('/api/v1/insight/export-csv-link', ['from' => '2026-11-30', 'to' => '2026-11-01'])
        ->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->actingAs($user)->postJson('/api/v1/insight/export-csv-link', ['from' => '2026-11-01', 'to' => '2026-11-30', 'channels' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ'])
        ->assertUnprocessable()->assertJsonValidationErrors('channels');

    $this->actingAs(mediaUser('free'))->postJson('/api/v1/insight/export-csv-link', ['from' => '2026-11-01', 'to' => '2026-11-30'])
        ->assertStatus(402)->assertJsonPath('reason', 'plan_required');
});
