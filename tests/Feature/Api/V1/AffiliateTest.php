<?php

use App\Enums\AffiliateStatus;
use App\Enums\CommissionStatus;
use App\Enums\WalletTransactionType;
use App\Jobs\ReleaseCommissions;
use App\Models\Affiliate;
use App\Models\Commission;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Support\CommissionEngine;
use App\Support\ReferralAttribution;
use App\Support\Wallet;

/** An approved affiliate, ready to be referred through. */
function approvedAffiliate(array $attributes = []): Affiliate
{
    return Affiliate::factory()->approved()->create($attributes);
}

/**
 * A paid subscription invoice belonging to $customer — the event the whole
 * commission engine hangs off.
 */
function paidInvoiceFor(User $customer, int $amount = 94_000): Invoice
{
    $subscription = Subscription::factory()->for($customer)->create([
        'plan_id' => Plan::query()->where('key', 'premium')->value('id'),
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
    ]);

    return Invoice::factory()->for($subscription)->create(['amount' => $amount]);
}

describe('the wallet split', function () {
    it('keeps sales and commission apart in both directions', function () {
        $user = User::factory()->create();

        Wallet::credit($user, WalletTransactionType::SaleCredit, 100_000);
        Wallet::credit($user, WalletTransactionType::CommissionCredit, 25_000, wallet: Wallet::AFFILIATE);

        // The promise is a separate balance, not a separate system: one
        // ledger, scoped by wallet.
        expect(Wallet::balance($user))->toBe(100_000)
            ->and(Wallet::balance($user, Wallet::AFFILIATE))->toBe(25_000);
    });

    it('will not let one balance overdraw the other', function () {
        $user = User::factory()->create();
        Wallet::credit($user, WalletTransactionType::SaleCredit, 100_000);

        expect(fn () => Wallet::debit(
            $user,
            WalletTransactionType::WithdrawalDebit,
            50_000,
            wallet: Wallet::AFFILIATE,
        ))->toThrow(RuntimeException::class);
    });

    it('pays out each balance separately, and one queue does not block the other', function () {
        $user = User::factory()->create();
        Wallet::credit($user, WalletTransactionType::SaleCredit, 200_000);
        Wallet::credit($user, WalletTransactionType::CommissionCredit, 200_000, wallet: Wallet::AFFILIATE);

        $bank = ['bank_code' => 'BCA', 'account_number' => '4471', 'account_name' => 'Rani'];

        $this->actingAs($user)
            ->postJson('/api/v1/me/withdrawals', $bank + ['amount' => 50_000])
            ->assertCreated();

        // A sales payout in flight must not stop an affiliate payout.
        $this->actingAs($user)
            ->postJson('/api/v1/me/withdrawals', $bank + ['amount' => 50_000, 'wallet' => 'affiliate'])
            ->assertCreated();

        // A second one on the same wallet is still refused.
        $this->actingAs($user)
            ->postJson('/api/v1/me/withdrawals', $bank + ['amount' => 50_000])
            ->assertUnprocessable();

        expect(Wallet::balance($user))->toBe(150_000)
            ->and(Wallet::balance($user, Wallet::AFFILIATE))->toBe(150_000)
            ->and(Withdrawal::query()->where('wallet', 'affiliate')->count())->toBe(1);
    });
});

describe('attribution', function () {
    it('records the signup against the affiliate who sent them', function () {
        $affiliate = approvedAffiliate(['code' => 'RANI']);
        $newcomer = User::factory()->create();

        ReferralAttribution::attach($newcomer, 'RANI');

        $referral = Referral::query()->sole();
        expect($referral->affiliate_id)->toBe($affiliate->id)
            ->and($referral->referred_user_id)->toBe($newcomer->id)
            ->and($referral->cookie_expires_at->isFuture())->toBeTrue();
    });

    it('refuses a self-referral', function () {
        $affiliate = approvedAffiliate(['code' => 'RANI']);

        ReferralAttribution::attach($affiliate->user, 'RANI');

        expect(Referral::query()->count())->toBe(0);
    });

    it('ignores an unknown code and an affiliate who is not approved yet', function () {
        $pending = Affiliate::factory()->create(['code' => 'WAITING']);

        ReferralAttribution::attach(User::factory()->create(), 'NOSUCHCODE');
        ReferralAttribution::attach(User::factory()->create(), 'WAITING');

        expect(Referral::query()->count())->toBe(0)
            ->and($pending->status)->toBe(AffiliateStatus::Applied);
    });

    it('does not attribute the same person twice', function () {
        approvedAffiliate(['code' => 'ONE']);
        approvedAffiliate(['code' => 'TWO']);
        $newcomer = User::factory()->create();

        ReferralAttribution::attach($newcomer, 'ONE');
        ReferralAttribution::attach($newcomer, 'TWO');

        expect(Referral::query()->count())->toBe(1);
    });

    it('counts a click before anybody signs up', function () {
        $affiliate = approvedAffiliate(['code' => 'RANI']);

        $this->postJson('/api/v1/referrals/RANI/click')->assertNoContent();
        // An unknown code answers the same way — a stranger must not learn
        // which codes exist.
        $this->postJson('/api/v1/referrals/NOPE/click')->assertNoContent();

        $referral = Referral::query()->sole();
        expect($referral->affiliate_id)->toBe($affiliate->id)
            ->and($referral->referred_user_id)->toBeNull();
    });
});

describe('earning', function () {
    it('writes twelve parts that add back to exactly the commission', function () {
        $affiliate = approvedAffiliate();
        $customer = User::factory()->create();
        Referral::factory()->for($affiliate)->create(['referred_user_id' => $customer->id]);

        CommissionEngine::forInvoice(paidInvoiceFor($customer, 94_000));

        $rows = Commission::query()->orderBy('installment_no')->get();

        // 20% of 94.000 = 18.800, split twelve ways — the remainder rides
        // on the first part rather than evaporating.
        expect($rows)->toHaveCount(12)
            ->and($rows->sum('amount'))->toBe(18_800)
            ->and($rows->first()->amount)->toBeGreaterThan($rows->last()->amount);
    });

    it('holds each part thirty days from the payment, a month apart', function () {
        $affiliate = approvedAffiliate();
        $customer = User::factory()->create();
        Referral::factory()->for($affiliate)->create(['referred_user_id' => $customer->id]);

        CommissionEngine::forInvoice(paidInvoiceFor($customer));

        $rows = Commission::query()->orderBy('installment_no')->get();

        expect($rows->first()->hold_until->toDateString())
            ->toBe(now()->addDays(30)->toDateString())
            ->and($rows->last()->hold_until->toDateString())
            ->toBe(now()->addDays(30)->addMonthsNoOverflow(11)->toDateString());
    });

    it('earns nothing for an affiliate who is not approved', function () {
        $affiliate = Affiliate::factory()->create();
        $customer = User::factory()->create();
        Referral::factory()->for($affiliate)->create(['referred_user_id' => $customer->id]);

        CommissionEngine::forInvoice(paidInvoiceFor($customer));

        expect(Commission::query()->count())->toBe(0);
    });

    it('earns nothing on a renewal past the twelve-month window', function () {
        $affiliate = approvedAffiliate();
        $customer = User::factory()->create();
        Referral::factory()->for($affiliate)->create([
            'referred_user_id' => $customer->id,
            // Their first payment was over a year ago.
            'first_paid_at' => now()->subMonths(13),
        ]);

        CommissionEngine::forInvoice(paidInvoiceFor($customer));

        expect(Commission::query()->count())->toBe(0);
    });

    it('earns nothing when nobody referred the customer', function () {
        CommissionEngine::forInvoice(paidInvoiceFor(User::factory()->create()));

        expect(Commission::query()->count())->toBe(0);
    });
});

describe('the tier', function () {
    it('moves up at ten paid referrals and again at twenty-five', function () {
        expect(Affiliate::tierFor(0))->toBe(20)
            ->and(Affiliate::tierFor(9))->toBe(20)
            ->and(Affiliate::tierFor(10))->toBe(25)
            ->and(Affiliate::tierFor(24))->toBe(25)
            ->and(Affiliate::tierFor(25))->toBe(30);
    });

    it('rises on the tenth customer’s first payment', function () {
        $affiliate = approvedAffiliate();
        $affiliate->forceFill(['paid_referrals_count' => 9])->save();

        $customer = User::factory()->create();
        Referral::factory()->for($affiliate)->create(['referred_user_id' => $customer->id]);

        CommissionEngine::forInvoice(paidInvoiceFor($customer));

        expect((float) $affiliate->fresh()->tier_percent)->toBe(25.0)
            // And the rate that was in force is what got written down.
            ->and((float) Commission::query()->first()->tier_percent)->toBe(25.0);
    });

    it('keeps the old rate on commission already earned', function () {
        $affiliate = approvedAffiliate();
        $customer = User::factory()->create();
        Referral::factory()->for($affiliate)->create([
            'referred_user_id' => $customer->id,
            'first_paid_at' => now()->subMonth(),
        ]);

        CommissionEngine::forInvoice(paidInvoiceFor($customer));

        // The affiliate climbs a tier afterwards.
        $affiliate->forceFill([
            'paid_referrals_count' => 25,
            'tier_percent' => '30.00',
        ])->save();

        // Integrity rule §2: last month is not repriced by this month.
        expect((float) Commission::query()->first()->tier_percent)->toBe(20.0);
    });
});

describe('release', function () {
    it('credits the affiliate balance once, and never the sales one', function () {
        $affiliate = approvedAffiliate();
        $referral = Referral::factory()->for($affiliate)->create();
        Commission::factory()->for($referral)->due()->create(['amount' => 5_000]);

        (new ReleaseCommissions)->handle();
        (new ReleaseCommissions)->handle();

        $user = $affiliate->user;
        expect(Wallet::balance($user, Wallet::AFFILIATE))->toBe(5_000)
            ->and(Wallet::balance($user))->toBe(0)
            ->and(WalletTransaction::query()->count())->toBe(1)
            ->and(Commission::query()->first()->status)->toBe(CommissionStatus::Released);
    });

    it('leaves money that is still holding alone', function () {
        $referral = Referral::factory()->create();
        Commission::factory()->for($referral)->create(['amount' => 5_000]);

        (new ReleaseCommissions)->handle();

        expect(Commission::query()->first()->status)->toBe(CommissionStatus::Holding)
            ->and(WalletTransaction::query()->count())->toBe(0);
    });

    it('reverses what has not been paid out when an invoice is refunded', function () {
        $affiliate = approvedAffiliate();
        $customer = User::factory()->create();
        Referral::factory()->for($affiliate)->create(['referred_user_id' => $customer->id]);
        $invoice = paidInvoiceFor($customer);

        CommissionEngine::forInvoice($invoice);
        CommissionEngine::reverseForInvoice($invoice);

        // No ledger line either way: the money never landed.
        expect(Commission::query()->where('status', CommissionStatus::Reversed)->count())->toBe(12)
            ->and(WalletTransaction::query()->count())->toBe(0);
    });
});

describe('the dashboard', function () {
    it('404s for somebody who never applied — the sidebar is not the gate', function () {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/me/affiliate')
            ->assertNotFound();
    });

    it('reports the funnel, the tier, and what is still holding', function () {
        $affiliate = approvedAffiliate();
        Referral::factory()->for($affiliate)->clickOnly()->create();
        $signed = Referral::factory()->for($affiliate)->create();
        $paying = Referral::factory()->for($affiliate)->paying()->create();
        Commission::factory()->for($paying)->create(['amount' => 3_000]);

        $data = $this->actingAs($affiliate->user)
            ->getJson('/api/v1/me/affiliate')
            ->assertOk()
            ->json('data');

        expect($data['funnel'])->toBe(['clicks' => 3, 'signed_up' => 2, 'paid' => 1])
            ->and($data['balance']['holding'])->toBe(3_000)
            ->and($data['balance']['available'])->toBe(0)
            ->and((float) $data['affiliate']['tier_percent'])->toBe(20.0)
            ->and($data['affiliate']['next_tier'])->toBe(['percent' => 25, 'needed' => 10])
            ->and($data['history'])->toHaveCount(1)
            ->and($signed->referred_user_id)->not->toBeNull();
    });

    it('takes an application once, with a code issued straight away', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/affiliates/apply', [
                'link' => 'https://instagram.com/rani',
                'audience' => 'Photographers in Bandung.',
            ])
            ->assertCreated();

        // The code exists from the start so the approval email can carry
        // the link the person is waiting for.
        expect($response->json('data.code'))->not->toBeEmpty()
            ->and($response->json('data.status'))->toBe('applied');

        $this->actingAs($user)
            ->postJson('/api/v1/affiliates/apply', [
                'link' => 'https://instagram.com/rani',
                'audience' => 'Again.',
            ])
            ->assertUnprocessable();
    });

    it('needs a signed-in creator', function () {
        $this->getJson('/api/v1/me/affiliate')->assertUnauthorized();
        $this->postJson('/api/v1/affiliates/apply', [])->assertUnauthorized();
    });
});
