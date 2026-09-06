<?php

namespace App\Http\Resources;

use App\Models\FileRequest;
use App\Models\FileRequestSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The owner's view of a request.
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
            'max_files' => $this->max_files,
            'max_mb' => $this->max_mb,
            // Whether, never what: the card's Password chip needs one bit,
            // and the hash is not it.
            'has_password' => $this->password_hash !== null,
            'status' => $this->status,
            'expired' => $this->isExpired(),
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
            'submissions_count' => $this->whenCounted('submissions'),
            /* What the card actually renders: how many people sent
               something, how many files arrived, and how much. Counted
               server-side because the client was deriving them from the
               embedded submissions and getting people wrong — two
               deliveries from one person read as two people.
               Only `stored` rows count: a failed push delivered nothing. */
            'people_count' => $this->whenLoaded(
                'submissions',
                fn () => $this->storedSubmissions()
                    ->pluck('sender_email')
                    ->unique()
                    ->count(),
            ),
            'files_count' => $this->whenLoaded(
                'submissions',
                fn () => $this->storedSubmissions()
                    ->sum(fn (FileRequestSubmission $s): int => count($s->files ?? [])),
            ),
            /* Null, not 0, when nothing has arrived — the card shows a dash
               for "nothing yet" and 0 B would be a different claim. Old
               submissions predate the byte record and contribute nothing,
               so a long-lived request can under-report rather than lie
               about individual files. */
            'size_bytes' => $this->whenLoaded('submissions', function (): ?int {
                $stored = $this->storedSubmissions();

                if ($stored->isEmpty()) {
                    return null;
                }

                return $stored->sum(
                    fn (FileRequestSubmission $s): int => collect($s->files ?? [])
                        ->sum(fn (array $f): int => (int) ($f['bytes'] ?? 0)),
                );
            }),
            'submissions' => $this->whenLoaded(
                'submissions',
                fn () => $this->submissions
                    ->map(fn (FileRequestSubmission $s): array => [
                        'id' => $s->ulid,
                        'sender_name' => $s->sender_name,
                        'sender_email' => $s->sender_email,
                        'message' => $s->message,
                        'files' => $s->files,
                        'bytes' => collect($s->files ?? [])
                            ->sum(fn (array $f): int => (int) ($f['bytes'] ?? 0)),
                        'status' => $s->status,
                        'failure_reason' => $s->failure_reason,
                        'created_at' => $s->created_at,
                    ])
                    ->all(),
            ),
        ];
    }
}
