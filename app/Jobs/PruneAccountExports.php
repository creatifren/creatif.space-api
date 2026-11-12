<?php

namespace App\Jobs;

use App\Models\AccountExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * An account export is a full copy of a workspace: its ZIP is deleted once
 * the 24-hour link has expired. The row stays as the receipt.
 */
class PruneAccountExports implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        AccountExport::query()
            ->whereNotNull('path')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->each(function (AccountExport $export): void {
                Storage::disk(AccountExport::DISK)->delete((string) $export->path);
                $export->update(['path' => null]);
            });
    }
}
