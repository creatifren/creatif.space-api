<?php

namespace App\Http\Resources;

use App\Models\FileRequest;
use App\Models\FileRequestSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The owner's view of a request — theirs, so it may name the folder.
 *
 * @mixin FileRequest
 */
class FileRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'slug' => $this->slug,
            'url' => config('app.frontend_url').'/r/'.$this->slug,
            'title' => $this->title,
            'note' => $this->note,
            'folder_id' => $this->target_folder_id,
            'drive_account_id' => $this->driveAccount->ulid,
            'max_files' => $this->max_files,
            'max_mb' => $this->max_mb,
            'status' => $this->status,
            'expired' => $this->isExpired(),
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
            'submissions_count' => $this->whenCounted('submissions'),
            'submissions' => $this->whenLoaded(
                'submissions',
                fn () => $this->submissions
                    ->map(fn (FileRequestSubmission $s): array => [
                        'id' => $s->ulid,
                        'sender_name' => $s->sender_name,
                        'sender_email' => $s->sender_email,
                        'message' => $s->message,
                        'files' => $s->files,
                        'status' => $s->status,
                        'failure_reason' => $s->failure_reason,
                        'created_at' => $s->created_at,
                    ])
                    ->all(),
            ),
        ];
    }
}
