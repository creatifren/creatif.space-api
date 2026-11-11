<?php

use Database\Seeders\PlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plans v2 (founder, 11 Nov 2026). Adds the scheduling window, queue and
 * approval-client quotas and the feature gates to every existing plan row,
 * from PlanSeeder::v2() — the one plan definition. Only those keys are
 * written: storage (the media section), prices and Credits are left as
 * they are, and no post, Space or link is touched. Enterprise is added as
 * a contact-sales row when it is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $v2 = PlanSeeder::v2();

        foreach (DB::table('plans')->get(['id', 'key', 'quotas', 'features']) as $plan) {
            $add = $v2[$plan->key] ?? null;
            if ($add === null) {
                continue;
            }

            DB::table('plans')->where('id', $plan->id)->update([
                'quotas' => json_encode([...(json_decode((string) $plan->quotas, true) ?: []), ...$add['quotas']]),
                'features' => json_encode([...(json_decode((string) $plan->features, true) ?: []), ...$add['features']]),
            ]);
        }

        $pro = DB::table('plans')->where('key', 'pro')->first(['quotas', 'features']);
        if ($pro !== null && ! DB::table('plans')->where('key', 'enterprise')->exists()) {
            DB::table('plans')->insert([
                'key' => 'enterprise',
                'name' => 'Enterprise',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'quotas' => json_encode([
                    ...(json_decode((string) $pro->quotas, true) ?: []),
                    'credits_monthly' => 150000,
                    'social_posts_monthly' => 500,
                    ...$v2['enterprise']['quotas'],
                ]),
                'features' => json_encode([...(json_decode((string) $pro->features, true) ?: []), ...$v2['enterprise']['features']]),
                'is_active' => true,
                'sort_order' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Takes the plans-v2 keys back off; the Enterprise row stays if any
     * subscription points at it, otherwise it goes.
     */
    public function down(): void
    {
        $v2 = PlanSeeder::v2();

        foreach (DB::table('plans')->get(['id', 'key', 'quotas', 'features']) as $plan) {
            $quotas = json_decode((string) $plan->quotas, true) ?: [];
            $features = json_decode((string) $plan->features, true) ?: [];
            foreach (array_keys($v2['free']['quotas']) as $k) {
                unset($quotas[$k]);
            }
            foreach (['ai_caption', 'insight', 'spaces', 'alt_text', 'ai_reply_suggestions', 'comment_to_dm', 'contact_sales'] as $k) {
                unset($features[$k]);
            }
            if ($plan->key === 'free') {
                $features['client_approvals'] = false;
            }
            DB::table('plans')->where('id', $plan->id)->update([
                'quotas' => json_encode($quotas),
                'features' => json_encode($features),
            ]);
        }

        $enterprise = DB::table('plans')->where('key', 'enterprise')->value('id');
        if ($enterprise !== null && ! DB::table('subscriptions')->where('plan_id', $enterprise)->exists()) {
            DB::table('plans')->where('id', $enterprise)->delete();
        }
    }
};
