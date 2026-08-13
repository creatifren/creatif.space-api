<?php

namespace App\Http\Resources;

use App\Models\FileRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a stranger holding the link may see. Enough to know who is asking
 * and what the limits are — and nothing about the owner's Drive.
 *
 * Deliberately not a subset of FileRequestResource: this is a different
 * audience, and keeping it a separate file is what stops a field being
 * added for the dashboard and leaking here by accident.
 *
 * @mixin FileRequest
 */
class PublicFileRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'note' => $this->note,
            'owner_name' => $this->user->name,
            'max_files' => $this->max_files,
            'max_mb' => $this->max_mb,
        ];
    }
}
