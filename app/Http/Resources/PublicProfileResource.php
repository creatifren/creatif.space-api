<?php

namespace App\Http\Resources;

use App\Enums\ProfileMode;
use App\Models\ClientReport;
use App\Models\Handle;
use App\Models\Profile;
use App\Models\SocialApprovalLink;
use App\Support\ProfileAccess;
use App\Support\PublicFooter;
use App\Support\Workspace;
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
            // The "Made with Creatif Space" line. Pricing sells its removal
            // on paid plans, so the public page has to be told — the plan
            // itself never leaves the server. A team member's own page
            // rides on the team's plan.
            'branding' => $this->user === null
                || ! Workspace::owner($this->user)->plan()->feature('branding_removed'),
            // Footer per plan (Free full · Pro/Max one line · Enterprise none).
            ...PublicFooter::fields($this->user),
            'spaces' => $spaces,
            // v7: Link in bio or Grid, and the Contact button (no form).
            'layout' => $profile->getAttributes()['layout'] ?? 'link_in_bio',
            'contact' => $profile->contact ?? (object) [],
            // A verified custom domain (Pro + Max): every public page lives there.
            'custom_domain' => ($profile->getAttributes()['custom_domain_verified_at'] ?? null) !== null && $this->user !== null && ProfileAccess::customDomain($this->user)
                ? $profile->getAttributes()['custom_domain'] : null,
            // v7 PAGES: public Galleries / Blogs, content plans and reports.
            'pages' => $this->user === null ? [] : [
                ...$this->user->spaces()->where('status', 'published')->where('visibility', 'public')->latest('published_at')->get()
                    ->map(fn ($s) => ['kind' => $s->type ?? 'gallery', 'title' => $s->title, 'slug' => $s->slug])->all(),
                ...SocialApprovalLink::query()->where('user_id', $this->user->id)->where('visibility', 'public')->whereNull('revoked_at')
                    ->latest('id')->limit(20)->get()
                    ->filter(fn (SocialApprovalLink $l) => ! $l->expired())
                    ->map(fn (SocialApprovalLink $l) => ['kind' => 'content_plan', 'title' => $l->title ?: 'Content plan', 'url' => $l->url()])->values()->all(),
                ...ClientReport::query()->where('user_id', $this->user->id)->where('visibility', 'public')->whereNull('revoked_at')
                    ->latest('month')->limit(20)->get()
                    ->map(fn (ClientReport $r) => ['kind' => 'report', 'title' => $r->month, 'url' => $r->url()])->all(),
            ],
        ];
    }
}
