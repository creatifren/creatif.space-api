<?php

namespace App\Http\Resources;

use App\Enums\ApprovalStatus;
use App\Enums\NoteAuthor;
use App\Models\Approval;
use App\Models\Space;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Space
 */
class SpaceResource extends JsonResource
{
    /**
     * The full document — editor bootstrap.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status,
            'visibility' => $this->visibility,
            'view_mode' => $this->view_mode,
            'approval_enabled' => $this->approval_enabled,
            'selling_enabled' => $this->selling_enabled,
            'has_password' => $this->password_hash !== null,
            'expires_at' => $this->expires_at,
            'design' => $this->design,
            'settings' => $this->settings,
            'seo' => $this->seo,
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->ulid,
                'drive_file_id' => $item->driveFile->ulid,
                'section' => $item->section,
                'sort_order' => $item->sort_order,
                'caption' => $item->caption,
                'file' => [
                    'name' => $item->driveFile->name,
                    'mime_type' => $item->driveFile->mime_type,
                    'size_bytes' => $item->driveFile->size_bytes,
                    'thumbnail_url' => $item->driveFile->thumbnail_url,
                    'access_lost' => $item->driveFile->access_lost_at !== null,
                ],
            ])->values(),
            'approvers' => $this->approvers(),
            'published_at' => $this->published_at,
            'archived_at' => $this->archived_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Everyone who has decided something in this Space, one card each —
     * the editor's Approval tab. Latest decision wins the headline; the
     * newest client note rides along as the quote.
     *
     * @return list<array<string, mixed>>
     */
    private function approvers(): array
    {
        $approvals = Approval::query()
            ->whereIn('space_item_id', $this->items->pluck('id'))
            ->whereNot('status', ApprovalStatus::Pending)
            ->with(['client', 'notes'])
            ->latest('updated_at')
            ->get();

        return $approvals->groupBy('client_id')
            ->map(function ($group) {
                $latest = $group->first();
                $note = $group->flatMap->notes
                    ->where('author_type', NoteAuthor::Client)
                    ->sortByDesc('id')
                    ->first();

                return [
                    'name' => $latest->client->displayName(),
                    'email' => $latest->client->email,
                    'avatar_url' => $latest->client->avatar_url,
                    'approved' => $group->where('status', ApprovalStatus::Approved)->count(),
                    'revision' => $group->where('status', ApprovalStatus::Revision)->count(),
                    'cancelled' => $group->where('status', ApprovalStatus::Cancelled)->count(),
                    'note' => $note?->body,
                    'last_activity' => $group->max('updated_at'),
                ];
            })->values()->all();
    }
}
