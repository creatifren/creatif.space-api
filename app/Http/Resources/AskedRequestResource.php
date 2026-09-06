<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A request somebody asked of me, from my side: who wanted the files, what
 * they called it, and what I sent.
 *
 * Deliberately absent: everything about the owner's side of the drop-box —
 * other people's submissions, the ceiling, the storage it landed in. I was
 * a sender here, not a participant in their library.
 *
 * @mixin \App\Models\FileRequestSubmission
 */
class AskedRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fileRequest = $this->fileRequest;

        return [
            'id' => $this->ulid,
            'title' => $fileRequest?->title,
            'url' => $fileRequest === null
                ? null
                : config('app.frontend_url').'/r/'.$fileRequest->slug,
            'asked_by' => $fileRequest?->user?->name,
            'files_count' => count($this->files ?? []),
            'status' => $this->status,
            'sent_at' => $this->created_at,
            /* Whether the link still works, so the row can say "closed"
               rather than sending somebody back to a dead page. */
            'expired' => $fileRequest?->isExpired() ?? true,
        ];
    }
}
