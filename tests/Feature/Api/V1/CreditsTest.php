<?php

use App\Enums\WalletTransactionType;
use App\Jobs\RenewCredits;
use App\Models\CreditPurchase;
use App\Models\File;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;
use App\Support\PlanQuota;
use App\Support\Wallet;
use Illuminate\Support\Facades\Http;

it('prices blocks at 5000, and every block at 4500 once past 100 GB', function () {
    expect(Credits::rentFor(0, 25))->toBe(0)
        ->and(Credits::rentFor(1, 25))->toBe(5000)      // 30 GB
        ->and(Credits::rentFor(15, 25))->toBe(75_000)   // exactly 100 GB: still 5000
        ->and(Credits::rentFor(16, 25))->toBe(16 * 4500); // 105 GB: all at 4500
});

it('gives Free its welcome Credits once, when the username is claimed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/handles', ['name' => 'rani'])->assertSuccessful();
    Credits::welcome($user); // a second call changes nothing

    expect(Credits::balance($user))->toBe(Credits::FREE_WELCOME);
});

it('starts a custom whole-dollar top-up at the fixed USD rate', function () {
    Http::preventStrayRequests();
    Http::fake(['test-api.creem.io/*' => Http::response(['id' => 'ch_topup', 'checkout_url' => 'https://checkout.creem.io/test'])]);
    $user = User::factory()->create();

    foreach ([9, 1001, 10.50] as $dollars) {
        $this->actingAs($user)->postJson('/api/v1/credits/top-up', ['dollars' => $dollars])->assertUnprocessable();
    }
    $this->actingAs($user)->postJson('/api/v1/credits/top-up', ['dollars' => 37])
        ->assertCreated()->assertJsonPath('data.checkout_url', 'https://checkout.creem.io/test');

    $purchase = CreditPurchase::query()->sole();
    expect($purchase->amount)->toBe(3700)->and($purchase->credits)->toBe(370000);
    Http::assertSent(fn ($r) => $r['units'] === 37 && $r['product_id'] === 'prod_credits');
});

it('rents a block from Credits and raises the storage ceiling', function () {
    $user = User::factory()->create(); // Free: 2 GB
    Credits::grant($user, 6000, 'Test');

    $this->actingAs($user)->postJson('/api/v1/credits/storage-blocks', ['blocks' => 2])->assertUnprocessable();
    $this->actingAs($user)->postJson('/api/v1/credits/storage-blocks', ['blocks' => 1])
        ->assertOk()
        ->assertJsonPath('data.storage.blocks', 1)
        ->assertJsonPath('data.balance', 1000);

    expect(PlanQuota::storageLimit($user->fresh()))->toBe(7 * 1024 ** 3);
});

it('takes storage rent without granting expiring allowance as permanent Credits', function () {
    $paid = User::factory()->create(['storage_blocks' => 1]);
    Subscription::factory()->for($paid)->create();
    Credits::grant($paid, 6000, 'Test');

    $short = User::factory()->create(['storage_blocks' => 1]);
    Subscription::factory()->for($short)->create();
    Credits::grant($short, 3100, 'Test');
    File::factory()->for($short)->create(['size_bytes' => 27 * 1024 ** 3]);

    (new RenewCredits)->handle();
    (new RenewCredits)->handle(); // a retry charges nothing twice

    expect(Credits::balance($paid))->toBe(1000)
        ->and($paid->fresh()->storage_blocks)->toBe(1)
        ->and(Credits::balance($short))->toBe(3100)
        ->and($short->fresh()->storage_blocks)->toBe(0)
        ->and(PlanQuota::readOnly($short->fresh()))->toBeTrue();
});

it('converts old balances ×100 once', function () {
    $user = User::factory()->create();
    Wallet::credit($user, WalletTransactionType::CreditsTopUp, 500, wallet: Wallet::CREDITS);
    Credits::grant($user, 30, 'Welcome');
    $empty = User::factory()->create();

    $migration = require database_path('migrations/2026_10_17_120000_convert_credits_to_rupiah_scale.php');
    $migration->up();
    $migration->up(); // a re-run changes nothing

    expect(Credits::balance($user))->toBe(53_000)
        ->and(Credits::balance($empty))->toBe(0);
});

it('lists every movement', function () {
    $user = User::factory()->create();
    Credits::grant($user, 30, 'Welcome');

    $this->actingAs($user)->getJson('/api/v1/credits/ledger')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'credits_grant')
        ->assertJsonPath('data.0.amount', 30);
});
