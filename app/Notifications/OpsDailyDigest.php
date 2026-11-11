<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The morning ops mail to the team inbox: failed queue jobs and failed
 * publishes in the last 24 hours. Counts, job classes and failure codes
 * only — no user, caption, email or token. Sent only when something failed.
 *
 * Not queued: it reports on the queue, so it must not wait in it.
 */
class OpsDailyDigest extends Notification
{
    use Queueable;

    /**
     * @param  array<string, int>  $jobsByClass
     * @param  array<string, int>  $publishesByCode
     */
    public function __construct(
        public int $failedJobs,
        public array $jobsByClass,
        public int $failedPublishes,
        public array $publishesByCode,
        public string $since,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Creatif Space ops: {$this->failedJobs} failed jobs, {$this->failedPublishes} failed publishes (24h)")
            ->greeting('Last 24 hours')
            ->line("Since {$this->since} (UTC).")
            ->line("Failed queue jobs: {$this->failedJobs}");

        foreach ($this->jobsByClass as $class => $n) {
            $mail->line("• {$class}: {$n}");
        }

        $mail->line("Failed publishes (destinations): {$this->failedPublishes}");
        foreach ($this->publishesByCode as $code => $n) {
            $mail->line("• {$code}: {$n}");
        }

        return $mail->line('Details: PostHog error tracking and `php artisan queue:failed`.');
    }
}
