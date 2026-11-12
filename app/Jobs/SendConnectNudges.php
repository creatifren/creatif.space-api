<?php

namespace App\Jobs;

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Onboarding;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hourly: the day-2 "connect your first channel" email for accounts that
 * signed up 24–72 hours ago and still have no channel (Onboarding::nudge
 * decides and sends; once ever per account).
 */
class SendConnectNudges implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        $now = CarbonImmutable::now();

        User::query()
            ->where('status', UserStatus::Active)
            ->whereNull('connect_nudge_sent_at')
            ->whereNotNull('welcome_sent_at')
            ->whereBetween('created_at', [
                $now->subHours(Onboarding::NUDGE_UNTIL_HOURS),
                $now->subHours(Onboarding::NUDGE_AFTER_HOURS),
            ])
            ->whereDoesntHave('socialAccounts')
            ->with('notificationPreferences')
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($now): void {
                foreach ($users as $user) {
                    try {
                        Onboarding::nudge($user, $now);
                    } catch (Throwable $e) {
                        Log::warning('connect_nudge.failed', ['user' => $user->id, 'error' => $e->getMessage()]);
                    }
                }
            });
    }
}
