<?php

use App\Models\InsightShareLink;
use App\Models\User;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Tests\Support\InsightFixture;
use Tests\TestCase;

/*
 * Connected apps (MCP create_insight_share_link / revoke_insight_share_link)
 * reach the share routes with the `approve` permission, like approval links.
 */

beforeEach(function () {
    Workspace::forget();
    $this->travelTo(CarbonImmutable::parse(InsightFixture::NOW, config('app.timezone')));
    InsightFixture::fakeRelay();
});

function shareKey(TestCase $test, User $owner, array $scopes): string
{
    $key = $test->actingAs($owner, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'share', 'scopes' => $scopes])
        ->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

it('lets an approve-scoped key make and revoke a share link', function () {
    ['owner' => $owner] = InsightFixture::workspace();
    $key = shareKey($this, $owner, ['read', 'approve']);

    $id = bearer($this, $key)->postJson('/api/v1/insight/share', ['from' => '2026-10-01', 'to' => '2026-10-31'])
        ->assertCreated()->json('data.id');
    bearer($this, $key)->deleteJson("/api/v1/insight/share/{$id}")->assertNoContent();

    expect(InsightShareLink::query()->sole()->revoked_at)->not->toBeNull();
});

it('keeps a read-only key from sharing', function () {
    ['owner' => $owner] = InsightFixture::workspace();
    $key = shareKey($this, $owner, ['read']);

    bearer($this, $key)->postJson('/api/v1/insight/share', ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertForbidden();
    expect(InsightShareLink::count())->toBe(0);
});
