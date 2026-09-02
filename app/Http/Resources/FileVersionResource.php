<?php

namespace App\Http\Resources;

use App\Models\FileVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FileVersion
 */
class FileVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'number' => $this->number,
            'size_bytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'url' => $this->url(),
            'note' => $this->note,
            // Who replaced it. Null once that account is gone — the version
            // outlives the person, and "by —" beats inventing a name.
            'by' => $this->whenLoaded('user', fn () => $this->user?->name),
            'created_at' => $this->created_at,
        ];
    }
}
