<?php

namespace App\Jobs;

use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\MonthlyInsightReport;
use App\Support\Insight\InsightClients;
use App\Support\Insight\InsightPdfBuilder;
use App\Support\Insight\InsightPeriod;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One owner's monthly Insight email: the one-page PDF for $month, attached.
 * Sent at most once per owner and month, and only while every condition
 * still holds on the day: switched on, an active account, the workspace
 * owner, Insight on the plan, at least one connected channel.
 */
class SendMonthlyInsightReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public int $userId, public string $month) {}

    public function handle(InsightPdfBuilder $builder): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null
            || ! $user->insight_monthly_email
            || $user->status !== UserStatus::Active
            || Workspace::isMember($user)
            || ! PlanQuota::hasFeature($user, 'insight')) {
            return;
        }

        $accounts = InsightClients::accounts($user);
        if ($accounts->isEmpty()) {
            return;
        }

        if ($user->insight_monthly_sent_for === $this->month) {
            return; // Already sent this month (a retry, or a second run).
        }

        $period = InsightPeriod::month($this->month);
        $locale = in_array($user->locale, ['en', 'id'], true) ? $user->locale : 'en';

        $result = $builder->build($user, $accounts, $period, 'onepage', ['locale' => $locale, 'branding' => true]);

        $user->notifyNow(new MonthlyInsightReport(
            pdf: $result['pdf'],
            fileName: InsightClients::fileName(InsightClients::slug($user), $period, 'onepage'),
            periodLabel: $period->label($locale),
            language: $locale,
        ));

        $user->forceFill(['insight_monthly_sent_for' => $this->month])->save();
    }
}
