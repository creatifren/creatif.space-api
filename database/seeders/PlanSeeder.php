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
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $paidQuotas = [
            'storage_bytes' => 25 * 1024 * 1024 * 1024,
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
                    'storage_bytes' => 2 * 1024 * 1024 * 1024,
                    'spaces_total' => 15,
                    // All fifteen may be published — there is no active limit.
                    'spaces_active' => null,
                    'seats' => 1,
                    'custom_domains' => 0,
                    'social_accounts' => 3,
                    // Publishes a month: one post to one account is one.
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
                'quotas' => $paidQuotas + ['social_posts_monthly' => 150],
                'features' => $paidFeatures,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'key' => 'max',
                'name' => 'Max',
                'price_monthly' => 1499,
                'price_yearly' => 14990,
                'quotas' => $paidQuotas + ['social_posts_monthly' => 500],
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
                    'social_posts_monthly' => 150,
                ],
                'features' => $paidFeatures,
                'is_active' => false,
                'sort_order' => 4,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(['key' => $plan['key']], $plan);
        }
    }
}
