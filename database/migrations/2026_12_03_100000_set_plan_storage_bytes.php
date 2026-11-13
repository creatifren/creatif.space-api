<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plans data drift (decision 11 Nov 2026): prod had Free at 2 GB and Max at
 * 25 GB. The media caps are Free 1 GB (the whole library), Pro 25 GB Keep,
 * Max 50 GB Keep — the same as config/media.php and PlanSeeder.
 *
 * Only quotas.storage_bytes on free / pro / max is written; every other
 * plan field and plan row is left as it is. Idempotent: a re-run writes the
 * same numbers. down() is a no-op — the old numbers were the bug.
 */
return new class extends Migration
{
    private const GB = 1024 * 1024 * 1024;

    /** @var array<string, int> */
    private const STORAGE_BYTES = [
        'free' => 1 * self::GB,
        'pro' => 25 * self::GB,
        'max' => 50 * self::GB,
    ];

    public function up(): void
    {
        foreach (DB::table('plans')->whereIn('key', array_keys(self::STORAGE_BYTES))->get(['id', 'key', 'quotas']) as $plan) {
            $quotas = json_decode((string) $plan->quotas, true) ?: [];
            if (($quotas['storage_bytes'] ?? null) === self::STORAGE_BYTES[$plan->key]) {
                continue;
            }
            $quotas['storage_bytes'] = self::STORAGE_BYTES[$plan->key];

            DB::table('plans')->where('id', $plan->id)->update(['quotas' => json_encode($quotas)]);
        }
    }

    public function down(): void {}
};
