<?php

use App\Enums\WalletTransactionType;
use App\Models\CreditPurchase;
use App\Models\User;
use App\Support\Credits;
use App\Support\Wallet;
use Illuminate\Support\Facades\Http;

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
