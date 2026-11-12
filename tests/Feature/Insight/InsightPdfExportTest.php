<?php

use App\Models\Subscription;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Tests\Support\InsightFixture;

/*
 * GET /api/v1/insight/export.pdf (the app's Export menu), POST
 * /insight/export-link + its signed GET (connected apps, MCP
 * get_insight_report_link), and capabilities.insight_export on /me.
 */

beforeEach(function () {
    Workspace::forget();
    $this->travelTo(CarbonImmutable::parse(InsightFixture::NOW, config('app.timezone')));
    InsightFixture::fakeRelay();
});

function pdfPages(string $pdf): int
{
    return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
}

it('downloads the one-page PDF for a range and the chosen channels', function () {
    ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace();

    $res = $this->actingAs($owner)
        ->get('/api/v1/insight/export.pdf?from=2026-10-01&to=2026-10-31&channels='.$accounts['instagram']->ulid.','.$accounts['facebook']->ulid)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="kopi-senja-2026-10-onepage.pdf"')
        ->assertHeader('X-Page-Count', '1');

    expect($res->getContent())->toStartWith('%PDF-')->and(pdfPages($res->getContent()))->toBe(1);
});

it('builds the deck and the detailed report on request, for every channel by default', function (string $format, int $pages) {
    ['owner' => $owner] = InsightFixture::workspace();

    $res = $this->actingAs($owner)->get("/api/v1/insight/export.pdf?from=2026-10-01&to=2026-10-31&format={$format}")
        ->assertOk()
        ->assertHeader('Content-Disposition', "attachment; filename=\"kopi-senja-2026-10-{$format}.pdf\"");

    expect(pdfPages($res->getContent()))->toBe($pages);
})->with([
    'deck' => ['deck', 3 + 5 + 1],
    'details' => ['details', 2 + 5 + 1],
]);

it('refuses Free with the 402 plan_required body', function () {
    ['owner' => $owner] = InsightFixture::workspace('free');

    $this->actingAs($owner)->getJson('/api/v1/insight/export.pdf?from=2026-10-01&to=2026-10-31')
        ->assertStatus(402)->assertJsonPath('reason', 'plan_required')->assertJsonPath('feature', 'insight');
    $this->actingAs($owner)->postJson('/api/v1/insight/export-link', ['from' => '2026-10-01', 'to' => '2026-10-31'])
        ->assertStatus(402)->assertJsonPath('feature', 'insight');
});

it('validates the range, the format and the channels', function (string $query, string $field) {
    ['owner' => $owner] = InsightFixture::workspace();

    $this->actingAs($owner)->getJson('/api/v1/insight/export.pdf?'.$query)
        ->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'no dates' => ['', 'from'],
    '93 days' => ['from=2026-07-01&to=2026-10-01', 'to'],
    'future' => ['from=2026-11-10&to=2026-11-20', 'from'],
    'bad format' => ['from=2026-10-01&to=2026-10-31&format=poster', 'format'],
    'foreign channel' => ['from=2026-10-01&to=2026-10-31&channels=01JNOTMINENOTMINENOTMINE00', 'channels'],
]);

describe('the signed link (MCP get_insight_report_link)', function () {
    it('makes a 15-minute link that downloads without a session', function () {
        ['owner' => $owner] = InsightFixture::workspace();

        $link = $this->actingAs($owner)->postJson('/api/v1/insight/export-link', ['from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'deck'])
            ->assertOk()
            ->assertJsonPath('file_name', 'kopi-senja-2026-10-deck.pdf')
            ->assertJsonPath('expires_at', now()->addMinutes(15)->utc()->toIso8601String())
            ->json('url');

        app('auth')->forgetGuards();
        $this->flushSession();
        $res = $this->get($link)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(pdfPages($res->getContent()))->toBe(9);
    });

    it('stops working when it expires or is altered', function () {
        ['owner' => $owner] = InsightFixture::workspace();
        $link = $this->actingAs($owner)->postJson('/api/v1/insight/export-link', ['from' => '2026-10-01', 'to' => '2026-10-31'])->json('url');
        app('auth')->forgetGuards();

        $this->get(str_replace('format=onepage', 'format=details', $link))->assertForbidden();
        $this->get(str_replace('to=2026-10-31', 'to=2026-10-30', $link))->assertForbidden();

        $this->travel(16)->minutes();
        $this->get($link)->assertForbidden();
    });

    it('fails closed when the workspace dropped to Free after the link was made', function () {
        ['owner' => $owner] = InsightFixture::workspace();
        $link = $this->actingAs($owner)->postJson('/api/v1/insight/export-link', ['from' => '2026-10-01', 'to' => '2026-10-31'])->json('url');
        Subscription::query()->where('user_id', $owner->id)->update(['status' => 'expired', 'current_period_end' => now()->subDay(), 'grace_ends_at' => null]);
        app('auth')->forgetGuards();
        Workspace::forget();

        $this->getJson($link)->assertStatus(402)->assertJsonPath('feature', 'insight');
    });

    it('is open to a read-scoped API key; the download route itself is not', function () {
        ['owner' => $owner] = InsightFixture::workspace();
        $key = $this->actingAs($owner, 'web')
            ->postJson('/api/v1/me/api-keys', ['name' => 'k', 'scopes' => ['read']])
            ->json('data.key');
        app('auth')->forgetGuards();

        bearer($this, $key)->postJson('/api/v1/insight/export-link', ['from' => '2026-10-01', 'to' => '2026-10-31'])
            ->assertOk()->assertJsonStructure(['url', 'expires_at', 'file_name']);
        bearer($this, $key)->get('/api/v1/insight/export.pdf?from=2026-10-01&to=2026-10-31')->assertForbidden();
    });
});

it('tells the app whether to show the Export menu', function (string $plan, bool $shown) {
    ['owner' => $owner] = InsightFixture::workspace($plan, false);

    $this->actingAs($owner->fresh())->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.capabilities.insight_export', $shown);
})->with([
    'pro' => ['pro', true],
    'max' => ['max', true],
    'free' => ['free', false],
]);
