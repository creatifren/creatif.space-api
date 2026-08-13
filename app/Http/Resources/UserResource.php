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
            'onboarded_at' => $this->onboarded_at,
            'created_at' => $this->created_at,
        ];
    }
}
