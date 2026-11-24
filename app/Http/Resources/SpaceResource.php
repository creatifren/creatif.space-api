<?php

namespace App\Http\Resources;

use App\Http\Controllers\Api\V1\SpaceController;
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
            // v7: gallery | blog.
            'type' => $this->type ?? 'gallery',
            'description' => $this->description,
            'status' => $this->status,
            'visibility' => $this->visibility,
            'view_mode' => $this->view_mode,
            'has_password' => $this->password_hash !== null,
            'expires_at' => $this->expires_at,
            // Where it opens (or will once published); null without a handle.
            'public_url' => $this->publicUrl(),
            'design' => $this->design,
            'settings' => $this->settings,
            'seo' => $this->seo,
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->ulid,
                'file_id' => $item->file->ulid,
                'section' => $item->section,
                'sort_order' => $item->sort_order,
                'caption' => $item->caption,
                'title' => $item->title,
                'featured' => (bool) $item->featured,
                'hidden' => (bool) $item->hidden,
                // v10.15: the shown crop (design.items meta); null = original shape.
                'crop' => SpaceController::cropOf(((array) ($this->design['items'] ?? []))[$item->ulid] ?? null),
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
