<?php

use App\Jobs\SendContentPlanReminders;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\ApprovalMailFooter;
use App\Notifications\ContentPlanReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/*
 * Content Plan reminders: when a live plan has posts waiting on the
 * approver and the first is scheduled within 24 hours, the approver gets
 * one email (approval footer), unless the link has reminders off.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    $this->travelTo(CarbonImmutable::parse('2026-12-01 03:00:00', 'UTC'));
    Notification::fake();
});

/** A December plan to rani@client.test with one post at $at (UTC) in state $approval. */
function crPlan(string $at = '2026-12-02 02:00:00', string $approval = 'client', array $attrs = []): array
{
    $owner = User::factory()->create(['name' => 'Bro']);
    Subscription::factory()->for($owner)->create();
    $link = SocialApprovalLink::create($attrs + [
        'user_id' => $owner->id, 'title' => 'December', 'approver_email' => 'rani@client.test',
        'scope_from' => '2026-12-01', 'scope_to' => '2026-12-31', 'scope_timezone' => 'Asia/Jakarta',
        'scope_starts_at' => CarbonImmutable::parse('2026-11-30 17:00:00', 'UTC'),
        'scope_ends_at' => CarbonImmutable::parse('2026-12-31 17:00:00', 'UTC'),
    ]);
    $post = SocialPost::factory()->for($owner)->create([
        'provider_post_id' => null, 'status' => 'scheduled', 'approval' => $approval,
        'scheduled_at' => CarbonImmutable::parse($at, 'UTC'), 'caption' => 'Launch day',
    ]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($owner)->create()->id, 'status' => 'pending']);

    return [$owner, $link, $post];
}

function crRun(): void
{
    (new SendContentPlanReminders)->handle();
}

it('emails the approver once when the first waiting post is within 24 hours', function () {
    [, $link] = crPlan('2026-12-02 02:00:00');

    crRun();
    crRun();

    Notification::assertSentOnDemandTimes(ContentPlanReminder::class, 1);
    Notification::assertSentOnDemand(ContentPlanReminder::class, function (ContentPlanReminder $n, array $channels, AnonymousNotifiable $to) use ($link) {
        return $to->routes['mail'] === 'rani@client.test' && $n->link->is($link) && $n->waiting === 1
            && $n->firstAt->eq(CarbonImmutable::parse('2026-12-02 02:00:00', 'UTC'));
    });
    expect($link->fresh()->reminded_at)->not->toBeNull();
});

it('waits until 24 hours before the first waiting post', function () {
    [, $link] = crPlan('2026-12-02 03:01:00');
    crRun();
    Notification::assertNothingSent();

    $this->travelTo(CarbonImmutable::parse('2026-12-01 03:01:00', 'UTC'));
    crRun();
    Notification::assertSentOnDemandTimes(ContentPlanReminder::class, 1);
});

it('counts only undecided posts: approved, changes requested and drafts do not remind', function (string $approval) {
    crPlan('2026-12-01 20:00:00', $approval);
    crRun();
    Notification::assertNothingSent();
})->with(['approved', 'revision']);

it('reminds about editor-held posts on a plan too (they wait on the client)', function () {
    crPlan('2026-12-01 20:00:00', 'awaiting');
    crRun();
    Notification::assertSentOnDemandTimes(ContentPlanReminder::class, 1);
});

it('sends nothing for a link with reminders off, revoked, expired, or a fixed list', function (array $attrs) {
    crPlan('2026-12-01 20:00:00', 'client', $attrs);
    crRun();
    Notification::assertNothingSent();
})->with([
    'opted out' => [['reminders_enabled' => false]],
    'revoked' => [['revoked_at' => '2026-11-30 00:00:00']],
    'expired' => [['expires_at' => '2026-11-30 00:00:00']],
]);

it('leaves fixed-list links alone', function () {
    [$owner, , $post] = crPlan('2026-12-01 20:00:00');
    SocialApprovalLink::query()->delete();
    $list = SocialApprovalLink::create(['user_id' => $owner->id, 'title' => 'List', 'approver_email' => 'rani@client.test']);
    $list->posts()->attach($post->id);
    crRun();
    Notification::assertNothingSent();
});

it('uses the approval footer, the plan link, the time in the plan’s timezone and a signed opt-out', function () {
    [, $link] = crPlan('2026-12-02 02:00:00');
    $mail = (new ContentPlanReminder($link, 2, CarbonImmutable::parse('2026-12-02 02:00:00', 'UTC'), SendContentPlanReminders::optOutUrl($link)))
        ->toMail(new AnonymousNotifiable);

    expect($mail->viewData['approvalFooter'] ?? false)->toBeTrue()
        ->and($mail->subject)->toBe('Reminder: Bro’s posts are waiting for your approval')
        ->and($mail->actionUrl)->toBe($link->url())
        ->and(implode("\n", $mail->introLines))->toContain('2 posts are still waiting')->toContain('Wed 2 Dec, 09:00 (Asia/Jakarta)')
        ->and(implode("\n", $mail->outroLines))->toContain('/approval-reminders/'.$link->ulid.'/off?')->toContain('signature=');
    expect(ApprovalMailFooter::LINK)->toBe('Approved with Creatif Space →');
});

it('lets the approver opt out with the signed link: GET asks, POST turns it off', function () {
    [, $link] = crPlan('2026-12-05 02:00:00');
    $url = SendContentPlanReminders::optOutUrl($link);

    $this->get($url)->assertOk()->assertSee('Turn reminders off');
    expect($link->fresh()->reminders_enabled)->toBeTrue();

    $this->post($url)->assertOk()->assertSee('Reminders are off');
    expect($link->fresh()->reminders_enabled)->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-12-04 03:00:00', 'UTC'));
    crRun();
    Notification::assertNothingSent();
});

it('refuses an unsigned or tampered opt-out link', function () {
    [, $link] = crPlan();
    [, $other] = crPlan();
    $this->get("/api/v1/approval-reminders/{$link->ulid}/off")->assertForbidden();
    $tampered = str_replace($link->ulid, $other->ulid, SendContentPlanReminders::optOutUrl($link));
    $this->post($tampered)->assertForbidden();
    expect($other->fresh()->reminders_enabled)->toBeTrue();
});

it('lets the owner switch reminders per link (PATCH reminders), shown on the link', function () {
    [$owner, $link] = crPlan();
    $this->actingAs($owner)->getJson("/api/v1/social-approval-links/{$link->ulid}")
        ->assertOk()->assertJsonPath('data.link.reminders', true)->assertJsonPath('data.link.reminded_at', null);

    $this->actingAs($owner)->patchJson("/api/v1/social-approval-links/{$link->ulid}", ['reminders' => false])
        ->assertOk()->assertJsonPath('data.link.reminders', false);
    expect($link->fresh()->reminders_enabled)->toBeFalse();

    $this->actingAs($owner)->patchJson("/api/v1/social-approval-links/{$link->ulid}", ['reminders' => 'nope'])
        ->assertUnprocessable()->assertJsonValidationErrors('reminders');
});

it('is scheduled every 15 minutes', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->description, SendContentPlanReminders::class));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('*/15 * * * *');
});
