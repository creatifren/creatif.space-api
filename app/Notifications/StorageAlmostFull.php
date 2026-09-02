<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\User;
use App\Support\PlanQuota;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Storage crossed 90% of the plan's ceiling. Not switchable — a full
 * quota means client uploads start bouncing, which is not a preference.
 *
 * Raised opportunistically from the code paths that add bytes; the
 * checkAndSend() guard keeps it to one notification a week, not one per
 * upload.
 */
class StorageAlmostFull extends Notification implements ShouldQueue
{
    use Queueable;

    private const THRESHOLD = 0.9;

    public function __construct(public int $usedBytes, public int $limitBytes) {}

    /**
     * Notify the owner if usage crossed the threshold and we haven't said
     * so in the past week. Call after bytes were added; cheap no-op below
     * the line or on unlimited plans.
     */
    public static function checkAndSend(User $owner): void
    {
        $limit = PlanQuota::storageLimit($owner);

        if ($limit === null) {
            return;
        }

        $used = PlanQuota::storageUsed($owner);

        if ($used < (int) ($limit * self::THRESHOLD)) {
            return;
        }

        // ponytail: dedupe by scanning the bell table — ceiling is one row
        // scan per byte-adding request; move to a cache key if it shows up
        // in profiles.
        $saidRecently = $owner->notifications()
            ->where('data->type', NotificationType::StorageQuota->value)
            ->where('created_at', '>=', now()->subWeek())
            ->exists();

        if ($saidRecently) {
            return;
        }

        $owner->notify(new self($used, $limit));
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationType::StorageQuota);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $pct = (int) floor($this->usedBytes / $this->limitBytes * 100);

        return (new MailMessage)
            ->subject('Your storage is '.$pct.'% full')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('You’ve used '.self::gb($this->usedBytes).' of '.self::gb($this->limitBytes).' — once it’s full, new uploads and client submissions will be refused.')
            ->action('Manage files', config('app.frontend_url').'/files')
            ->line('Deleting files you no longer need frees the space immediately, or upgrade for more room.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::StorageQuota->value,
            'used_bytes' => $this->usedBytes,
            'limit_bytes' => $this->limitBytes,
            'percent' => (int) floor($this->usedBytes / $this->limitBytes * 100),
        ];
    }

    private static function gb(int $bytes): string
    {
        return round($bytes / 1_073_741_824, 1).' GB';
    }
}
