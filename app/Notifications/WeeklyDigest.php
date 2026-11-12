<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Monday 08:00 in the user's timezone: last week in numbers — published,
 * waiting for approval, failed (with reasons and links). Email only; the
 * weekly_digest setting turns it off (SendWeeklyDigests checks it too).
 */
class WeeklyDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{week: array{from: string, to: string, timezone: string}, published: int, waiting: int, failed: list<array{post_id: string, caption: string, reason: string, url: string}>, failed_total: int}  $digest
     */
    public function __construct(public array $digest) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $notifiable->wants(NotificationType::WeeklyDigest, 'email') ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->digest;
        $mail = (new MailMessage)
            ->subject("Your week on creatif.space: {$d['published']} published".($d['failed_total'] > 0 ? ", {$d['failed_total']} failed" : ''))
            ->greeting('Hi '.$notifiable->name.',')
            ->line("Last week ({$d['week']['from']} to {$d['week']['to']}):")
            ->line("• Published: {$d['published']} ".($d['published'] === 1 ? 'post' : 'posts'))
            ->line("• Waiting for approval now: {$d['waiting']}")
            ->line("• Failed: {$d['failed_total']}");

        foreach ($d['failed'] as $f) {
            $mail->line("“{$f['caption']}”: {$f['reason']} [Open]({$f['url']})");
        }
        if ($d['failed_total'] > count($d['failed'])) {
            $mail->line('…and '.($d['failed_total'] - count($d['failed'])).' more.');
        }

        return $mail
            ->action('Open social posts', config('app.frontend_url').'/social')
            ->line('You can turn this email off in Settings → Notifications.');
    }
}
