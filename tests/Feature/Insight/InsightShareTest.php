<?php

use App\Models\InsightShareLink;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InsightFixture;
use Tests\TestCase;

/*
 * Shareable Insight links: POST/GET/DELETE /api/v1/insight/share and the
 * public page data at GET /api/v1/public/insight/{token}.
 */

beforeEach(function () {
    Workspace::forget();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse(InsightFixture::NOW, config('app.timezone')));
    InsightFixture::fakeRelay();
    config(['app.frontend_url' => 'https://app.creatif.test']);
});

function shareLink(TestCase $test, User $owner, array $body = []): array
{
    return $test->actingAs($owner)
        ->postJson('/api/v1/insight/share', $body + ['from' => '2026-10-01', 'to' => '2026-10-31'])
        ->assertCreated()
        ->json('data');
}

it('makes a 30-day link to the client report page and shows its token once', function () {
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace();

    $link = shareLink($this, $owner);

    expect($link['token'])->toBeString()->toHaveLength(43)
        ->and($link['url'])->toBe('https://app.creatif.test/report/'.$link['token'])
        ->and($link['expires_at'])->toBe(now()->addDays(30)->utc()->toIso8601String())
        ->and($link['status'])->toBe('active')
        ->and($link['period'])->toBe(['from' => '2026-10-01', 'to' => '2026-10-31', 'label' => '1 Oct – 31 Oct 2026'])
        ->and($link['channel_ids'])->toBe(collect($accounts)->pluck('ulid')->values()->all());

    // Stored hashed; the list never shows a token again.
    expect(InsightShareLink::query()->sole()->token_hash)->toBe(hash('sha256', $link['token']));
    $this->actingAs($owner)->getJson('/api/v1/insight/share')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.token')->assertJsonPath('data.0.id', $link['id']);
});

it('serves the report numbers to anyone with the token, and nothing else', function () {
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace();
    $link = shareLink($this, $owner, ['channels' => $accounts['instagram']->ulid.','.$accounts['facebook']->ulid]);
    app('auth')->forgetGuards();

    $res = $this->getJson('/api/v1/public/insight/'.$link['token'])
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertJsonPath('data.totals.posts', 20)
        ->assertJsonPath('data.totals.reach', 49800 + 15100)
        ->assertJsonCount(2, 'data.channels')
        ->assertJsonPath('data.channels.0.network', 'Instagram')
        ->assertJsonMissingPath('data.channels.0.id')
        ->assertJsonPath('data.expires_at', $link['expires_at']);

    $body = (string) $res->getContent();
    foreach ([$owner->email, $owner->ulid, $owner->name, $accounts['instagram']->ulid, $accounts['tiktok']->ulid, 'acc_instagram', $link['id']] as $secret) {
        expect($body)->not->toContain($secret);
    }
});

it('stops after 30 days with 410 expired', function () {
    ['owner' => $owner] = InsightFixture::workspace();
    $link = shareLink($this, $owner);

    $this->travel(30)->days();
    $this->travel(1)->minutes();

    $this->getJson('/api/v1/public/insight/'.$link['token'])->assertStatus(410)->assertJsonPath('expired', true);
    $this->actingAs($owner)->getJson('/api/v1/insight/share')->assertJsonPath('data.0.status', 'expired');
});

it('stops at once when revoked, with the same 404 as an unknown token', function () {
    ['owner' => $owner] = InsightFixture::workspace();
    $link = shareLink($this, $owner);

    $this->actingAs($owner)->deleteJson('/api/v1/insight/share/'.$link['id'])->assertNoContent();
    // Idempotent.
    $this->actingAs($owner)->deleteJson('/api/v1/insight/share/'.$link['id'])->assertNoContent();
    app('auth')->forgetGuards();

    $revoked = $this->getJson('/api/v1/public/insight/'.$link['token'])->assertNotFound()->json();
    $unknown = $this->getJson('/api/v1/public/insight/'.str_repeat('x', 43))->assertNotFound()->json();
    expect($revoked)->toBe($unknown);
});

it('goes dark when the workspace drops to Free', function () {
    ['owner' => $owner] = InsightFixture::workspace();
    $link = shareLink($this, $owner);
    Subscription::query()->where('user_id', $owner->id)->update(['status' => 'expired', 'current_period_end' => now()->subDay(), 'grace_ends_at' => null]);
    Workspace::forget();
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/public/insight/'.$link['token'])->assertNotFound();
});

it('keeps another workspace out: its links are not listed and cannot be revoked', function () {
    ['owner' => $owner] = InsightFixture::workspace();
    $link = shareLink($this, $owner);
    $other = User::factory()->create();
    Subscription::factory()->for($other)->create();

    $this->actingAs($other)->getJson('/api/v1/insight/share')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($other)->deleteJson('/api/v1/insight/share/'.$link['id'])->assertNotFound();
    expect(InsightShareLink::query()->sole()->revoked_at)->toBeNull();
});

it('is Pro / Max: Free gets the 402 on making and listing', function () {
    ['owner' => $owner] = InsightFixture::workspace('free');

    $this->actingAs($owner)->postJson('/api/v1/insight/share', ['from' => '2026-10-01', 'to' => '2026-10-31'])
        ->assertStatus(402)->assertJsonPath('feature', 'insight');
    $this->actingAs($owner)->getJson('/api/v1/insight/share')->assertStatus(402);
    expect(InsightShareLink::count())->toBe(0);
});

it('validates the period and the channels', function (array $body, string $field) {
    ['owner' => $owner] = InsightFixture::workspace();

    $this->actingAs($owner)->postJson('/api/v1/insight/share', $body)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'no dates' => [[], 'from'],
    '93 days' => [['from' => '2026-07-01', 'to' => '2026-10-01'], 'to'],
    'foreign channel' => [['from' => '2026-10-01', 'to' => '2026-10-31', 'channels' => '01JNOTMINENOTMINENOTMINE00'], 'channels'],
]);
