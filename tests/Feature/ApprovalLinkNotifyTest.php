<?php

use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\ApprovalLinkShared;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/*
 * POST /api/v1/social-approval-links with notify_approver: true emails the
 * new link to its approver (MCP create_content_plan, after the user
 * confirmed). Without it nothing is emailed: the URL is shared by hand.
 */

beforeEach(fn () => Notification::fake());

function nlOwner(): User
{
    $owner = User::factory()->create(['name' => 'Rizal']);
    Subscription::factory()->for($owner)->create();

    return $owner;
}

it('emails a new Content Plan to its approver when notify_approver is true', function () {
    $owner = nlOwner();
    $account = SocialAccount::factory()->for($owner)->create(['platform' => 'instagram']);

    $data = $this->actingAs($owner)->postJson('/api/v1/social-approval-links', [
        'date_from' => '2026-12-01',
        'date_to' => '2026-12-31',
        'timezone' => 'Asia/Jakarta',
        'channel_ids' => [$account->ulid],
        'approver_email' => 'Rani@Client.test',
        'title' => 'December',
        'notify_approver' => true,
    ])->assertCreated()->assertJsonPath('data.approver_notified', true)->json('data');

    $link = SocialApprovalLink::query()->where('user_id', $owner->id)->sole();
    Notification::assertSentOnDemand(ApprovalLinkShared::class, function (ApprovalLinkShared $n, array $channels, AnonymousNotifiable $to) use ($link) {
        return $to->routes['mail'] === 'rani@client.test' && $n->link->is($link) && $channels === ['mail'];
    });
    Notification::assertSentOnDemandTimes(ApprovalLinkShared::class, 1);

    $mail = (new ApprovalLinkShared($link))->toMail(new AnonymousNotifiable);
    expect($mail->subject)->toBe('Rizal asks you to approve posts')
        ->and($mail->introLines[0])->toBe('Rizal has shared “December” with you: please review the posts planned from 1 Dec 2026 to 31 Dec 2026.')
        ->and($mail->actionUrl)->toBe($link->url())
        ->and($mail->actionUrl)->toBe($data['url'])
        ->and($mail->viewData['approvalFooter'] ?? false)->toBeTrue();
});

it('sends nothing without notify_approver, and says so', function () {
    $owner = nlOwner();

    $this->actingAs($owner)->postJson('/api/v1/social-approval-links', [
        'date_from' => '2026-12-01',
        'date_to' => '2026-12-07',
        'approver_email' => 'rani@client.test',
    ])->assertCreated()->assertJsonPath('data.approver_notified', false);

    $this->actingAs($owner)->postJson('/api/v1/social-approval-links', [
        'date_from' => '2026-12-01',
        'date_to' => '2026-12-07',
        'approver_email' => 'rani@client.test',
        'notify_approver' => false,
    ])->assertCreated()->assertJsonPath('data.approver_notified', false);

    Notification::assertNothingSent();
});

it('emails a fixed-list link too when asked', function () {
    $owner = nlOwner();
    $post = SocialPost::factory()->for($owner)->create(['status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => now()->addDays(2)]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($owner)->create()->id, 'status' => 'pending']);

    $this->actingAs($owner)->postJson('/api/v1/social-approval-links', [
        'post_ids' => [$post->ulid],
        'approver_email' => 'rani@client.test',
        'notify_approver' => true,
    ])->assertCreated()->assertJsonPath('data.approver_notified', true);

    $link = SocialApprovalLink::query()->where('user_id', $owner->id)->sole();
    $mail = (new ApprovalLinkShared($link))->toMail(new AnonymousNotifiable);
    expect($mail->introLines[0])->toBe('Rizal has shared with you: please review some posts.');
    Notification::assertSentOnDemandTimes(ApprovalLinkShared::class, 1);
});

it('refuses a non-boolean notify_approver and creates nothing', function () {
    $owner = nlOwner();

    $this->actingAs($owner)->postJson('/api/v1/social-approval-links', [
        'date_from' => '2026-12-01',
        'date_to' => '2026-12-07',
        'approver_email' => 'rani@client.test',
        'notify_approver' => 'yes please',
    ])->assertUnprocessable()->assertJsonValidationErrors('notify_approver');

    expect(SocialApprovalLink::query()->count())->toBe(0);
    Notification::assertNothingSent();
});
