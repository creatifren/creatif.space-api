<?php

use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\Credits;
use App\Support\Wallet;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * get_credits' spend block: GET /api/v1/credits → limits.spend, this
 * cycle's Credits by action (publish, reply, ai), returns netted, both
 * buckets, the previous cycle left out.
 */

beforeEach(function () {
    ensurePassportKeys();
    Http::fake();
});

function spendKey(TestCase $test, User $user): string
{
    $key = $test->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Spend key', 'scopes' => ['read']])
        ->assertCreated()
        ->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

it('sums this cycle’s sends and replies, nets returns, and leaves the last cycle out', function () {
    $user = User::factory()->create();
    Credits::grant($user, 50_000, 'Test top-up');
    Wallet::credit($user, WalletTransactionType::CreditsMonthly, 3_000, null, null, 'Test monthly', Wallet::MONTHLY);

    Wallet::debit($user, WalletTransactionType::CreditsSend, 900, null, null, 'Send', Wallet::CREDITS);
    Wallet::debit($user, WalletTransactionType::CreditsSend, 600, null, null, 'Send · Monthly Credits', Wallet::MONTHLY);
    Wallet::credit($user, WalletTransactionType::CreditsSendReturn, 300, null, null, 'Failed destination', Wallet::CREDITS);
    Wallet::debit($user, WalletTransactionType::CreditsReply, 20, null, null, 'Reply', Wallet::CREDITS);

    $old = Wallet::debit($user, WalletTransactionType::CreditsSend, 5_000, null, null, 'Last cycle', Wallet::CREDITS);
    WalletTransaction::query()->whereKey($old->id)->update(['created_at' => Credits::cycleSince($user)->subDay()]);

    expect(Credits::spendThisCycle($user))->toBe(['publish' => 1_200, 'reply' => 20, 'ai' => 0, 'total' => 1_220]);

    bearer($this, spendKey($this, $user))->getJson('/api/v1/credits')
        ->assertOk()
        ->assertJsonPath('data.limits.spend.by_action', ['publish' => 1_200, 'reply' => 20, 'ai' => 0, 'total' => 1_220])
        ->assertJsonPath('data.limits.spend.since', Credits::cycleSince($user)->toIso8601String());
});

it('is all zeros with nothing spent, and never counts another workspace', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Credits::grant($other, 10_000, 'Test top-up');
    Wallet::debit($other, WalletTransactionType::CreditsSend, 300, null, null, 'Send', Wallet::CREDITS);

    expect(Credits::spendThisCycle($user))->toBe(['publish' => 0, 'reply' => 0, 'ai' => 0, 'total' => 0]);
});
