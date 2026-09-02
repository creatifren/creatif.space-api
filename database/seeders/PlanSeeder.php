<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The three plans, from DATABASE.md §F. Real production data — no local
 * guard, unlike StaffSeeder.
 *
 * updateOrCreate rather than firstOrCreate: prices change, and a re-run
 * must carry the new number rather than quietly keep the old one. Keyed on
 * `key`, so a row that someone edited in Filament is corrected, not doubled.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'key' => 'free',
                'name' => 'Free',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'seat_price_monthly' => 0,
                'quotas' => [
                    'storage_bytes' => 2 * 1024 * 1024 * 1024,
                    'spaces_total' => 10,
                    'spaces_active' => 3,
                    'seats' => 1,
                    'custom_domains' => 0,
                    // Zero, not null: Free gets no social posts at all.
                    'social_posts_monthly' => 0,
                ],
                'features' => [
                    'branding_removed' => false,
                    'delivery_history' => false,
                    'full_analytics' => false,
                    'custom_fonts' => false,
                ],
                'sort_order' => 1,
            ],
            [
                'key' => 'premium',
                'name' => 'Premium',
                'price_monthly' => 94_000,
                // Three months free: ten months paid, twelve used.
                'price_yearly' => 840_000,
                'seat_price_monthly' => 0,
                'quotas' => [
                    'storage_bytes' => 25 * 1024 * 1024 * 1024,
                    // null = no limit. Not zero, which would mean none.
                    'spaces_total' => null,
                    'spaces_active' => null,
                    'seats' => 1,
                    'custom_domains' => 1,
                    'social_posts_monthly' => 60,
                ],
                'features' => [
                    'branding_removed' => true,
                    'delivery_history' => true,
                    'full_analytics' => true,
                    'custom_fonts' => true,
                ],
                'sort_order' => 2,
            ],
            [
                'key' => 'team',
                'name' => 'Team / Agency',
                'price_monthly' => 270_000,
                'price_yearly' => 2_430_000,
                'seat_price_monthly' => 44_000,
                'quotas' => [
                    // Per-seat: PlanQuota multiplies storage and social posts
                    // by the seats bought, pooled across the workspace.
                    'storage_bytes' => 25 * 1024 * 1024 * 1024,
                    'spaces_total' => null,
                    'spaces_active' => null,
                    'seats' => 3,
                    'custom_domains' => 1,
                    'social_posts_monthly' => 60,
                ],
                'features' => [
                    'branding_removed' => true,
                    'delivery_history' => true,
                    'full_analytics' => true,
                    'custom_fonts' => true,
                ],
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(['key' => $plan['key']], $plan);
        }
    }
}
