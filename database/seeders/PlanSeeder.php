<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The plans, as sold on /pricing (owner, 25 Oct 2026). Real production data
 * — no local guard, unlike StaffSeeder.
 *
 * Prices are US cents. Yearly is ten months' price for twelve months' use.
 * Pro and Max share every feature and limit; only the monthly publishing
 * allowance differs. Team is planned, not sold: kept inactive so /plans and
 * checkout leave it out.
 *
 * updateOrCreate rather than firstOrCreate: prices change, and a re-run
 * must carry the new number rather than quietly keep the old one. Keyed on
 * `key`, so a row that someone edited in Filament is corrected, not doubled.
 *
 * This file is the one plan definition. Two sections, two owners:
 *
 * - media: `storage_bytes` (and anything storage / rental) — owned by the
 *   Media v2 work. Plans v2 does not touch those lines.
 * - plans v2 (founder, 11 Nov 2026): self::v2() — scheduling window and
 *   queue, approval clients, and the feature gates PlanQuota enforces.
 *   Enterprise is listed, never sold: contact sales only.
 *
 * Storage upgrades are not plans and never appear here.
 */
class PlanSeeder extends Seeder
{
    /**
     * Plans v2 — merged over each row's quotas / features below and by the
     * plans-v2 migration onto existing rows. null quota = no limit.
     *
     * @return array<string, array{quotas: array<string, int|null>, features: array<string, bool>}>
     */
    public static function v2(): array
    {
        $paid = [
            'quotas' => [
                // Days ahead a post may be scheduled (UTC, from now).
                'schedule_window_days' => null,
                // Scheduled + waiting-for-approval posts per workspace.
                'queued_posts' => null,
                // Distinct clients across open approval links.
                'approval_clients' => null,
            ],
            'features' => [
                // Approval links are on every plan now; Free has limits above.
                'client_approvals' => true,
                'ai_caption' => true,
                'insight' => true,
                'spaces' => true,
                'alt_text' => true,
                'ai_reply_suggestions' => true,
                'comment_to_dm' => true,
            ],
        ];

        return [
            'free' => [
                'quotas' => [
                    'schedule_window_days' => 7,
                    'queued_posts' => 10,
                    'approval_clients' => 1,
                ],
                'features' => [
                    'client_approvals' => true,
                    'ai_caption' => false,
                    'insight' => false,
                    'spaces' => false,
                    'alt_text' => false,
                    'ai_reply_suggestions' => false,
                    'comment_to_dm' => false,
                ],
            ],
            'pro' => $paid,
            'max' => $paid,
            'team' => $paid,
            'enterprise' => [
                'quotas' => $paid['quotas'],
                'features' => $paid['features'] + ['contact_sales' => true],
            ],
        ];
    }

    public function run(): void
    {
        $paidQuotas = [
            // Media v2: display only — limits are enforced from config/media.php
            // (Keep quota per tier); Pro/Max set their own below.
            'storage_bytes' => (int) config('media.tiers.pro.keep_quota_bytes'),
            // null = no limit. Not zero, which would mean none.
            'spaces_total' => null,
            'spaces_active' => null,
            'seats' => 1,
            'custom_domains' => 1,
            'social_accounts' => null,
        ];

        $paidFeatures = [
            'branding_removed' => true,
            'client_approvals' => true,
            'delivery_history' => true,
            'full_analytics' => true,
            'custom_fonts' => true,
        ];

        $plans = [
            [
                'key' => 'free',
                'name' => 'Free',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'quotas' => [
                    // Media v2: Free's 1 GB total (config/media.php).
                    'storage_bytes' => (int) config('media.tiers.free.total_bytes'),
                    'spaces_total' => 15,
                    // All fifteen may be published — there is no active limit.
                    'spaces_active' => null,
                    'seats' => 1,
                    'custom_domains' => 0,
                    'social_accounts' => 3,
                    // Monthly Credits: granted each cycle, expire at its end.
                    'credits_monthly' => 3000,
                    // Legacy, unused: publishes a month before Monthly Credits.
                    'social_posts_monthly' => 10,
                ],
                'features' => [
                    'branding_removed' => false,
                    'client_approvals' => false,
                    'delivery_history' => false,
                    'full_analytics' => false,
                    'custom_fonts' => false,
                ],
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'key' => 'pro',
                'name' => 'Pro',
                'price_monthly' => 999,
                'price_yearly' => 9990,
                'quotas' => $paidQuotas + ['credits_monthly' => 45000, 'social_posts_monthly' => 150],
                'features' => $paidFeatures,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'key' => 'max',
                'name' => 'Max',
                'price_monthly' => 1499,
                'price_yearly' => 14990,
                // Media v2: Max's Keep quota (config/media.php).
                'quotas' => ['storage_bytes' => (int) config('media.tiers.max.keep_quota_bytes')] + $paidQuotas + ['credits_monthly' => 150000, 'social_posts_monthly' => 500],
                'features' => $paidFeatures,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'key' => 'team',
                'name' => 'Team',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'quotas' => [
                    ...$paidQuotas,
                    // Per-seat: PlanQuota multiplies storage and publishes by
                    // the seats bought, pooled across the workspace.
                    'seats' => 3,
                    'credits_monthly' => 45000,
                    'social_posts_monthly' => 150,
                ],
                'features' => $paidFeatures,
                'is_active' => false,
                'sort_order' => 4,
            ],
            [
                // Listed on /plans with contact_sales, never in checkout
                // (PlanKey::sold()). Prices are agreed per contract.
                'key' => 'enterprise',
                'name' => 'Enterprise',
                'price_monthly' => 0,
                'price_yearly' => 0,
                // Media v2: Max's Keep quota (config/media.php).
                'quotas' => ['storage_bytes' => (int) config('media.tiers.max.keep_quota_bytes')] + $paidQuotas + ['credits_monthly' => 150000, 'social_posts_monthly' => 500],
                'features' => $paidFeatures,
                'is_active' => true,
                'sort_order' => 5,
            ],
        ];

        $v2 = self::v2();
        foreach ($plans as $plan) {
            $plan['quotas'] = [...$plan['quotas'], ...$v2[$plan['key']]['quotas']];
            $plan['features'] = [...$plan['features'], ...$v2[$plan['key']]['features']];
            Plan::query()->updateOrCreate(['key' => $plan['key']], $plan);
        }
    }
}
