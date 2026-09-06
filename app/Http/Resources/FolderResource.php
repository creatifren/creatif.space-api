<?php

namespace App\Http\Resources;

use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Folder
 */
class FolderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            // The card's "N files · M sub-folders" line. whenCounted, same
            // reason as FileResource::version.
            'files_count' => $this->whenCounted('files'),
            'children_count' => $this->whenCounted('children'),
            'created_at' => $this->created_at,
        ];
    }
}
