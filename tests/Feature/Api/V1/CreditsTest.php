<?php

use App\Jobs\RenewCredits;
use App\Models\CreditPurchase;
use App\Models\File;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;
use App\Support\PlanQuota;
use Illuminate\Support\Facades\Http;

it('prices blocks at 50, and every block at 45 once past 100 GB', function () {
    expect(Credits::rentFor(0, 25))->toBe(0)
        ->and(Credits::rentFor(1, 25))->toBe(50)        // 30 GB
        ->and(Credits::rentFor(15, 25))->toBe(750)      // exactly 100 GB: still 50
        ->and(Credits::rentFor(16, 25))->toBe(16 * 45); // 105 GB: all at 45
});

it('gives Free its thirty once, when the username is claimed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/handles', ['name' => 'rani'])->assertSuccessful();
    Credits::welcome($user); // a second call changes nothing

    expect(Credits::balance($user))->toBe(30);
});

it('lands a paid top-up once, however often Midtrans retries', function () {
    config(['services.midtrans.server_key' => 'sk']);
    $user = User::factory()->create();
    $purchase = CreditPurchase::create(['user_id' => $user->id, 'credits' => 500, 'amount' => 50_000, 'status' => 'pending']);

    $payload = [
        'order_id' => $purchase->midtrans_order_id,
        'status_code' => '200',
        'gross_amount' => '50000.00',
        'transaction_status' => 'settlement',
        'payment_type' => 'qris',
    ];
    $payload['signature_key'] = hash('sha512', $payload['order_id'].'200'.'50000.00'.'sk');

    $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();
    $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();

    expect(Credits::balance($user))->toBe(500)
        ->and($purchase->fresh()->status)->toBe('paid');
});

it('starts a top-up only for an offered pack', function () {
    Http::fake(['*' => Http::response(['token' => 'snap-tok'])]);
    config(['services.midtrans.server_key' => 'sk']);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/credits/top-up', ['credits' => 77])->assertUnprocessable();
    $this->actingAs($user)->postJson('/api/v1/credits/top-up', ['credits' => 500])
        ->assertCreated()
        ->assertJsonPath('data.snap_token', 'snap-tok');

    expect(CreditPurchase::query()->first()->amount)->toBe(500);
});

it('rents a block from Credits and raises the storage ceiling', function () {
    $user = User::factory()->create(); // Free: 2 GB
    Credits::grant($user, 60, 'Test');

    $this->actingAs($user)->postJson('/api/v1/credits/storage-blocks', ['blocks' => 2])->assertUnprocessable();
    $this->actingAs($user)->postJson('/api/v1/credits/storage-blocks', ['blocks' => 1])
        ->assertOk()
        ->assertJsonPath('data.storage.blocks', 1)
        ->assertJsonPath('data.balance', 10);

    expect(PlanQuota::storageLimit($user->fresh()))->toBe(7 * 1024 ** 3);
});

it('takes rent before the plan Credits on the first, and lapses what it cannot pay', function () {
    $paid = User::factory()->create(['storage_blocks' => 1]);
    Subscription::factory()->for($paid)->create();
    Credits::grant($paid, 60, 'Test');

    $short = User::factory()->create(['storage_blocks' => 1]);
    Subscription::factory()->for($short)->create();
    Credits::grant($short, 31, 'Test');
    File::factory()->for($short)->create(['size_bytes' => 27 * 1024 ** 3]);

    (new RenewCredits)->handle();
    (new RenewCredits)->handle(); // a retry charges nothing twice

    expect(Credits::balance($paid))->toBe(60 - 50 + 120)
        ->and($paid->fresh()->storage_blocks)->toBe(1)
        // Rent first: 31 could not pay 50, so the block lapsed — then 120 landed.
        ->and(Credits::balance($short))->toBe(31 + 120)
        ->and($short->fresh()->storage_blocks)->toBe(0)
        ->and(PlanQuota::readOnly($short->fresh()))->toBeTrue();
});

it('lists every movement', function () {
    $user = User::factory()->create();
    Credits::grant($user, 30, 'Welcome');

    $this->actingAs($user)->getJson('/api/v1/credits/ledger')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'credits_grant')
        ->assertJsonPath('data.0.amount', 30);
});
