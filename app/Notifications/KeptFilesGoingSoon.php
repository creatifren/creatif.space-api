<?php

namespace App\Notifications;

use App\Exceptions\PlanFeatureRequired;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * After a downgrade to Free, kept files stay 30 days (/terms). This is the
 * warning 7 days and 1 day before the first of them goes: how many, how
 * much, when, and the two ways to keep them — upgrade, or download.
 * Always mailed: it is about losing files.
 */
class KeptFilesGoingSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $files,
        public int $bytes,
        public CarbonImmutable $deletesAt,
        public int $daysBefore,
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
        $when = $this->daysBefore === 1 ? 'tomorrow' : "in {$this->daysBefore} days";
        $what = $this->files === 1 ? '1 kept file' : "{$this->files} kept files";

        return (new MailMessage)
            ->subject("{$what} will be deleted {$when}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("Your account moved to the Free plan, so files you marked Keep are kept for 30 days and then deleted. {$what} (".self::size($this->bytes).') will be deleted on '.$this->deletesAt->format('j M Y').'.')
            ->line('To keep them: upgrade to Pro, or download them before then.')
            ->action('Upgrade to keep them', PlanFeatureRequired::billingUrl())
            ->line('Download them from Media: '.rtrim((string) config('app.frontend_url'), '/').'/files');
    }

    public static function size(int $bytes): string
    {
        return $bytes >= 1024 ** 3
            ? round($bytes / 1024 ** 3, 1).' GB'
            : max(1, (int) round($bytes / 1024 ** 2)).' MB';
    }
}
