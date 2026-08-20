<?php

namespace App\Http\Resources;

use App\Enums\ProfileMode;
use App\Models\Handle;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Handle
 */
class PublicProfileResource extends JsonResource
{
    /**
     * Public shape of creatif.space/{handle}. Deliberately excludes email,
     * ids, and any statistics — "PROFILE is not a SPACE".
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // A handle can be claimed before the profile row exists — a blank
        // one stands in so every read below has something to answer with.
        $profile = $this->user?->profile()->first() ?? new Profile;
        $freelance = $profile->mode === ProfileMode::Freelance;

        // The work grid: published AND public Spaces only. Private ones are
        // link-only and never appear on the profile.
        $spaces = $this->user === null ? collect() : $this->user->spaces()
            ->where('status', 'published')
            ->where('visibility', 'public')
            ->with('items.file')
            ->latest('published_at')
            ->get()
            ->map(fn ($space) => [
                'title' => $space->title,
                'slug' => $space->slug,
                'items_count' => $space->items->count(),
                'cover_url' => $space->items->first(
                    fn ($item) => $item->file !== null
                        && str_starts_with($item->file->mime_type, 'image/'),
                )?->file?->url(),
                'published_at' => $space->published_at,
            ])
            ->values();

        return [
            'handle' => $this->name,
            'name' => $this->user?->name,
            'avatar_url' => $this->user?->avatar_url,
            'mode' => $profile->mode ?? ProfileMode::Portfolio,
            'headline' => $profile->headline,
            'bio' => $profile->bio,
            'location' => $profile->location,
            'socials' => $profile->socials ?? (object) [],
            'appearance' => $profile->appearance ?? (object) [],
            'seo' => $profile->seo,
            'categories' => $profile->categories ?? [],
            'cover_url' => $profile->cover_url,
            // The Portfolio/Freelance switch is the spine: in portfolio mode
            // the freelance block simply does not exist on the public page.
            'freelance' => $freelance ? ($profile->freelance ?? (object) []) : null,
            'spaces' => $spaces,
            'offers' => $this->offers(),
        ];
    }

    /**
     * The price list — offers the owner chose to show here. An offer that
     * lives only on a Space stays on that Space.
     *
     * @return list<array<string, mixed>>
     */
    private function offers(): array
    {
        if ($this->user === null) {
            return [];
        }

        return $this->user->offers()
            ->where('is_active', true)
            ->where('show_on_profile', true)
            ->get()
            ->map(fn ($offer) => [
                'id' => $offer->ulid,
                'type' => $offer->type,
                'title' => $offer->title,
                'description' => $offer->description,
                'price' => $offer->price,
                'price_from' => $offer->price_from,
                'buyable' => $offer->isBuyable(),
                'needs_amount' => $offer->needsBuyerAmount(),
            ])
            ->values()
            ->all();
    }
}
