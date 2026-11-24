<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spaces on every plan (Rizal 24 Nov, prototype v10.21). Free gets the
 * `spaces` feature, loses the 15-Space cap (storage is the only limit) and
 * gains the anti-spam `spaces_daily` quota (10 new Spaces a day); paid plans
 * get `spaces_daily` = null (no limit). Data only — no schema change, no
 * Space touched. Same values as PlanSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('plans')->get(['id', 'key', 'quotas', 'features']) as $plan) {
            $quotas = json_decode((string) $plan->quotas, true) ?: [];
            $features = json_decode((string) $plan->features, true) ?: [];
            if ($plan->key === 'free') {
                $features['spaces'] = true;
                $quotas['spaces_total'] = null;
                $quotas['spaces_daily'] = 10;
            } else {
                $quotas['spaces_daily'] = null;
            }
            DB::table('plans')->where('id', $plan->id)->update([
                'quotas' => json_encode($quotas),
                'features' => json_encode($features),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->get(['id', 'key', 'quotas', 'features']) as $plan) {
            $quotas = json_decode((string) $plan->quotas, true) ?: [];
            $features = json_decode((string) $plan->features, true) ?: [];
            unset($quotas['spaces_daily']);
            if ($plan->key === 'free') {
                $features['spaces'] = false;
                $quotas['spaces_total'] = 15;
            }
            DB::table('plans')->where('id', $plan->id)->update([
                'quotas' => json_encode($quotas),
                'features' => json_encode($features),
            ]);
        }
    }
};
