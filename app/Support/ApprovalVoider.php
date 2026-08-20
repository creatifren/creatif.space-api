<?php

namespace App\Support;

use App\Enums\ApprovalCancelReason;
use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\Space;

/**
 * Files are immutable once ready (R2 objects never change), so "the file
 * version changed" is no longer a way to lose an approval — replacing a
 * photo means swapping the Space item, and item deletion cascades its
 * approvals. What remains is the owner's deliberate reset.
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
}
