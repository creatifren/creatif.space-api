<?php

use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\ApprovalMailFooter;
use App\Notifications\ApprovalReminder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
 * "Send a reminder" (POST /api/v1/social-approval-links/{id}/remind): the
 * approver gets the link once more by email, with the approval footer, at
 * most once every 12 hours per link.
 */

/** A paid owner with one post waiting on a link addressed to rani@client.test. */
function rmLink(?string $approverEmail = 'rani@client.test', string $approval = 'client'): array
{
    $owner = User::factory()->create(['name' => 'Rizal']);
    Subscription::factory()->for($owner)->create();

    $post = SocialPost::factory()->for($owner)->create([
        'provider_post_id' => null, 'status' => 'scheduled', 'approval' => $approval, 'scheduled_at' => now()->addDays(2),
    ]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($owner)->create()->id, 'status' => 'pending']);

    $link = SocialApprovalLink::create(['user_id' => $owner->id, 'title' => 'November', 'approver_email' => $approverEmail]);
    $link->posts()->attach($post->id);

    return [$owner, $link];
}

beforeEach(fn () => Notification::fake());

it('emails the approver once and says when the next reminder may go', function () {
    Carbon::setTestNow('2026-11-12 09:00:00');
    [$owner, $link] = rmLink();

    $this->actingAs($owner)->postJson("/api/v1/social-approval-links/{$link->ulid}/remind")
        ->assertOk()
        ->assertJsonPath('data.link_id', $link->ulid)
        ->assertJsonPath('data.waiting', 1)
        ->assertJsonPath('data.reminded_at', now()->toIso8601String())
        ->assertJsonPath('data.next_reminder_at', now()->addHours(12)->toIso8601String());

    Notification::assertSentOnDemand(ApprovalReminder::class, function (ApprovalReminder $n, array $channels, AnonymousNotifiable $to) use ($link) {
        return $to->routes['mail'] === 'rani@client.test' && $n->link->is($link) && $n->waiting === 1;
    });
    Notification::assertSentOnDemandTimes(ApprovalReminder::class, 1);
    expect($link->fresh()->last_reminded_at->toIso8601String())->toBe(now()->toIso8601String());

    // The link itself now carries it, for "Send a reminder" in the app.
    $this->actingAs($owner)->getJson("/api/v1/social-approval-links/{$link->ulid}")
        ->assertJsonPath('data.link.last_reminded_at', now()->toIso8601String())
        ->assertJsonPath('data.link.next_reminder_at', now()->addHours(12)->toIso8601String());
});

it('allows one reminder per 12 hours: 429 reminder_too_soon inside, OK after', function () {
    Carbon::setTestNow('2026-11-12 09:00:00');
    [$owner, $link] = rmLink();
    $this->actingAs($owner)->postJson("/api/v1/social-approval-links/{$link->ulid}/remind")->assertOk();

    Carbon::setTestNow('2026-11-12 20:59:00');
    $this->actingAs($owner)->postJson("/api/v1/social-approval-links/{$link->ulid}/remind")
        ->assertStatus(429)
        ->assertJsonPath('code', 'reminder_too_soon')
        ->assertJsonPath('next_reminder_at', Carbon::parse('2026-11-12 21:00:00')->toIso8601String())
        ->assertHeader('Retry-After', '60');
    Notification::assertSentOnDemandTimes(ApprovalReminder::class, 1);

    Carbon::setTestNow('2026-11-12 21:00:00');
    $this->actingAs($owner)->postJson("/api/v1/social-approval-links/{$link->ulid}/remind")->assertOk();
    Notification::assertSentOnDemandTimes(ApprovalReminder::class, 2);
});

it('refuses when there is nothing to remind about or no one to remind', function () {
    [$owner, $answered] = rmLink(approval: 'approved');
    $this->actingAs($owner)->postJson("/api/v1/social-approval-links/{$answered->ulid}/remind")
        ->assertStatus(422)->assertJsonPath('code', 'nothing_waiting');

    [$owner2, $old] = rmLink(approverEmail: null);
    $this->actingAs($owner2)->postJson("/api/v1/social-approval-links/{$old->ulid}/remind")
        ->assertStatus(422)->assertJsonPath('code', 'no_approver');

    [$owner3, $revoked] = rmLink();
    $revoked->update(['revoked_at' => now()]);
    $this->actingAs($owner3)->postJson("/api/v1/social-approval-links/{$revoked->ulid}/remind")
        ->assertStatus(422)->assertJsonPath('code', 'link_not_live');

    [$owner4, $expired] = rmLink();
    $expired->update(['expires_at' => now()->subMinute()]);
    $this->actingAs($owner4)->postJson("/api/v1/social-approval-links/{$expired->ulid}/remind")
        ->assertStatus(422)->assertJsonPath('code', 'link_not_live');

    Notification::assertNothingSent();
    expect($answered->fresh()->last_reminded_at)->toBeNull();
});

it('is the owner’s workspace only: 404 for anyone else', function () {
    [, $link] = rmLink();
    $this->actingAs(User::factory()->create())->postJson("/api/v1/social-approval-links/{$link->ulid}/remind")->assertNotFound();
    Notification::assertNothingSent();
});

it('sends a reminder email with the approval footer and the same link', function () {
    config(['app.name' => 'Creatif Space', 'app.marketing_url' => 'https://creatif.space']);
    [, $link] = rmLink();
    $mail = (new ApprovalReminder($link, 2))->toMail(new AnonymousNotifiable);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Reminder: Rizal is waiting for your approval')
        ->and($mail->actionUrl)->toBe($link->url())
        ->and($mail->viewData['approvalFooter'] ?? false)->toBeTrue()
        ->and($html)->toContain('class="approval-footer"')
        ->and(html_entity_decode($html, ENT_QUOTES | ENT_HTML5))->toContain(ApprovalMailFooter::URL)
        ->and(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5))->toContain('Rizal is still waiting for your answer on 2 posts in “November”.');
});
