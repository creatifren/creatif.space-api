<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A transfer as its sender sees it.
 *
 * @mixin \App\Models\Transfer
 */
class TransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'title' => $this->title,
            'note' => $this->note,
            'url' => config('app.frontend_url').'/t/'.$this->slug,
            'files_count' => $this->whenCounted('files'),
            'size_bytes' => $this->whenLoaded('files', fn () => (int) $this->files->sum('size_bytes')),
            // Whether it is locked, never the hash — see the model's $hidden.
            'has_password' => $this->password_hash !== null,
            'expires_at' => $this->expires_at,
            'expired' => $this->isExpired(),
            'opens' => $this->opens,
            'downloads' => $this->downloads,
            'recipients' => $this->whenLoaded(
                'recipients',
                fn () => $this->recipients->pluck('email'),
            ),
            'created_at' => $this->created_at,
        ];
    }
}
