<?php

namespace App\Jobs;

use App\Enums\UserStatus;
use App\Http\Controllers\Api\V1\ClientReportController;
use App\Models\ClientLabel;
use App\Models\ClientReport;
use App\Models\User;
use App\Support\ClientReportBuilder;
use App\Support\PlanQuota;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hourly: every owner whose Report emails are on and whose send day + hour
 * it is now (in their timezone) gets last month's report built and emailed
 * to each client with an approver and the report email on. A client is
 * never sent the same month twice.
 */
class SendClientReports implements ShouldQueue
{
    use Queueable;

    public function handle(ClientReportBuilder $builder): void
    {
        User::query()
            ->where('client_reports_enabled', true)
            ->where('status', UserStatus::Active->value)
            ->chunkById(200, function ($users) use ($builder): void {
                foreach ($users as $user) {
                    $now = CarbonImmutable::now($user->timezone ?: config('app.timezone'));
                    if ($now->day !== (int) $user->report_send_day || $now->format('H:00') !== ($user->report_send_time ?? '09:00')) {
                        continue;
                    }
                    if (! PlanQuota::hasFeature($user, 'insight')) {
                        continue;
                    }
                    $month = $now->subMonthNoOverflow()->format('Y-m');
                    $user->clientLabels()->where('is_demo', false)->where('report_email', true)->whereNotNull('approver_email')->each(function (ClientLabel $label) use ($builder, $user, $month): void {
                        $report = ClientReport::query()->firstOrCreate(['client_label_id' => $label->id, 'month' => $month], ['user_id' => $user->id]);
                        if ($report->sent_at !== null || $report->revoked_at !== null) {
                            return;
                        }
                        try {
                            ClientReportController::deliver($builder, $report->load(['user', 'clientLabel']));
                        } catch (Throwable $e) {
                            Log::warning('client_report.send_failed', ['report' => $report->ulid, 'error' => $e->getMessage()]);
                        }
                    });
                }
            });
    }
}
