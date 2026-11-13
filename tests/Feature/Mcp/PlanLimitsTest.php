<?php

use App\Enums\WalletTransactionType;
use App\Http\Middleware\CapTokenSends;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\Credits;
use App\Support\Wallet;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * MCP is public and on every plan. Free is held by Credits and features,
 * enforced here — exercised through a real Free-plan API key.
 */

beforeEach(function () {
    ensurePassportKeys();
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
        'app.frontend_url' => 'https://creatif.space',
    ]);
    Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_pl', 'status' => 'scheduled'])]);
});

function planKey(TestCase $test, User $user, array $scopes = ['read', 'draft', 'approve', 'publish']): string
{
    $key = $test->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Plan limits key', 'scopes' => $scopes])
        ->assertCreated()
        ->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

function planDraft(User $user, array $platforms = ['instagram']): SocialPost
{
    $post = SocialPost::factory()->for($user)->create([
        'status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => null, 'caption' => 'Hello',
    ]);
    foreach ($platforms as $platform) {
        $account = SocialAccount::factory()->for($user)->create(['platform' => $platform]);
        $post->targets()->create(['social_account_id' => $account->id, 'status' => 'pending']);
    }

    return $post;
}

/** Spend every Monthly Credit this cycle has, through the ledger. */
function spendMonthly(User $user): void
{
    $left = Credits::monthlyBalance($user);
    if ($left > 0) {
        Wallet::debit($user, WalletTransactionType::Adjustment, $left, null, null, 'Test: used up', Wallet::MONTHLY);
    }
}

describe('GET /credits limits (get_credits)', function () {
    it('reads every limit from the plans table for a Free key', function () {
        $user = User::factory()->create(); // no subscription = Free
        $free = Plan::query()->where('key', 'free')->sole();
        SocialAccount::factory()->for($user)->create();

        $limits = bearer($this, planKey($this, $user, ['read']))
            ->getJson('/api/v1/credits')
            ->assertOk()
            ->json('data.limits');

        expect($limits['plan'])->toBe(['key' => 'free', 'name' => $free->name])
            ->and($limits['monthly_posts']['limit'])->toBe($free->quota('social_posts_monthly'))
            ->and($limits['monthly_posts']['used'])->toBe(0)
            ->and($limits['connected_accounts'])->toBe(['limit' => $free->quota('social_accounts'), 'used' => 1])
            ->and($limits['features']['approval_links'])->toBe($free->feature('client_approvals'))
            ->and($limits['features']['analytics'])->toBe($free->feature('full_analytics'))
            ->and($limits['credits']['monthly_amount'])->toBe(Credits::monthlyAmount($user))
            ->and($limits['credits']['total'])->toBe(Credits::total($user))
            ->and($limits['price_per_send']['default'])->toBe(Credits::perSend())
            ->and($limits['mcp_daily_sends'])->toBe(['cap' => CapTokenSends::DAILY_CAP, 'used' => 0, 'remaining' => CapTokenSends::DAILY_CAP])
            ->and($limits['urls']['top_up'])->toBe('https://creatif.space/credits');
    });

    it('follows the plan row, not code: a changed quota shows at once', function () {
        $user = User::factory()->create();
        $free = Plan::query()->where('key', 'free')->sole();
        $free->update(['quotas' => [...$free->quotas, 'social_accounts' => 7]]);

        bearer($this, planKey($this, $user, ['read']))
            ->getJson('/api/v1/credits')
            ->assertJsonPath('data.limits.connected_accounts.limit', 7);
    });

    it('shows a Pro plan with approval links on', function () {
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create();

        bearer($this, planKey($this, $user, ['read']))
            ->getJson('/api/v1/credits')
            ->assertJsonPath('data.limits.features.approval_links', true)
            ->assertJsonPath('data.limits.connected_accounts.limit', null);
    });
});

describe('Free plan through a real API key', function () {
    it('makes a Free approval link for the first client, and refuses a second client with 402 approval_clients', function () {
        $user = User::factory()->create();
        $first = planDraft($user);
        $second = planDraft($user);
        $key = planKey($this, $user);

        bearer($this, $key)
            ->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [$first->ulid], 'client_name' => 'Acme'])
            ->assertCreated();

        bearer($this, $key)
            ->postJson('/api/v1/social-approval-links', ['approver_email' => 'globex@example.test', 'post_ids' => [$second->ulid], 'client_name' => 'Globex'])
            ->assertStatus(402)
            ->assertJsonPath('reason', 'plan_required')
            ->assertJsonPath('code', 'plan_required')
            ->assertJsonPath('feature', 'approval_clients')
            ->assertJsonPath('required_plan', 'pro')
            ->assertJsonPath('limit', 1)
            ->assertJsonPath('message', 'On Free, approval links are for one client at a time. Approval for more clients is on Pro and Max. Upgrade: https://creatif.space/settings?section=subscription')
            ->assertJsonPath('billing_url', 'https://creatif.space/settings?section=subscription');

        expect($second->fresh()->status->value)->toBe('draft');
    });

    it('answers the app (cookie session) with the same 402 shape', function () {
        $user = User::factory()->create();
        $first = planDraft($user);
        $second = planDraft($user);

        $this->actingAs($user)
            ->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [$first->ulid]])
            ->assertCreated();
        $this->actingAs($user)
            ->postJson('/api/v1/social-approval-links', ['approver_email' => 'second@example.test', 'post_ids' => [$second->ulid]])
            ->assertStatus(402)
            ->assertJsonPath('reason', 'plan_required')
            ->assertJsonPath('feature', 'approval_clients')
            ->assertJsonStructure(['message', 'reason', 'feature', 'required_plan', 'limit', 'errors' => ['approver_email']]);
    });

    it('refuses a send it cannot pay for: insufficient_credits, needed vs available, no debit', function () {
        $user = User::factory()->create();
        spendMonthly($user);
        Credits::grant($user, 1, 'Test: one Credit');
        $post = planDraft($user, ['instagram', 'x']);
        $key = planKey($this, $user);
        $ledger = WalletTransaction::query()->count();

        $preview = bearer($this, $key)->getJson("/api/v1/social-posts/{$post->ulid}/send-preview")
            ->assertOk()
            ->assertJsonPath('data.credits.can_afford', false)
            ->json('data');
        $price = $preview['credits']['price'];

        bearer($this, $key)->postJson("/api/v1/social-posts/{$post->ulid}/send", ['confirm_token' => $preview['confirm_token']])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'insufficient_credits')
            ->assertJsonPath('needed', $price)
            ->assertJsonPath('available', 1)
            ->assertJsonPath('topup_url', 'https://creatif.space/credits')
            ->assertJsonPath('message', "Not enough Credits: this send needs {$price} and 1 are available (0 monthly + 1 top-up). Top up: https://creatif.space/credits");

        expect(WalletTransaction::query()->count())->toBe($ledger)
            ->and(Credits::total($user))->toBe(1)
            ->and($post->fresh()->status->value)->toBe('draft')
            ->and(CapTokenSends::status($user)['used'])->toBe(0);
        Http::assertNothingSent();
    });

    it('says quota_exceeded with the reset date once the month is spent and there is no top-up', function () {
        $user = User::factory()->create();
        spendMonthly($user);
        $post = planDraft($user);
        $resets = Credits::cycleEnds($user);

        $key = planKey($this, $user);
        $token = bearer($this, $key)->getJson("/api/v1/social-posts/{$post->ulid}/send-preview")->json('data.confirm_token');

        bearer($this, $key)->postJson("/api/v1/social-posts/{$post->ulid}/send", ['confirm_token' => $token])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'quota_exceeded')
            ->assertJsonPath('resets_at', $resets->toIso8601String())
            ->assertJsonPath('available', 0);

        expect($post->fresh()->status->value)->toBe('draft');
        Http::assertNothingSent();
    });

    it('debits exactly the preview\'s cost on a successful send', function () {
        $user = User::factory()->create();
        Credits::grant($user, 100_000, 'Test');
        $post = planDraft($user, ['instagram', 'x', 'linkedin']);
        $key = planKey($this, $user);

        $preview = bearer($this, $key)->getJson("/api/v1/social-posts/{$post->ulid}/send-preview")
            ->assertJsonPath('data.credits.can_afford', true)
            ->json('data');
        $price = $preview['credits']['price'];
        $before = Credits::total($user);
        $lastId = (int) WalletTransaction::query()->max('id');

        bearer($this, $key)->postJson("/api/v1/social-posts/{$post->ulid}/send", ['confirm_token' => $preview['confirm_token']])->assertOk();

        $debited = -1 * (int) WalletTransaction::query()->where('id', '>', $lastId)->where('user_id', $user->id)->sum('amount');
        expect($price)->toBeGreaterThan(0)
            ->and($debited)->toBe($price)
            ->and(Credits::total($user))->toBe($before - $price);
    });
});
