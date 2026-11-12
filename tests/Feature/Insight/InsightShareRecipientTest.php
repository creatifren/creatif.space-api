<?php

use App\Models\InsightShareLink;
use App\Models\User;
use App\Notifications\ApprovalMailFooter;
use App\Notifications\InsightReportShared;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Support\InsightFixture;

/*
 * Insight share link with an optional recipient_email: listed in that
 * person's "Reports shared with you" (GET /me/reports/shared, MCP
 * list_shared_with_me) and emailed once. Without one: a plain public link.
 */

beforeEach(function () {
    Workspace::forget();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse(InsightFixture::NOW, config('app.timezone')));
    InsightFixture::fakeRelay();
    config(['app.frontend_url' => 'https://app.creatif.test']);
    Notification::fake();
});

function isrShare($test, User $owner, ?string $recipient): array
{
    return $test->actingAs($owner)
        ->postJson('/api/v1/insight/share', array_filter(['from' => '2026-10-01', 'to' => '2026-10-31', 'recipient_email' => $recipient]))
        ->assertCreated()
        ->json('data');
}

function isrShared($test, string $email): array
{
    app('auth')->forgetGuards();

    return asApprover($test, $email)->getJson('/api/v1/me/reports/shared')->assertOk()->json('data');
}

it('emails the recipient once, with the link and the approval footer', function () {
    ['owner' => $owner] = InsightFixture::workspace();
    $link = isrShare($this, $owner, '  Rani@Client.test ');

    expect($link['recipient_email'])->toBe('rani@client.test');
    Notification::assertSentOnDemandTimes(InsightReportShared::class, 1);
    Notification::assertSentOnDemand(InsightReportShared::class, function (InsightReportShared $n, array $c, AnonymousNotifiable $to) use ($link) {
        $mail = $n->toMail($to);

        return $to->routes['mail'] === 'rani@client.test'
            && $mail->actionUrl === $link['url']
            && ($mail->viewData['approvalFooter'] ?? false) === true
            && str_contains($mail->subject, 'Kopi Senja shared an Insight report with you');
    });
    expect(ApprovalMailFooter::LINK)->toBe('Approved with Creatif Space →');
});

it('lists the link for the recipient only, with its URL', function () {
    ['owner' => $owner] = InsightFixture::workspace();
    $link = isrShare($this, $owner, 'rani@client.test');

    $rows = isrShared($this, 'rani@client.test');
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'report_id' => $link['id'], 'kind' => 'insight', 'url' => $link['url'],
            'owner' => ['name' => 'Kopi Senja'], 'period' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
        ]);

    expect(isrShared($this, 'someone@else.test'))->toBe([]);
    // The owner does not see their own link as "shared with you".
    expect(isrShared($this, $owner->email))->toBe([]);
});

it('keeps a plain link unlisted and sends nothing; only its hash is stored', function () {
    ['owner' => $owner] = InsightFixture::workspace();
    $link = isrShare($this, $owner, null);

    expect($link['recipient_email'])->toBeNull();
    Notification::assertNothingSent();
    $row = InsightShareLink::query()->sole();
    expect($row->recipient_token)->toBeNull()
        ->and($row->getRawOriginal('token_hash'))->toBe(hash('sha256', $link['token']));
});

it('stores a recipient link’s token encrypted, never in plain text', function () {
    ['owner' => $owner] = InsightFixture::workspace();
    $link = isrShare($this, $owner, 'rani@client.test');

    $raw = (string) InsightShareLink::query()->sole()->getRawOriginal('recipient_token');
    expect($raw)->not->toBe('')->not->toContain($link['token']);
    expect(InsightShareLink::query()->sole()->recipient_token)->toBe($link['token']);
});

it('disappears from the list once revoked or expired', function () {
    ['owner' => $owner] = InsightFixture::workspace();
    $link = isrShare($this, $owner, 'rani@client.test');
    expect(isrShared($this, 'rani@client.test'))->toHaveCount(1);

    app('auth')->forgetGuards();
    $this->actingAs($owner)->deleteJson("/api/v1/insight/share/{$link['id']}")->assertNoContent();
    expect(isrShared($this, 'rani@client.test'))->toBe([]);

    $second = isrShare($this, $owner, 'rani@client.test');
    expect(isrShared($this, 'rani@client.test'))->toHaveCount(1);
    $this->travel(31)->days();
    expect(isrShared($this, 'rani@client.test'))->toBe([]);
    expect($second['id'])->not->toBe($link['id']);
});

it('answers 404 for another workspace’s link, and validates the email', function () {
    ['owner' => $owner] = InsightFixture::workspace();
    $link = isrShare($this, $owner, 'rani@client.test');

    // Another workspace: any other user (InsightFixture::workspace() is one per test).
    $other = User::factory()->create();
    Workspace::forget();
    app('auth')->forgetGuards();
    $this->actingAs($other)->deleteJson("/api/v1/insight/share/{$link['id']}")->assertNotFound();
    expect(InsightShareLink::query()->where('ulid', $link['id'])->value('revoked_at'))->toBeNull();

    $this->actingAs($owner)->postJson('/api/v1/insight/share', ['from' => '2026-10-01', 'to' => '2026-10-31', 'recipient_email' => 'not an email'])
        ->assertUnprocessable()->assertJsonValidationErrors('recipient_email');
});

it('needs a verified email to see it', function () {
    ['owner' => $owner] = InsightFixture::workspace();
    isrShare($this, $owner, 'rani@client.test');
    $rani = User::factory()->create(['email' => 'rani@client.test', 'email_verified_at' => null]);

    app('auth')->forgetGuards();
    $this->actingAs(User::query()->findOrFail($rani->id))->getJson('/api/v1/me/reports/shared')->assertOk()->assertExactJson(['data' => []]);
});
