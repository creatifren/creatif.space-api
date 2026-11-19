<?php

use App\Jobs\SendClientReports;
use App\Models\ClientLabel;
use App\Models\ClientReport;
use App\Models\SocialAccount;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\ClientReportReady;
use App\Support\ClientReportBuilder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/* v7 monthly client reports + Preferences › Report emails. */

beforeEach(function () {
    Notification::fake();
    // No AI and no network feeds in tests: the plain summary and empty numbers.
    config(['services.ai.key' => null]);
    Http::fake(['*' => Http::response(['data' => []])]);
});

function reportOwner(): User
{
    $user = User::factory()->create(['timezone' => 'Asia/Makassar']);
    Subscription::factory()->for($user)->create();

    // Re-read: strict models need the column defaults (Report emails fields).
    return User::query()->findOrFail($user->id);
}

it('keeps Report emails off until turned on, and saves the schedule and per-client switch', function () {
    $user = reportOwner();
    $label = ClientLabel::create(['user_id' => $user->id, 'name' => 'Kopi Senja', 'approver_email' => 'dewi@example.test']);

    $this->actingAs($user)->getJson('/api/v1/me/report-settings')->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.send_day', 1)
        ->assertJsonPath('data.send_time', '09:00');

    $this->actingAs($user)->patchJson('/api/v1/me/report-settings', [
        'enabled' => true, 'send_day' => 2, 'send_time' => '08:00', 'from_name' => 'Rizal · Creatif Space',
        'clients' => [['id' => $label->ulid, 'report_email' => false]],
    ])->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.send_day', 2)
        ->assertJsonPath('data.clients.0.report_email', false);

    $this->actingAs($user)->patchJson('/api/v1/me/report-settings', ['send_day' => 31])->assertStatus(422);
    $this->actingAs($user)->patchJson('/api/v1/me/report-settings', ['send_time' => '09:30'])->assertStatus(422);
});

it('builds a client report, Link only by default, with an editable summary and a public page', function () {
    $user = reportOwner();
    $label = ClientLabel::create(['user_id' => $user->id, 'name' => 'Hotel Ombak', 'approver_email' => 'made@example.test']);
    $label->accounts()->attach(SocialAccount::factory()->for($user)->create()->id);

    $report = $this->actingAs($user)->postJson('/api/v1/client-reports', ['client_id' => $label->ulid, 'month' => now()->subMonth()->format('Y-m')])
        ->assertCreated()
        ->assertJsonPath('data.client.name', 'Hotel Ombak')
        ->assertJsonPath('data.visibility', 'link')
        ->assertJsonCount(1, 'data.accounts')
        ->json('data');
    expect($report['summary'])->toBeString()->not->toBe('');

    $this->actingAs($user)->patchJson("/api/v1/client-reports/{$report['id']}", ['summary' => 'A strong month.'])
        ->assertOk()->assertJsonPath('data.summary', 'A strong month.')->assertJsonPath('data.summary_edited', true);

    $token = basename($report['url']);
    expect($token)->toStartWith('cr_');
    $this->app['auth']->forgetGuards();
    $this->getJson("/api/v1/public/client-reports/{$token}")->assertOk()
        ->assertJsonPath('data.summary', 'A strong month.')
        ->assertJsonMissingPath('data.sent_to');
    expect(ClientReport::first()->open_count)->toBe(1);

    $this->actingAs($user)->deleteJson("/api/v1/client-reports/{$report['id']}/link")->assertNoContent();
    $this->app['auth']->forgetGuards();
    $this->getJson("/api/v1/public/client-reports/{$token}")->assertStatus(409)->assertJsonPath('state', 'revoked');
});

it('emails last month to each client with an approver on the owner\'s day and hour, once', function () {
    $user = reportOwner();
    $user->forceFill(['client_reports_enabled' => true, 'report_send_day' => 1, 'report_send_time' => '09:00'])->save();
    $on = ClientLabel::create(['user_id' => $user->id, 'name' => 'Kopi Senja', 'approver_email' => 'dewi@example.test']);
    ClientLabel::create(['user_id' => $user->id, 'name' => 'No approver']);
    ClientLabel::create(['user_id' => $user->id, 'name' => 'Off', 'approver_email' => 'off@example.test', 'report_email' => false]);

    $this->travelTo(now('Asia/Makassar')->startOfMonth()->setTime(8, 10));
    (new SendClientReports)->handle(app(ClientReportBuilder::class));
    Notification::assertNothingSent();

    $this->travelTo(now('Asia/Makassar')->startOfMonth()->setTime(9, 2));
    (new SendClientReports)->handle(app(ClientReportBuilder::class));
    (new SendClientReports)->handle(app(ClientReportBuilder::class));

    Notification::assertSentOnDemandTimes(ClientReportReady::class, 1);
    expect(ClientReport::query()->where('client_label_id', $on->id)->value('sent_to'))->toBe('dewi@example.test');
});
