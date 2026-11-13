<?php

use App\Models\Plan;
use Illuminate\Support\Facades\DB;

/*
 * Plans data drift (decision 11 Nov): Free media cap 1 GB, Pro Keep 25 GB,
 * Max Keep 50 GB. Prod had Free 2 GB and Max 25 GB.
 */

const PSB_GB = 1024 * 1024 * 1024;

function psbMigrate(): void
{
    (require database_path('migrations/2026_12_03_100000_set_plan_storage_bytes.php'))->up();
}

function psbRow(string $key): array
{
    $row = (array) DB::table('plans')->where('key', $key)->first();
    $row['quotas'] = json_decode((string) $row['quotas'], true);
    $row['features'] = json_decode((string) $row['features'], true);
    unset($row['updated_at']);

    return $row;
}

it('seeds, configures and factories the decided caps', function () {
    expect(Plan::query()->where('key', 'free')->sole()->quota('storage_bytes'))->toBe(1 * PSB_GB)
        ->and(Plan::query()->where('key', 'pro')->sole()->quota('storage_bytes'))->toBe(25 * PSB_GB)
        ->and(Plan::query()->where('key', 'max')->sole()->quota('storage_bytes'))->toBe(50 * PSB_GB)
        ->and(config('media.tiers.free.total_bytes'))->toBe(1 * PSB_GB)
        ->and(config('media.tiers.pro.keep_quota_bytes'))->toBe(25 * PSB_GB)
        ->and(config('media.tiers.max.keep_quota_bytes'))->toBe(50 * PSB_GB)
        ->and(Plan::factory()->free()->make()->quotas['storage_bytes'])->toBe(1 * PSB_GB)
        ->and(Plan::factory()->make()->quotas['storage_bytes'])->toBe(25 * PSB_GB);
});

it('the migration fixes the prod drift, touches only storage_bytes, and is idempotent', function () {
    // Prod as found: Free 2 GB, Max 25 GB.
    foreach (['free' => 2, 'max' => 25] as $key => $gb) {
        $q = psbRow($key)['quotas'];
        $q['storage_bytes'] = $gb * PSB_GB;
        DB::table('plans')->where('key', $key)->update(['quotas' => json_encode($q)]);
    }
    $before = collect(['free', 'pro', 'max', 'team', 'enterprise'])->mapWithKeys(fn ($k) => [$k => psbRow($k)]);

    psbMigrate();
    $once = collect(['free', 'pro', 'max', 'team', 'enterprise'])->mapWithKeys(fn ($k) => [$k => psbRow($k)]);
    psbMigrate();
    $twice = collect(['free', 'pro', 'max', 'team', 'enterprise'])->mapWithKeys(fn ($k) => [$k => psbRow($k)]);

    expect($once['free']['quotas']['storage_bytes'])->toBe(1 * PSB_GB)
        ->and($once['pro']['quotas']['storage_bytes'])->toBe(25 * PSB_GB)
        ->and($once['max']['quotas']['storage_bytes'])->toBe(50 * PSB_GB)
        ->and($twice->all())->toEqual($once->all());

    foreach ($before as $key => $row) {
        $expected = $row;
        if (in_array($key, ['free', 'pro', 'max'], true)) {
            $expected['quotas']['storage_bytes'] = $once[$key]['quotas']['storage_bytes'];
        }
        expect($once[$key])->toEqual($expected);
    }
});
