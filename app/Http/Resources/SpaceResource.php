<?php

namespace App\Http\Resources;

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
            'has_password' => $this->password_hash !== null,
            'expires_at' => $this->expires_at,
            'design' => $this->design,
            'settings' => $this->settings,
            'seo' => $this->seo,
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->ulid,
                'file_id' => $item->file->ulid,
                'section' => $item->section,
                'sort_order' => $item->sort_order,
                'caption' => $item->caption,
                'file' => [
                    'name' => $item->file->name,
                    'mime_type' => $item->file->mime_type,
                    'size_bytes' => $item->file->size_bytes,
                    'url' => $item->file->url(),
                    'width' => $item->file->width,
                    'height' => $item->file->height,
                ],
            ])->values(),
            'published_at' => $this->published_at,
            'archived_at' => $this->archived_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
