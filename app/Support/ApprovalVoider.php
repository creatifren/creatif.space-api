<?php

namespace App\Support;

use App\Enums\ApprovalCancelReason;
use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\DriveFile;
use App\Models\Space;
use App\Notifications\ApprovalsVoided;

/**
 * "Approvals auto-cancel when a file version changes" — the product promise,
 * with exactly one home. The sync job calls it when it sees a new md5; the
 * owner's Reset button calls it on purpose.
 */
class ApprovalVoider
{
    /**
     * Void every standing approval on this file and tell the owners. One
     * notification per Space, not per approval: a swapped photo is one piece
     * of news even when six clients had signed it off.
     */
    public static function fileChanged(DriveFile $file): int
    {
        $approvals = Approval::query()
            ->where('status', ApprovalStatus::Approved)
            ->whereHas('spaceItem', fn ($q) => $q->where('drive_file_id', $file->id))
            ->with('spaceItem.space.user')
            ->get();

        if ($approvals->isEmpty()) {
            return 0;
        }

        foreach ($approvals as $approval) {
            $approval->void(ApprovalCancelReason::FileVersionChanged);
        }

        $bySpace = $approvals->groupBy(fn (Approval $a) => $a->spaceItem->space_id);

        foreach ($bySpace as $spaceApprovals) {
            $space = $spaceApprovals->first()->spaceItem->space;

            $space->user->notify(new ApprovalsVoided(
                $space,
                $file->name,
                $spaceApprovals->count(),
            ));
        }

        return $approvals->count();
    }

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
                'version_hash_at_approval' => null,
            ])->save();
        }

        return $approvals->count();
    }
}
