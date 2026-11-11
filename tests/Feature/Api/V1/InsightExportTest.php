<?php

use App\Http\Controllers\Api\V1\InsightExportController;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

// GET /api/v1/insight/export.csv — Insight, Pro and Max.

beforeEach(fn () => config([
    'services.postforme.key' => 'test-key',
    'services.postforme.base_url' => 'https://api.postforme.test/v1',
    'app.timezone' => 'Asia/Jakarta',
]));

/** @return list<list<string>> */
function csvRows(string $body): array
{
    return array_map(fn ($line) => str_getcsv($line, escape: ''), array_values(array_filter(explode("\n", $body))));
}

function feedPost(string $id, string $postedAt, array $metrics = [], array $extra = []): array
{
    return array_merge(['platform_post_id' => $id, 'caption' => "Caption {$id}", 'platform_url' => "https://ig.test/{$id}",
        'posted_at' => $postedAt, 'media' => [], 'metrics' => $metrics], $extra);
}

it('gives Free the standard 402 plan_required body with feature insight', function () {
    Http::fake();

    $this->actingAs(mediaUser('free'))
        ->get('/api/v1/insight/export.csv?from=2026-11-01&to=2026-11-30', ['Accept' => 'application/json'])
        ->assertStatus(402)
        ->assertJsonPath('reason', 'plan_required')
        ->assertJsonPath('feature', 'insight')
        ->assertJsonPath('required_plan', 'pro');

    Http::assertNothingSent();
});

it('streams the columns in order, one row per post inside the date range', function () {
    $user = mediaUser('pro');
    $account = SocialAccount::factory()->for($user)->create(['platform' => 'instagram', 'username' => 'kopi']);
    $ours = SocialPost::factory()->for($user)->create();

    // Newest first, as the feeds come. Jakarta is UTC+7: 30 Nov 17:30Z is
    // already 1 Dec there (out), 31 Oct 17:30Z is 1 Nov there (in).
    Http::fake(['api.postforme.test/*' => Http::response([
        'data' => [
            feedPost('dec', '2026-11-30T17:30:00Z', ['views' => 1]),
            feedPost('late', '2026-11-30T16:59:59Z', ['views' => 120, 'likes' => 9, 'saved' => 2], ['external_post_id' => $ours->ulid]),
            feedPost('x', '2026-11-15T08:00:00Z', ['public_metrics' => ['like_count' => 4, 'reply_count' => 1, 'quotes' => 3]]),
            feedPost('early', '2026-10-31T17:30:00Z', ['reach' => 80]),
            feedPost('oct', '2026-10-31T16:00:00Z', ['views' => 5]),
        ],
        'meta' => ['next_cursor' => 'more'],
    ])]);

    $response = $this->actingAs($user)->get('/api/v1/insight/export.csv?from=2026-11-01&to=2026-11-30');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('insight-2026-11-01-to-2026-11-30.csv');
    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class);

    $rows = csvRows($response->streamedContent());

    expect($rows[0])->toBe([
        'date', 'posted_at', 'platform', 'channel', 'post_id', 'url',
        'views', 'likes', 'comments', 'shares', 'saves', 'reach', 'caption',
    ])->and($rows[0])->toBe(InsightExportController::COLUMNS)
        ->and(array_slice($rows, 1))->toBe([
            ['2026-11-30', '2026-11-30T16:59:59Z', 'instagram', 'instagram @kopi', $ours->ulid, 'https://ig.test/late', '120', '9', '', '', '2', '', 'Caption late'],
            ['2026-11-15', '2026-11-15T08:00:00Z', 'instagram', 'instagram @kopi', '', 'https://ig.test/x', '', '4', '', '3', '', '', 'Caption x'],
            ['2026-11-01', '2026-10-31T17:30:00Z', 'instagram', 'instagram @kopi', '', 'https://ig.test/early', '', '', '', '', '', '80', 'Caption early'],
        ]);

    // The page reached back past `from`: the next page is not read.
    Http::assertSentCount(1);
});

it('reads further pages until it passes the start date', function () {
    $user = mediaUser('max');
    SocialAccount::factory()->for($user)->create();

    Http::fake(['api.postforme.test/*' => Http::sequence()
        ->push(['data' => [feedPost('a', '2026-11-20T03:00:00Z')], 'meta' => ['next_cursor' => 'p2']])
        ->push(['data' => [feedPost('b', '2026-11-02T03:00:00Z'), feedPost('c', '2026-10-01T03:00:00Z')], 'meta' => ['next_cursor' => 'p3']])
        ->push(['data' => [feedPost('never', '2026-11-10T03:00:00Z')], 'meta' => ['next_cursor' => null]]),
    ]);

    $rows = csvRows($this->actingAs($user)->get('/api/v1/insight/export.csv?from=2026-11-01&to=2026-11-30')->streamedContent());

    expect(array_column(array_slice($rows, 1), 5))->toBe(['https://ig.test/a', 'https://ig.test/b']);
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'cursor=p2'));
});

it('exports only the channels asked for, and refuses channels outside the workspace', function () {
    $user = mediaUser('pro');
    $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
    $fb = SocialAccount::factory()->for($user)->create(['platform' => 'facebook']);
    $foreign = SocialAccount::factory()->create();

    Http::fake(['api.postforme.test/*' => Http::response(['data' => [feedPost('p', '2026-11-05T03:00:00Z')], 'meta' => ['next_cursor' => null]])]);

    $rows = csvRows($this->actingAs($user)->get("/api/v1/insight/export.csv?from=2026-11-01&to=2026-11-30&channels={$fb->ulid}")->streamedContent());
    expect(array_column(array_slice($rows, 1), 2))->toBe(['facebook']);
    Http::assertSent(fn ($r) => str_contains($r->url(), $fb->provider_account_id));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), $ig->provider_account_id));

    $rows = csvRows($this->actingAs($user)->get('/api/v1/insight/export.csv?from=2026-11-01&to=2026-11-30')->streamedContent());
    expect(array_column(array_slice($rows, 1), 2))->toBe(['instagram', 'facebook']);

    $this->actingAs($user)
        ->getJson("/api/v1/insight/export.csv?from=2026-11-01&to=2026-11-30&channels={$ig->ulid},{$foreign->ulid}")
        ->assertJsonValidationErrors('channels');
});

it('validates the date range', function (string $query, string $field) {
    Http::fake();

    $this->actingAs(mediaUser('pro'))
        ->getJson('/api/v1/insight/export.csv?'.$query)
        ->assertJsonValidationErrors($field);
})->with([
    'missing from' => ['to=2026-11-30', 'from'],
    'missing to' => ['from=2026-11-01', 'to'],
    'not a date' => ['from=1-11-2026&to=2026-11-30', 'from'],
    'backwards' => ['from=2026-11-30&to=2026-11-01', 'to'],
    'over a year' => ['from=2025-01-01&to=2026-11-30', 'to'],
]);

it('keeps going when one channel feed fails, and never starts a formula', function () {
    $user = mediaUser('pro');
    $broken = SocialAccount::factory()->for($user)->create();
    SocialAccount::factory()->for($user)->create();

    Http::fake([
        "api.postforme.test/v1/social-account-feeds/{$broken->provider_account_id}*" => Http::response(['message' => 'no'], 403),
        'api.postforme.test/*' => Http::response(['data' => [
            feedPost('p', '2026-11-05T03:00:00Z', [], ['caption' => '=HYPERLINK("http://evil")']),
        ], 'meta' => ['next_cursor' => null]]),
    ]);

    $rows = csvRows($this->actingAs($user)->get('/api/v1/insight/export.csv?from=2026-11-01&to=2026-11-30')->streamedContent());

    expect($rows)->toHaveCount(2)
        ->and($rows[1][12])->toBe('\'=HYPERLINK("http://evil")');
});
