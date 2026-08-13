<?php

namespace App\Http\Resources;

use App\Models\TeamMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TeamMember
 */
class TeamMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'email' => $this->email,
            // Their real name once they have signed in; before that the
            // email is all anybody knows about them.
            'name' => $this->member?->name,
            'avatar_url' => $this->member?->avatar_url,
            'role' => $this->role,
            'status' => $this->status,
            'invited_at' => $this->invited_at,
            'joined_at' => $this->joined_at,
        ];
    }
}
