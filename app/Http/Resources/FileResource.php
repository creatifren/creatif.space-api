<?php

namespace App\Http\Resources;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin File
 */
class FileResource extends JsonResource
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
            'name' => $this->name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'url' => $this->url(),
            'width' => $this->width,
            'height' => $this->height,
            'status' => $this->status,
            /* How many sets of bytes this file has had, counting the
               current ones — so a file that was never replaced is "v1",
               not "v0". The list itself is its own request.

               whenCounted, because a caller that skipped withCount should
               get no key rather than a 500 or a confident wrong number. */
            'version' => $this->whenCounted(
                'versions',
                fn () => 1 + $this->versions_count,
            ),
            'source' => $this->source,
            'exif' => $this->exif,
            'created_at' => $this->created_at,

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
