<?php

namespace App\Jobs;

use App\Models\InsightReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Insight PDFs live InsightReport::TTL_HOURS. The file goes; the row stays
 * as the record that the report was made (path cleared, status kept).
 */
class PruneInsightReports implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        InsightReport::query()
            ->whereNotNull('path')
            ->where('expires_at', '<', now())
            ->chunkById(200, function ($reports): void {
                foreach ($reports as $report) {
                    try {
                        Storage::disk(InsightReport::DISK)->delete((string) $report->path);
                    } catch (Throwable) {
                        continue; // Try again tomorrow.
                    }
                    $report->forceFill(['path' => null])->save();
                }
            });
    }
}
