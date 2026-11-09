<?php

use App\Listeners\SingleLineMailSubject;
use App\Models\SocialPost;
use App\Models\User;
use App\Notifications\SocialPostFailed;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;

/*
 * Resend refuses an email whose subject has a line break, so a multi-line
 * caption (or title, or name) used to lose the whole notification. These
 * send for real through the array mailer, so the subject checked is the
 * one that would reach the transport.
 */

/** @return list<Email> */
function sentEmails(): array
{
    return Mail::mailer('array')->getSymfonyTransport()->messages()
        ->map(fn ($m) => $m->getOriginalMessage())
        ->all();
}

it('sends a failed-post email with a one-line subject for a multi-line caption', function () {
    $user = User::factory()->create(['name' => 'Rani']);
    $post = SocialPost::factory()->for($user)->create([
        'caption' => "Line one\nLine two\r\nLine three\n\n#winter",
        'fail_reason' => 'Instagram refused it.',
    ]);

    Notification::sendNow($user, new SocialPostFailed($post), ['mail']);

    $emails = sentEmails();
    expect($emails)->toHaveCount(1);
    $subject = $emails[0]->getSubject();
    expect($subject)->not->toContain("\n")->not->toContain("\r")
        ->and($subject)->toBe('Post didn’t publish: Line one Line two Line three #winter');
});

it('keeps the in-app caption on one line too', function () {
    $user = User::factory()->create();
    $post = SocialPost::factory()->for($user)->create(['caption' => "A\nB"]);

    expect((new SocialPostFailed($post))->toArray($user)['caption'])->toBe('A B');
});

it('collapses and caps any subject on its way out', function () {
    Mail::raw('body', fn ($m) => $m->to('a@example.test')->subject("Opened:\r\n  Noel\tdeck ".str_repeat('x', 300)));

    $subject = sentEmails()[0]->getSubject();

    expect($subject)->not->toMatch('/[\r\n\t]/')
        ->and($subject)->toStartWith('Opened: Noel deck x')
        ->and(mb_strlen($subject))->toBeLessThanOrEqual(SingleLineMailSubject::MAX + 3);
});

it('leaves an ordinary subject alone', function () {
    expect(SingleLineMailSubject::clean('You’re on Pro'))->toBe('You’re on Pro');
});
