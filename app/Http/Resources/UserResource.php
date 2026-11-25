<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\Media\MediaLinks;
use App\Support\AccountDeletion;
use App\Support\PlanQuota;
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
            'avatar_url' => MediaLinks::fresh($this->avatar_url),
            'locale' => $this->locale,
            'theme' => $this->theme,
            'timezone' => $this->timezone,
            // v7 Language & region.
            // Raw attributes: a model made in memory has no column defaults yet.
            'week_start' => (int) ($this->resource->getAttributes()['week_start'] ?? 1),
            'time_format' => $this->resource->getAttributes()['time_format'] ?? '24h',
            'status' => $this->status,
            // Null for almost everybody. The sidebar shows /referral only
            // on "approved" — one nullable string here beats a second
            // fetch on every dashboard page.
            'affiliate_status' => $this->affiliate?->status,
            // Which ways in this account has — Settings shows them. Never the
            // values themselves: both columns stay $hidden.
            'has_google' => $this->google_id !== null,
            'has_password' => $this->password !== null,
            // Where the current paid plan is managed: creem (web), google
            // (Play Store), or null on Free — the app shows "Manage
            // subscription on Google Play" for google.
            'billing_provider' => $this->activeSubscription()?->provider,
            'onboarded_at' => $this->onboarded_at,
            // Null until the dashboard tour is finished or skipped (RF-004).
            'tour_seen_at' => $this->tour_seen_at,
            'created_at' => $this->created_at,
            // Account deletion: set while the account waits to be purged
            // (status pending_deletion); null otherwise.
            'deletion_requested_at' => $this->deletion_requested_at?->utc()->toIso8601String(),
            'purge_at' => AccountDeletion::purgeAt($this->resource)?->utc()->toIso8601String(),
            // What this API supports, for an app that ships ahead of it.
            'capabilities' => [
                'account_deletion' => true,
                // The Insight PDF export (GET /insight/export.pdf): Pro and
                // Max — the app shows its Export menu when true.
                'insight_export' => PlanQuota::hasFeature($this->resource, 'insight'),
            ],
        ];
    }
}
