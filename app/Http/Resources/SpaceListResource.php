<?php

namespace App\Http\Resources;

use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\Space;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Space
 */
class SpaceListResource extends JsonResource
{
    /**
     * Card shape for the /spaces list.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $firstPhoto = $this->items->first(
            fn ($item) => $item->file !== null
                && str_starts_with($item->file->mime_type, 'image/'),
        );

        return [
            'id' => $this->ulid,
            'title' => $this->title,
            'slug' => $this->slug,
            'status' => $this->status,
            'visibility' => $this->visibility,
            'view_mode' => $this->view_mode,
            'approval_enabled' => $this->approval_enabled,
            'approval_state' => $this->approvalState(),
            'selling_enabled' => $this->selling_enabled,
            'has_password' => $this->password_hash !== null,
            'expires_at' => $this->expires_at,
            'items_count' => $this->items->count(),
            'cover_url' => $firstPhoto?->file?->url(),
            'published_at' => $this->published_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * The card chip: unsent (nobody has decided anything), approved (every
     * file carries a standing approval), else waiting. Free plan caps the
     * list at ten Spaces, so a query per card stays cheap.
     */
    private function approvalState(): ?string
    {
        if (! $this->approval_enabled) {
            return null;
        }

        $itemIds = $this->items->pluck('id');

        $decided = Approval::query()
            ->whereIn('space_item_id', $itemIds)
            ->whereNot('status', ApprovalStatus::Pending)
            ->get(['space_item_id', 'status']);

        if ($decided->isEmpty()) {
            return 'unsent';
        }

        $approvedItems = $decided
            ->where('status', ApprovalStatus::Approved)
            ->pluck('space_item_id')
            ->unique();

        return $itemIds->count() > 0 && $approvedItems->count() === $itemIds->count()
            ? 'approved'
            : 'waiting';
    }
}
