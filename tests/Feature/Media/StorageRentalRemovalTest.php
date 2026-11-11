<?php

use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\Credits;
use App\Support\Media\StorageRental;
use App\Support\PlanQuota;
use Illuminate\Console\Scheduling\Schedule;

/*
 * Rule 1: storage rental is gone. Active rentals are ended (rows kept),
 * nothing is charged or refunded, the renewal job and its schedule are gone,
 * and nothing offers storage for Credits any more.
 */

it('ends every active rental without charging, refunding or deleting anything', function () {
    $renter = User::factory()->create(['storage_blocks' => 3]);
    $other = User::factory()->create(['storage_blocks' => 0]);
    $before = WalletTransaction::query()->count();

    expect(StorageRental::active())->toHaveCount(1)
        ->and(StorageRental::active()[0])->toMatchArray(['user_ulid' => $renter->ulid, 'blocks' => 3]);

    expect(StorageRental::endAll('test'))->toBe(1)
        ->and(StorageRental::endAll('test again'))->toBe(0); // idempotent

    $renter->refresh();
    expect($renter->storage_rental_ended_at)->not->toBeNull()
        ->and($renter->storage_blocks)->toBe(3) // the record stays
        ->and($other->fresh()->storage_rental_ended_at)->toBeNull()
        ->and(WalletTransaction::query()->count())->toBe($before)
        ->and(StorageRental::active())->toBe([]);
});

it('no longer adds rented blocks to anybody\'s storage', function () {
    $renter = User::factory()->create(['storage_blocks' => 4]);

    // Free: the 1 GB media cap, whatever was rented.
    expect(PlanQuota::storageLimit($renter))->toBe(1024 ** 3);
});

it('has removed the rental endpoints', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/credits/storage-blocks', ['blocks' => 1])->assertNotFound();
    $this->actingAs($user)->deleteJson('/api/v1/credits/storage-blocks', ['blocks' => 1])->assertNotFound();

    expect(WalletTransaction::query()->where('type', 'credits_rent')->exists())->toBeFalse();
});

it('has removed the monthly renewal job and its schedule entry', function () {
    expect(class_exists('App\\Jobs\\RenewCredits'))->toBeFalse()
        ->and(method_exists(Credits::class, 'addBlocks'))->toBeFalse()
        ->and(method_exists(Credits::class, 'monthly'))->toBeFalse();

    $events = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->description);
    expect($events->filter(fn ($d) => str_contains($d, 'RenewCredits'))->all())->toBe([])
        ->and($events->contains(fn ($d) => str_contains($d, 'ApplyMediaRetention')))->toBeTrue();
});

it('offers no storage upgrade in /credits or the plan catalog', function () {
    $user = User::factory()->create();

    $credits = $this->actingAs($user)->getJson('/api/v1/credits')->assertOk();
    expect($credits->json('data.rate'))->not->toHaveKeys(['block_gb', 'per_block', 'per_block_bulk', 'bulk_from_gb'])
        ->and($credits->json('data.storage'))->not->toHaveKeys(['blocks', 'rent'])
        ->and($credits->json('data.committed'))->toHaveKeys(['sends', 'sends_monthly'])->not->toHaveKey('rent')
        ->and($credits->json('data.storage.media'))->toBe(['tier' => 'free', 'used_bytes' => 0, 'quota_bytes' => 1024 ** 3]);

    $plans = $this->getJson('/api/v1/plans')->assertOk()->getContent();
    expect(strtolower((string) $plans))->not->toContain('storage_block')
        ->not->toContain('per_block')
        ->not->toContain('rented');
});
