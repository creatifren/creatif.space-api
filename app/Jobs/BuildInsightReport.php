<?php

namespace App\Jobs;

use App\Models\InsightReport;
use App\Support\Insight\InsightPdfBuilder;
use App\Support\Insight\InsightPeriod;
use App\Support\PlanQuota;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds one Insight PDF: collect (relay feed + our DB) → thumbnails →
 * Blade → Dompdf → storage. queued → building → done | failed.
 */
class BuildInsightReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public InsightReport $report) {}

    public function handle(InsightPdfBuilder $builder): void
    {
        $report = $this->report->fresh();

        if ($report === null || ! in_array($report->status, [InsightReport::STATUS_QUEUED, InsightReport::STATUS_BUILDING], true)) {
            return;
        }

        $report->update(['status' => InsightReport::STATUS_BUILDING]);

        $owner = $report->owner;

        // Fail closed: a workspace that dropped to Free between the request
        // and this build gets no report (GET answers 402 for it anyway).
        if (! PlanQuota::hasFeature($owner, 'insight')) {
            $report->update(['status' => InsightReport::STATUS_FAILED, 'failure_reason' => 'plan_required']);

            return;
        }
        $options = $report->options;
        $period = InsightPeriod::fromDates($report->period_from, $report->period_to, $options['period_kind']);

        $result = $builder->build(
            $owner,
            InsightPdfBuilder::accounts($owner, $options['channel_ids']),
            $period,
            $report->format,
            ['locale' => $report->locale, 'branding' => ! $options['hide_branding'], 'notes' => $options['next_month_notes']],
        );

        $path = 'insight-reports/'.$owner->ulid.'/'.$report->ulid.'.pdf';
        Storage::disk(InsightReport::DISK)->put($path, $result['pdf'], ['ContentType' => 'application/pdf']);

        $report->update([
            'status' => InsightReport::STATUS_DONE,
            'path' => $path,
            'size_bytes' => strlen($result['pdf']),
            'page_count' => $result['pages'],
            'failure_reason' => null,
            'expires_at' => now()->addHours(InsightReport::TTL_HOURS),
        ]);
    }

    /** Out of attempts (an exception, or the worker's timeout). */
    public function failed(?Throwable $e): void
    {
        Log::warning('insight.report_failed', ['report' => $this->report->ulid, 'error' => $e?->getMessage()]);

        $this->report->fresh()?->update([
            'status' => InsightReport::STATUS_FAILED,
            'failure_reason' => 'build_failed',
        ]);
    }
}
