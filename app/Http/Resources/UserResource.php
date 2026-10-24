<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'handle' => $this->handle?->name,
            // When Settings may offer a username change again.
            'handle_changeable_at' => $this->handle?->changeableAt(),
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'locale' => $this->locale,
            'theme' => $this->theme,
            'status' => $this->status,
            // Null for almost everybody. The sidebar shows /referral only
            // on "approved" — one nullable string here beats a second
            // fetch on every dashboard page.
            'affiliate_status' => $this->affiliate?->status,
            // Which ways in this account has — Settings shows them. Never the
            // values themselves: both columns stay $hidden.
            'has_google' => $this->google_id !== null,
            'has_password' => $this->password !== null,
            'onboarded_at' => $this->onboarded_at,
            // Null until the dashboard tour is finished or skipped (RF-004).
            'tour_seen_at' => $this->tour_seen_at,
            'created_at' => $this->created_at,
        ];
    }
}
