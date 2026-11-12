<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The 1st of the month, 09:00 (app timezone, the workspace's time): one
 * SendMonthlyInsightReport per owner who switched the email on. Each runs
 * on its own, so one failing workspace never holds up the rest; plan,
 * channels and "already sent" are checked there.
 */
class SendMonthlyInsightReports implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $month = now(config('app.timezone'))->subMonthNoOverflow()->format('Y-m');

        User::query()
            ->where('insight_monthly_email', true)
            ->select('id')
            ->chunkById(200, function ($users) use ($month): void {
                foreach ($users as $user) {
                    SendMonthlyInsightReport::dispatch($user->id, $month);
                }
            });
    }
}
