<?php

namespace App\Support;

use App\Enums\ApprovalCancelReason;
use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\File;
use App\Models\Space;

/**
 * Two ways a decision stops being true: the owner resets it, or the file it
 * was made about is replaced.
 *
 * The second used to be impossible — files were immutable once ready, so an
 * approval could only be lost by deleting the Space item it hung from. File
 * versions changed that: new bytes under a standing approval would mean a
 * client's "approved" silently covering work they never saw. So a new
 * version voids the approvals on every item showing that file.
 */
class ApprovalVoider
{
    /**
     * The owner's "Reset to Pending": every decision on the Space goes back
     * to untouched. No notification — she is the one who asked for it.
     */
    public static function resetSpace(Space $space): int
    {
        $approvals = Approval::query()
            ->whereIn('space_item_id', $space->items()->select('id'))
            ->whereIn('status', [ApprovalStatus::Approved, ApprovalStatus::Revision])
            ->get();

        foreach ($approvals as $approval) {
            $approval->forceFill([
                'status' => ApprovalStatus::Pending,
                'cancelled_reason' => ApprovalCancelReason::OwnerReset,
                'approved_at' => null,
            ])->save();
        }

        return $approvals->count();
    }

    /**
     * New bytes landed on a file: every decision made about the old ones
     * goes back to pending, wherever that file is shown.
     *
     * Returns how many were voided, so the caller can tell the owner what
     * their upload just cost — a silent reset of a client's sign-off is the
     * thing this method exists to prevent.
     */
    public static function forFile(File $file): int
    {
        $approvals = Approval::query()
            ->whereIn('space_item_id', $file->spaceItems()->select('id'))
            ->whereIn('status', [ApprovalStatus::Approved, ApprovalStatus::Revision])
            ->get();

        foreach ($approvals as $approval) {
            $approval->forceFill([
                'status' => ApprovalStatus::Pending,
                'cancelled_reason' => ApprovalCancelReason::FileReplaced,
                'approved_at' => null,
            ])->save();
        }

        return $approvals->count();
    }
}
