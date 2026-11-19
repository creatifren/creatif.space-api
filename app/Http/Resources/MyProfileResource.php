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
            // IANA zone the user picked; null = not set (the app's zone applies).
            'timezone' => $this->user->timezone,
            'avatar_url' => $this->user->avatar_url,
            'handle' => $this->user->handle?->name,
            'mode' => $this->mode,
            /* Which layout a new Space starts in. Never public — this is a
               preference about Spaces that do not exist yet. */
            'default_view_mode' => $this->default_view_mode,
            'headline' => $this->headline,
            'bio' => $this->bio,
            'location' => $this->location,
            'socials' => $this->socials ?? (object) [],
            'appearance' => $this->appearance ?? (object) [],
            'seo' => $this->seo,
            'freelance' => $this->freelance ?? (object) [],
            'categories' => $this->categories ?? [],
            'cover_url' => $this->cover_url,
            // v7: Link in bio or Grid, the Contact button, the custom domain.
            'layout' => $this->getAttributes()['layout'] ?? 'link_in_bio',
            'contact' => $this->contact ?? (object) [],
            'custom_domain' => $this->getAttributes()['custom_domain'] ?? null,
            'custom_domain_verified' => ($this->getAttributes()['custom_domain_verified_at'] ?? null) !== null,
            'week_start' => (int) ($this->user->getAttributes()['week_start'] ?? 1),
            'time_format' => $this->user->getAttributes()['time_format'] ?? '24h',
        ];
    }
}
