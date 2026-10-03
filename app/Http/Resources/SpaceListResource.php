<?php

namespace App\Http\Resources;

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
            'has_password' => $this->password_hash !== null,
            'expires_at' => $this->expires_at,
            'items_count' => $this->items->count(),
            'cover_url' => $firstPhoto?->file?->url(),
            'published_at' => $this->published_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
