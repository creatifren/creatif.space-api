<?php

use App\Enums\WalletTransactionType;
use App\Jobs\RenewMonthlyCredits;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\Credits;
use App\Support\Wallet;
use Illuminate\Support\Facades\Http;

/*
 * Monthly Credits (owner, 2026-11-09): the plan's credits_monthly per
 * cycle, expiring at its end; Top-up Credits never expire; sends spend
 * Monthly first; returns go back to the bucket they came from.
 */

beforeEach(function () {
    config([
        'services.social.provider' => 'postforme',
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
});

function monthlyPost(User $user, array $platforms, string $caption = 'Hi'): SocialPost
{
    $post = SocialPost::factory()->for($user)->create(['caption' => $caption, 'scheduled_at' => now()->addDay()]);
    foreach ($platforms as $platform) {
        $post->targets()->create([
            'social_account_id' => SocialAccount::factory()->for($user)->create(['platform' => $platform])->id,
            'status' => 'pending',
        ]);
    }

    return $post;
}

function setMonthly(string $plan, int $credits): void
{
    $p = Plan::query()->where('key', $plan)->firstOrFail();
    $p->update(['quotas' => [...$p->quotas, 'credits_monthly' => $credits]]);
}

it('grants each plan its Monthly Credits once a cycle, with a ledger row', function () {
    $free = User::factory()->create();
    $pro = User::factory()->create();
    Subscription::factory()->for($pro)->create();

    expect(Credits::monthlyBalance($free))->toBe(3000)
        ->and(Credits::monthlyBalance($free))->toBe(3000) // asked twice, granted once
        ->and(Credits::monthlyBalance($pro))->toBe(45000)
        ->and(Credits::balance($free))->toBe(0)
        ->and(WalletTransaction::query()->where('user_id', $free->id)->where('type', WalletTransactionType::CreditsMonthly)->count())->toBe(1);
});

it('expires the unused Monthly Credits at the cycle end, never Top-up', function () {
    $this->travelTo(now()->setDate(2026, 11, 10)->setTime(12, 0));
    $user = User::factory()->create(['created_at' => now()->setDate(2026, 1, 10)]);
    Credits::grant($user, 5000, 'Bought'); // Top-up
    $post = monthlyPost($user, ['instagram']);
    Credits::chargeSend($post);
    expect(Credits::monthlyBalance($user))->toBe(2700);

    $this->travelTo(now()->setDate(2026, 12, 10)->setTime(4, 0));
    (new RenewMonthlyCredits)->handle();
    (new RenewMonthlyCredits)->handle(); // a retry neither expires nor grants twice

    $expired = WalletTransaction::query()->where('user_id', $user->id)->where('type', WalletTransactionType::CreditsExpired)->sole();
    expect($expired->amount)->toBe(-2700)
        ->and($expired->wallet)->toBe(Wallet::MONTHLY)
        ->and(Credits::monthlyBalance($user))->toBe(3000)
        ->and(Credits::balance($user))->toBe(5000)
        ->and(Credits::cycleEnds($user)->toDateString())->toBe('2027-01-10');
});

it('spends Monthly first, then Top-up, splitting a destination across both', function () {
    setMonthly('free', 500);
    $user = User::factory()->create();
    Credits::grant($user, 1000, 'Bought');
    $post = monthlyPost($user, ['instagram', 'threads']);

    Credits::chargeSend($post);

    [$a, $b] = $post->targets()->orderBy('id')->get()->all();
    expect(Credits::monthlyBalance($user))->toBe(0)
        ->and(Credits::balance($user))->toBe(900)
        ->and([$a->paid_with, $a->charged_monthly, $a->charged_credits])->toBe(['monthly', 300, 0])
        ->and([$b->paid_with, $b->charged_monthly, $b->charged_credits])->toBe(['monthly', 200, 100])
        ->and(Credits::committed($user))->toMatchArray(['sends' => 100, 'sends_monthly' => 500]);
});

it('refuses without touching Monthly when Top-up cannot cover the rest', function () {
    setMonthly('free', 300);
    $user = User::factory()->create();
    $post = monthlyPost($user, ['instagram', 'threads']);

    expect(fn () => Credits::chargeSend($post))->toThrow(Illuminate\Validation\ValidationException::class);
    expect(Credits::monthlyBalance($user))->toBe(300)
        ->and($post->targets()->whereNotNull('paid_with')->count())->toBe(0);
});

it('returns each part to its own bucket, and only once', function () {
    setMonthly('free', 500);
    $user = User::factory()->create();
    Credits::grant($user, 1000, 'Bought');
    $post = monthlyPost($user, ['instagram', 'threads']);
    Credits::chargeSend($post);

    Credits::returnSend($post, $post->targets()->get(), 'Failed');
    Credits::returnSend($post, $post->targets()->get(), 'Failed again');

    expect(Credits::monthlyBalance($user))->toBe(500)
        ->and(Credits::balance($user))->toBe(1000);
});

it('does not bring back Monthly Credits from a cycle that has ended', function () {
    $this->travelTo(now()->setDate(2026, 11, 10)->setTime(12, 0));
    $user = User::factory()->create(['created_at' => now()->setDate(2026, 1, 10)]);
    $post = monthlyPost($user, ['instagram']);
    Credits::chargeSend($post);

    $this->travelTo(now()->setDate(2026, 12, 11));
    Credits::returnSend($post, $post->targets()->get(), 'Failed late');

    // The new cycle has its own 3000; last cycle's 300 had expired with it.
    expect(Credits::monthlyBalance($user))->toBe(3000);
});

it('gives back 300 for a legacy Top-up charge and nothing for a legacy allowance one', function () {
    $user = User::factory()->create();
    $post = monthlyPost($user, ['instagram', 'threads']);
    [$a, $b] = $post->targets()->orderBy('id')->get()->all();
    $a->update(['paid_with' => 'credits', 'charged_at' => now()]);
    $b->update(['paid_with' => 'allowance', 'charged_at' => now()]);

    Credits::returnSend($post, [$a, $b], 'Failed');

    expect(Credits::balance($user))->toBe(300);
});

it('tops the cycle up, not over, when a plan is bought mid-cycle', function () {
    $user = User::factory()->create();
    expect(Credits::monthlyBalance($user))->toBe(3000);

    Subscription::factory()->for($user)->create(['current_period_start' => now()->subDays(40)]);
    $pro = Credits::monthlyAmount($user);

    expect(Credits::monthlyBalance($user))->toBe($pro)
        ->and(Credits::monthlyBalance($user))->toBe($pro);
});

it('prices X by its caption: 300 plain, 2000 with a link, both configurable', function () {
    setMonthly('free', 0);
    $user = User::factory()->create();
    Credits::grant($user, 10_000, 'Bought');
    $plain = monthlyPost($user, ['x'], 'No link here, just Node.js and e.g. text');
    $linked = monthlyPost($user, ['x', 'instagram'], 'New drop: creatif.space/rani');

    Credits::chargeSend($plain);
    Credits::chargeSend($linked);

    expect(Credits::balance($user))->toBe(10_000 - 300 - 2000 - 300)
        ->and(Credits::hasLink('see https://x.com/a'))->toBeTrue()
        ->and(Credits::hasLink('www.example.org'))->toBeTrue()
        ->and(Credits::hasLink('3.5 stars, v2.0'))->toBeFalse();

    config(['services.social.per_send.x_link' => 1500, 'services.social.per_send.default' => 250]);
    expect(Credits::perSend('x', 'https://a.co'))->toBe(1500)
        ->and(Credits::perSend('instagram', 'https://a.co'))->toBe(250);
});

it('prices X by its own caption override', function () {
    setMonthly('free', 0);
    $user = User::factory()->create();
    Credits::grant($user, 10_000, 'Bought');
    $post = monthlyPost($user, ['x'], 'Plain');
    $post->targets()->update(['configuration' => ['caption' => 'with https://creatif.space']]);

    Credits::chargeSend($post);

    expect(Credits::balance($user))->toBe(8000);
});

it('refuses an X post with a link at the composer when only Monthly would not cover it', function () {
    Http::fake();
    setMonthly('free', 0);
    $user = User::factory()->create();
    Credits::grant($user, 1000, 'Bought');
    $x = SocialAccount::factory()->for($user)->create(['platform' => 'x']);

    $this->actingAs($user)
        ->postJson('/api/v1/social-posts', ['caption' => 'Shop at https://creatif.space', 'account_ids' => [$x->ulid]])
        ->assertUnprocessable();

    Http::assertNothingSent();
});

it('shows both buckets on /credits and /me/subscription', function () {
    $user = User::factory()->create();
    Credits::grant($user, 700, 'Bought');

    $this->actingAs($user)->getJson('/api/v1/credits')
        ->assertOk()
        ->assertJsonPath('data.monthly_balance', 3000)
        ->assertJsonPath('data.topup_balance', 700)
        ->assertJsonPath('data.balance', 700)
        ->assertJsonPath('data.total', 3700)
        ->assertJsonPath('data.monthly_resets_at', Credits::cycleEnds($user)->toIso8601String());

    $this->actingAs($user)->getJson('/api/v1/me/subscription')
        ->assertOk()
        ->assertJsonPath('data.monthly_balance', 3000)
        ->assertJsonPath('data.topup_balance', 700)
        ->assertJsonPath('data.total', 3700);

    $this->actingAs($user)->getJson('/api/v1/credits/ledger')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'credits_monthly')
        ->assertJsonPath('data.0.bucket', 'monthly');
});
