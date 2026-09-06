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
            /* Which Drive brought it, when one did. whenLoaded: the list
               eager-loads the relation, a single-file read does not need
               it, and neither should invent an email. */
            'source_account' => $this->whenLoaded(
                'sourceAccount',
                /* $this->resource, not $this: the resource proxies property
                   reads through __get, which erases the model type, and the
                   relation is typed on File itself. */
                fn () => $this->resource->sourceAccount === null ? null : [
                    'id' => $this->resource->sourceAccount->ulid,
                    'email' => $this->resource->sourceAccount->email,
                ],
            ),
            'exif' => $this->exif,
            'created_at' => $this->created_at,

            /* Trash only: when it went in, and the date the purge job will
               take it. Both null for a live file, so the client can tell
               the two apart without a second shape. The countdown on screen
               is this date, not created_at plus a constant — a window we
               change later must not move files already in the bin. */
            'deleted_at' => $this->deleted_at,
            'purge_at' => $this->purge_at,
            /* Where it was — the Trash's "WAS IN" column. whenLoaded, so a
               caller that skipped the eager load gets no key rather than a
               query per row. */
            'folder' => $this->whenLoaded(
                'folder',
                // $this->resource for the same reason as source_account below.
                fn () => $this->resource->folder === null
                    ? null
                    : [
                        'id' => $this->resource->folder->ulid,
                        'name' => $this->resource->folder->name,
                    ],
            ),

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
