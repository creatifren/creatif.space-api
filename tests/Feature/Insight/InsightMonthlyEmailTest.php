<?php

use App\Jobs\SendMonthlyInsightReport;
use App\Jobs\SendMonthlyInsightReports;
use App\Models\Subscription;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\MonthlyInsightReport;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InsightFixture;

/*
 * The monthly Insight email: opt-in switch (Settings → Insight), the 1st at
 * 09:00 workspace time, last month's one-page PDF to Pro / Max owners.
 */

beforeEach(function () {
    Workspace::forget();
    // 1 Nov 2026, 09:00 in Jakarta: the run for October.
    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', config('app.timezone')));
    InsightFixture::fakeRelay();
});

describe('the switch', function () {
    it('is off by default and turns on and off for a Pro owner', function () {
        ['owner' => $owner] = InsightFixture::workspace('pro', false);
        $owner = $owner->fresh();

        $this->actingAs($owner)->getJson('/api/v1/me/insight-settings')
            ->assertOk()->assertExactJson(['data' => ['monthly_email' => false, 'available' => true]]);

        $this->actingAs($owner)->patchJson('/api/v1/me/insight-settings', ['monthly_email' => true])
            ->assertOk()->assertJsonPath('data.monthly_email', true);
        expect($owner->fresh()->insight_monthly_email)->toBeTrue();

        $this->actingAs($owner)->patchJson('/api/v1/me/insight-settings', ['monthly_email' => false])
            ->assertOk()->assertJsonPath('data.monthly_email', false);
    });

    it('refuses to switch on for Free (402) but always lets it be switched off', function () {
        ['owner' => $owner] = InsightFixture::workspace('free', false);
        $owner = $owner->fresh();

        $this->actingAs($owner)->getJson('/api/v1/me/insight-settings')->assertJsonPath('data.available', false);
        $this->actingAs($owner)->patchJson('/api/v1/me/insight-settings', ['monthly_email' => true])
            ->assertStatus(402)->assertJsonPath('feature', 'insight');
        expect($owner->fresh()->insight_monthly_email)->toBeFalse();

        $owner->forceFill(['insight_monthly_email' => true])->save();
        $this->actingAs($owner)->patchJson('/api/v1/me/insight-settings', ['monthly_email' => false])->assertOk();
    });

    it('is the owner\'s: a teammate gets 403', function () {
        ['owner' => $owner] = InsightFixture::workspace('pro', false);
        Subscription::query()->where('user_id', $owner->id)->update(['seats' => 2]);
        $member = User::factory()->create();
        TeamMember::factory()->active($member)->create(['owner_id' => $owner->id]);
        Workspace::forget();

        $this->actingAs($member->fresh())->patchJson('/api/v1/me/insight-settings', ['monthly_email' => true])->assertForbidden();
    });

    it('validates the body', function () {
        ['owner' => $owner] = InsightFixture::workspace('pro', false);

        $this->actingAs($owner->fresh())->patchJson('/api/v1/me/insight-settings', ['monthly_email' => 'sometimes'])
            ->assertStatus(422)->assertJsonValidationErrors('monthly_email');
    });
});

describe('the send', function () {
    it('queues one job per owner who switched it on, for last month', function () {
        Queue::fake();
        $on = User::factory()->create(['insight_monthly_email' => true]);
        User::factory()->create(['insight_monthly_email' => false]);

        (new SendMonthlyInsightReports)->handle();

        Queue::assertPushed(SendMonthlyInsightReport::class, 1);
        Queue::assertPushed(SendMonthlyInsightReport::class, fn ($job) => $job->userId === $on->id && $job->month === '2026-10');
    });

    it('mails the one-page PDF for October, once', function () {
        Notification::fake();
        ['owner' => $owner] = InsightFixture::workspace('pro');
        $owner->forceFill(['insight_monthly_email' => true])->save();

        dispatch_sync(new SendMonthlyInsightReport($owner->id, '2026-10'));
        dispatch_sync(new SendMonthlyInsightReport($owner->id, '2026-10'));

        Notification::assertSentToTimes($owner, MonthlyInsightReport::class, 1);
        Notification::assertSentTo($owner, MonthlyInsightReport::class, function (MonthlyInsightReport $n) use ($owner) {
            $mail = $n->toMail($owner);

            return $n->fileName === 'kopi-senja-2026-10-onepage.pdf'
                && str_starts_with($n->pdf, '%PDF-')
                && $mail->subject === 'Your Insight report — October 2026'
                && $mail->rawAttachments[0]['name'] === 'kopi-senja-2026-10-onepage.pdf'
                && $mail->rawAttachments[0]['options']['mime'] === 'application/pdf';
        });
        expect($owner->fresh()->insight_monthly_sent_for)->toBe('2026-10');
    });

    it('writes in the owner\'s language', function () {
        Notification::fake();
        ['owner' => $owner] = InsightFixture::workspace('pro');
        $owner->forceFill(['insight_monthly_email' => true, 'locale' => 'id'])->save();

        dispatch_sync(new SendMonthlyInsightReport($owner->id, '2026-10'));

        Notification::assertSentTo($owner, MonthlyInsightReport::class,
            fn (MonthlyInsightReport $n) => $n->toMail($owner)->subject === 'Laporan Insight kamu — Oktober 2026');
    });

    it('sends nothing when switched off, on Free, or without channels', function (string $case) {
        Notification::fake();
        ['owner' => $owner, 'accounts' => $accounts] = InsightFixture::workspace($case === 'free' ? 'free' : 'pro');
        $owner->forceFill(['insight_monthly_email' => $case !== 'off'])->save();
        if ($case === 'no channels') {
            collect($accounts)->each->delete();
        }

        dispatch_sync(new SendMonthlyInsightReport($owner->id, '2026-10'));

        Notification::assertNothingSent();
    })->with(['off', 'free', 'no channels']);

    it('is scheduled for the 1st at 09:00 workspace time', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->description, 'SendMonthlyInsightReports'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('0 9 1 * *')
            ->and($event->timezone)->toBe(config('app.timezone'));
    });
});
