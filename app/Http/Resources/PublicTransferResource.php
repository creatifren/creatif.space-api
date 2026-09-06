<?php

namespace App\Http\Resources;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A transfer as its recipient sees it: what is in it and who sent it.
 *
 * Deliberately absent: open and download counts (the sender's business),
 * the recipient list (one client should not learn who else got the same
 * files), and the note's audience is the person holding the link, so it
 * stays.
 *
 * @mixin \App\Models\Transfer
 */
class PublicTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'title' => $this->title,
            'note' => $this->note,
            'from' => ['name' => $this->user->name, 'handle' => $this->user->handle?->name],
            'expires_at' => $this->expires_at,
            'size_bytes' => (int) $this->files->sum('size_bytes'),
            'files' => $this->files->map(fn (File $file) => [
                'id' => $file->ulid,
                'name' => $file->name,
                'size_bytes' => $file->size_bytes,
                'mime_type' => $file->mime_type,
                // Signed and short-lived, like every other file we serve.
                'src' => $file->url(),
            ]),
        ];
    }
}
