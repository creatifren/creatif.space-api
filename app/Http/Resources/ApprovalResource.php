<?php

namespace App\Http\Resources;

use App\Enums\NoteAuthor;
use App\Models\Approval;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Approval
 */
class ApprovalResource extends JsonResource
{
    /**
     * One decision, with the note that came with it and the owner's reply.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $clientNote = $this->notes->firstWhere('author_type', NoteAuthor::Client);
        $ownerReply = $this->notes->firstWhere('author_type', NoteAuthor::Owner);

        return [
            'id' => $this->ulid,
            'item_id' => $this->spaceItem->ulid,
            'file_name' => $this->spaceItem->file->name,
            'status' => $this->status,
            'approved_at' => $this->approved_at,
            'cancelled_reason' => $this->cancelled_reason,
            'client' => [
                'name' => $this->client->displayName(),
                'email' => $this->client->email,
                'avatar_url' => $this->client->avatar_url,
            ],
            'note' => $clientNote === null ? null : [
                'body' => $clientNote->body,
                'chips' => $clientNote->chips ?? [],
                'created_at' => $clientNote->created_at,
            ],
            'reply' => $ownerReply === null ? null : [
                'body' => $ownerReply->body,
                'created_at' => $ownerReply->created_at,
            ],
            'updated_at' => $this->updated_at,
        ];
    }
}
