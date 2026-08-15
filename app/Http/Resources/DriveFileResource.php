<?php

namespace App\Http\Resources;

use App\Models\DriveFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DriveFile
 */
class DriveFileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'account_id' => $this->account->ulid,
            'provider_file_id' => $this->provider_file_id,
            'parent_folder_id' => $this->parent_folder_id,
            'name' => $this->name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'thumbnail_url' => $this->thumbnail_url,
            'is_folder' => $this->is_folder,
            'exif' => $this->exif,
            'access_lost' => $this->access_lost_at !== null,
            'last_synced_at' => $this->last_synced_at,

            /* The Spaces this file appears in — the drawer's "Used in Space"
             * chips and the list's "in a Space" pill. Guarded by whenLoaded so
             * a caller that forgets the eager load gets no key rather than a
             * silent query per row. A file can sit in one Space twice, in
             * different sections, so ids are made unique before they become
             * chips. */
            'spaces' => $this->whenLoaded('spaceItems', fn () => $this->spaceItems
                ->pluck('space')
                ->filter()
                ->unique('id')
                ->values()
                ->map(fn ($space) => [
                    'id' => $space->ulid,
                    'title' => $space->title,
                ])),
        ];
    }
}
