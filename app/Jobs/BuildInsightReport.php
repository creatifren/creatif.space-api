<?php

namespace App\Jobs;

use App\Models\InsightReport;
use App\Models\SocialAccount;
use App\Support\Insight\InsightClients;
use App\Support\Insight\InsightCollector;
use App\Support\Insight\InsightImages;
use App\Support\Insight\InsightPeriod;
use App\Support\Insight\InsightRenderer;
use App\Support\Insight\InsightView;
use App\Support\Workspace;
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

    public function handle(InsightCollector $collector, InsightRenderer $renderer, InsightImages $images): void
    {
        $report = $this->report->fresh();

        if ($report === null || ! in_array($report->status, [InsightReport::STATUS_QUEUED, InsightReport::STATUS_BUILDING], true)) {
            return;
        }

        $report->update(['status' => InsightReport::STATUS_BUILDING]);

        $owner = $report->owner;
        $options = $report->options;
        $period = InsightPeriod::fromDates($report->period_from, $report->period_to, $options['period_kind']);

        // The channels asked for, in the order asked, still in the workspace.
        $accounts = SocialAccount::query()
            ->whereIn('user_id', Workspace::memberIds($owner))
            ->whereIn('ulid', $options['channel_ids'])
            ->get()
            ->sortBy(fn (SocialAccount $a) => array_search($a->ulid, $options['channel_ids'], true))
            ->values();

        $data = $collector->collect($owner, $accounts, $period);

        $thumbnails = [];
        foreach ($data['thumbnail_sources'] as $key => $candidates) {
            $thumbnails[$key] = $images->firstImage($candidates);
        }

        $view = InsightView::build($data, [
            'client_name' => InsightClients::name($owner),
            'locale' => $report->locale,
            // The API holds no workspace logo yet, and an avatar is not a
            // logo: the slot stays empty (hidden) until one exists, whatever
            // show_logo says.
            'logo' => null,
            'branding' => ! $options['hide_branding'],
            'notes' => $options['next_month_notes'],
            'generated_at' => now(),
            'thumbnails' => $thumbnails,
        ]);

        $result = $renderer->render($report->format, $view);

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
