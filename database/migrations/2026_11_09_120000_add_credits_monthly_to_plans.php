<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The publishing allowance becomes Monthly Credits (owner, 2026-11-09):
 * each plan's `credits_monthly` is its old publishes a month × 300 —
 * Free 3.000, Pro 45.000, Max 150.000 (Team 45.000 a seat).
 * `social_posts_monthly` stays in the JSON, readable, unused.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('plans')->get(['id', 'quotas']) as $plan) {
            $quotas = json_decode((string) $plan->quotas, true) ?: [];

            if (array_key_exists('credits_monthly', $quotas)) {
                continue;
            }

            $posts = $quotas['social_posts_monthly'] ?? null;
            $quotas['credits_monthly'] = $posts === null ? null : (int) $posts * 300;

            DB::table('plans')->where('id', $plan->id)->update(['quotas' => json_encode($quotas)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->get(['id', 'quotas']) as $plan) {
            $quotas = json_decode((string) $plan->quotas, true) ?: [];
            unset($quotas['credits_monthly']);
            DB::table('plans')->where('id', $plan->id)->update(['quotas' => json_encode($quotas)]);
        }
    }
};
