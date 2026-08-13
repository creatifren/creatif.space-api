<?php

namespace App\Http\Resources;

use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Profile
 */
class MyProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->user->name,
            'locale' => $this->user->locale,
            'theme' => $this->user->theme,
            'avatar_url' => $this->user->avatar_url,
            'handle' => $this->user->handle?->name,
            'mode' => $this->mode,
            'headline' => $this->headline,
            'bio' => $this->bio,
            'location' => $this->location,
            'socials' => $this->socials ?? (object) [],
            'appearance' => $this->appearance ?? (object) [],
            'seo' => $this->seo,
            'freelance' => $this->freelance ?? (object) [],
            'categories' => $this->categories ?? [],
            'cover_url' => $this->cover_url,
        ];
    }
}
