<?php

namespace App\Jobs;

use App\Enums\DriveAccountStatus;
use App\Models\DriveFile;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The periodic Drive re-check — the Fase 2 leftover.
 *
 * Everything this needs already existed: SyncDriveFile detects a changed
 * md5 and calls ApprovalVoider, and it stamps access_lost_at when Drive
 * answers 401/403/404. What was missing is that nothing ever ran it again.
 * SyncDriveFile is dispatched exactly once, when a file is first picked, so
 * two shipped promises were quietly inert:
 *
 *   1. "Approvals auto-cancel when a file version changes." Swap a photo in
 *      Drive after a client signed it off and the approval kept standing —
 *      the record said they approved a file that is no longer the file they
 *      saw. That is the dispute the approval feature exists to prevent.
 *   2. Home's "Needs Attention" strip reads access_lost_at, and nothing
 *      refreshed it, so lost files never surfaced.
 *
 * So this job is a *selector*, not a second implementation: it decides which
 * files are due and hands each to SyncDriveFile. The rule keeps one home,
 * the same reason ApprovalVoider exists.
 */
class RecheckDriveFiles implements ShouldQueue
{
    use Queueable;

    /**
     * Files synced more recently than this are left alone. With a daily
     * schedule the window only matters after a manual run or a retry — it
     * stops a second pass from re-asking Drive about the same file hours
     * later for nothing.
     */
    public const STALE_HOURS = 12;

    /**
     * Files per run, across both passes. Drive bills per request, so an
     * account with a large library is swept over several nights rather than
     * in one burst; stalest-first means the queue rotates through everything
     * instead of re-checking the same head of the list.
     */
    public const MAX_FILES = 500;

    public int $tries = 1;

    public function handle(): void
    {
        $cutoff = now()->subHours(self::STALE_HOURS);

        /*
         * Files that sit in a Space go first. They are the ones carrying
         * approvals to void and the ones a client opens, so if the budget
         * runs out it should run out on files nobody is looking at.
         */
        $spent = $this->sweep($cutoff, self::MAX_FILES, inSpace: true);

        if ($spent < self::MAX_FILES) {
            $this->sweep($cutoff, self::MAX_FILES - $spent, inSpace: false);
        }
    }

    /**
     * Dispatch a re-sync for the stalest due files, and report how many.
     */
    private function sweep(CarbonInterface $cutoff, int $limit, bool $inSpace): int
    {
        $files = DriveFile::query()
            /*
             * Folders are skipped. SyncDriveFile fans a folder out into a
             * full SyncDriveFolder traversal, which is discovery of new
             * files rather than a re-check of known ones — a bigger question
             * with its own cost, and it would blow this budget in one row.
             */
            ->where('is_folder', false)
            ->whereNull('trashed_at')
            /*
             * Only accounts we can actually reach. A revoked token makes
             * accessToken() throw, so sweeping those would fail once per
             * file to re-learn something the account status already says.
             */
            ->whereHas(
                'account',
                fn ($q) => $q->where('status', DriveAccountStatus::Connected),
            )
            ->where(fn ($q) => $q
                ->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<', $cutoff))
            ->when(
                $inSpace,
                fn ($q) => $q->whereHas('spaceItems'),
                fn ($q) => $q->whereDoesntHave('spaceItems'),
            )
            // NULL sorts first on both MySQL and SQLite: never-synced is the
            // stalest thing there is, so that ordering is the one we want.
            ->orderBy('last_synced_at')
            ->with('account')
            ->limit($limit)
            ->get();

        foreach ($files as $file) {
            SyncDriveFile::dispatch($file->account, $file->provider_file_id);
        }

        return $files->count();
    }
}
