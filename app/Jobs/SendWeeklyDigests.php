<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\WeeklyDigest as WeeklyDigestMail;
use App\Services\Analytics;
use App\Support\WeeklyDigest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hourly: whoever it is Monday 08:xx for right now (in their timezone,
 * WeeklyDigest::timezone) gets last week's digest — once per week
 * (weekly_digest_sent_on). Skipped: the weekly_digest setting off,
 * internal accounts, accounts that are not active, and a week with
 * nothing to say.
 */
class SendWeeklyDigests implements ShouldQueue
{
    use Queueable;

    public const HOUR = 8;

    public int $tries = 1;

    public function handle(): void
    {
        $now = CarbonImmutable::now();

        User::query()
            ->where('status', UserStatus::Active)
            ->with('notificationPreferences')
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($now): void {
                foreach ($users as $user) {
                    try {
                        $this->maybeSend($user, $now);
                    } catch (Throwable $e) {
                        Log::warning('weekly_digest.failed', ['user' => $user->id, 'error' => $e->getMessage()]);
                    }
                }
            });
    }

    private function maybeSend(User $user, CarbonImmutable $now): void
    {
        $local = $now->setTimezone(WeeklyDigest::timezone($user));
        if (! $local->isMonday() || $local->hour !== self::HOUR) {
            return;
        }

        $monday = $local->toDateString();
        if (($user->getAttributes()['weekly_digest_sent_on'] ?? null) === $monday
            || Analytics::isInternal($user)
            || ! $user->wants(NotificationType::WeeklyDigest, 'email')) {
            return;
        }

        $digest = WeeklyDigest::for($user, $local->startOfDay());

        // Marked first: a failing mailer must not send it again every hour.
        $user->forceFill(['weekly_digest_sent_on' => $monday])->save();

        if (! WeeklyDigest::isEmpty($digest)) {
            $user->notify(new WeeklyDigestMail($digest));
        }
    }
}
