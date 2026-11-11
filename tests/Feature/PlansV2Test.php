<?php

use App\Exceptions\PlanFeatureRequired;
use App\Models\EnterpriseLead;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Space;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\EnterpriseContactReceived;
use App\Support\Credits;
use App\Support\PlanQuota;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/*
 * Plans v2 (founder, 11 Nov 2026): Free / Pro (7-day trial = Pro) / Max /
 * Enterprise (contact sales). One plan definition (PlanSeeder), enforced in
 * PlanQuota, one 402 shape: {message, reason, feature, required_plan, limit?}.
 */

const V2_BILLING = 'https://creatif.space/settings?section=subscription';

beforeEach(function () {
    // A fixed clock: the window's boundary is to the second.
    Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'UTC'));
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
        'app.frontend_url' => 'https://creatif.space',
        'services.ai' => ['key' => 'k', 'base_url' => 'https://ai.test/v1/', 'model' => 'm'],
    ]);
    Http::fake([
        'api.postforme.test/*' => Http::response(['id' => 'post_v2', 'status' => 'scheduled']),
        'ai.test/*' => Http::response(['choices' => [['message' => ['content' => 'Better caption']]]]),
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function v2Free(): User
{
    $user = User::factory()->create();
    Credits::grant($user, 100_000, 'Test');

    return $user;
}

function v2Pro(string $status = 'active'): User
{
    $user = v2Free();
    Subscription::factory()->for($user)->create(['status' => $status]);

    return $user;
}

function v2Account(User $user): SocialAccount
{
    return SocialAccount::factory()->for($user)->create(['platform' => 'linkedin']);
}

/** Posts already in the queue, made directly (not through the limit). */
function v2Queue(User $user, int $n, array $attrs = []): void
{
    SocialPost::factory()->count($n)->for($user)->create($attrs + [
        'status' => 'scheduled', 'scheduled_at' => now()->addDays(2), 'approval' => null,
    ]);
}

function v2Draft(User $user, array $attrs = []): SocialPost
{
    $post = SocialPost::factory()->for($user)->create($attrs + [
        'status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => null, 'caption' => 'Draft',
    ]);
    $post->targets()->create(['social_account_id' => v2Account($user)->id, 'status' => 'pending']);

    return $post;
}

/** The one 402 shape, for any feature. */
function v2Assert402($response, string $feature, ?int $limit = null): void
{
    $response->assertStatus(402)
        ->assertJsonPath('reason', 'plan_required')
        ->assertJsonPath('code', 'plan_required')
        ->assertJsonPath('feature', $feature)
        ->assertJsonPath('required_plan', 'pro')
        ->assertJsonPath('billing_url', V2_BILLING);
    expect($response->json('message'))->toBeString()->toContain(V2_BILLING);
    $limit === null
        ? expect($response->json())->not->toHaveKey('limit')
        : $response->assertJsonPath('limit', $limit);
}

function v2Schedule(TestCase $test, User $user, string $at, array $extra = [])
{
    return $test->actingAs($user)->postJson('/api/v1/social-posts', [
        'caption' => 'Hello', 'account_ids' => [v2Account($user)->ulid], 'scheduled_at' => $at,
    ] + $extra);
}

function v2ApiKey(TestCase $test, User $user, array $scopes = ['read', 'draft', 'approve', 'publish']): string
{
    ensurePassportKeys();
    $key = $test->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'v2', 'scopes' => $scopes])
        ->assertCreated()
        ->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

describe('rule 1 — Free scheduling window (7 days, UTC)', function () {
    it('accepts exactly 7 days ahead and refuses 7 days + 1 minute', function () {
        $user = v2Free();

        v2Schedule($this, $user, now()->addDays(7)->toIso8601String())->assertCreated();
        v2Assert402(v2Schedule($this, $user, now()->addDays(7)->addMinute()->toIso8601String()), 'schedule_window', 7);

        expect(SocialPost::query()->count())->toBe(1);
    });

    it('compares in UTC whatever offset the time is written in', function () {
        $user = v2Free();
        $edge = now()->addDays(7)->setTimezone('Asia/Jakarta'); // 17:00+07:00

        v2Schedule($this, $user, $edge->toIso8601String())->assertCreated();
        v2Assert402(v2Schedule($this, $user, $edge->addMinute()->toIso8601String()), 'schedule_window', 7);
    });

    it('applies to sending a draft, moving a queued post, and the send preview', function () {
        $user = v2Free();
        $far = now()->addDays(8)->toIso8601String();

        $draft = v2Draft($user);
        v2Assert402($this->actingAs($user)->postJson("/api/v1/social-posts/{$draft->ulid}/send", ['scheduled_at' => $far]), 'schedule_window', 7);
        v2Assert402($this->actingAs($user)->getJson("/api/v1/social-posts/{$draft->ulid}/send-preview?scheduled_at=".urlencode($far)), 'schedule_window', 7);
        expect($draft->fresh()->status->value)->toBe('draft');

        $queued = SocialPost::factory()->for($user)->create(['status' => 'scheduled', 'approval' => 'awaiting', 'provider_post_id' => null, 'scheduled_at' => now()->addDay()]);
        v2Assert402($this->actingAs($user)->patchJson("/api/v1/social-posts/{$queued->ulid}", ['scheduled_at' => $far]), 'schedule_window', 7);
        $this->actingAs($user)->patchJson("/api/v1/social-posts/{$queued->ulid}", ['scheduled_at' => now()->addDays(7)->toIso8601String()])->assertOk();
    });

    it('lets Pro, Max and a Pro trial schedule further out', function (string $plan, string $status) {
        $user = v2Free();
        Subscription::factory()->for($user)->create([
            'status' => $status,
            'plan_id' => Plan::query()->where('key', $plan)->value('id'),
        ]);

        expect($user->plan()->key->value)->toBe($plan);
        v2Schedule($this, $user, now()->addDays(60)->toIso8601String())->assertCreated();
    })->with([
        'pro' => ['pro', 'active'],
        'max' => ['max', 'active'],
        'trial = pro' => ['pro', 'trialing'],
    ]);
});

describe('rule 1 — Free queue (10 scheduled + waiting for approval)', function () {
    it('takes the 10th queued post and refuses the 11th', function () {
        $user = v2Free();
        v2Queue($user, 9);

        v2Schedule($this, $user, now()->addDay()->toIso8601String())->assertCreated();
        expect(PlanQuota::queuedPosts($user))->toBe(10);

        v2Assert402(v2Schedule($this, $user, now()->addDay()->toIso8601String()), 'queue_limit', 10);
        expect(PlanQuota::queuedPosts($user))->toBe(10);
    });

    it('counts posts waiting for approval, not drafts, published, failed, cancelled or past slots', function () {
        $user = v2Free();
        v2Queue($user, 4);
        v2Queue($user, 3, ['approval' => 'client', 'scheduled_at' => null, 'provider_post_id' => null]);
        v2Queue($user, 1, ['approval' => 'revision', 'scheduled_at' => null, 'provider_post_id' => null]);
        SocialPost::factory()->count(5)->for($user)->create(['status' => 'draft', 'scheduled_at' => now()->addDay()]);
        SocialPost::factory()->count(5)->for($user)->published()->create();
        SocialPost::factory()->count(2)->for($user)->create(['status' => 'failed']);
        SocialPost::factory()->count(2)->for($user)->create(['status' => 'cancelled']);
        // Sent now, still on its way: not queued.
        SocialPost::factory()->for($user)->create(['status' => 'scheduled', 'scheduled_at' => null, 'approval' => null]);

        expect(PlanQuota::queuedPosts($user))->toBe(8);
    });

    it('counts the whole workspace and refuses holding a post for approval past the limit', function () {
        $user = v2Free();
        v2Queue($user, 10);

        v2Assert402(v2Schedule($this, $user, now()->addDay()->toIso8601String()), 'queue_limit', 10);
        v2Assert402($this->actingAs($user)->postJson('/api/v1/social-posts', [
            'caption' => 'Hi', 'account_ids' => [v2Account($user)->ulid], 'approval' => 'awaiting',
        ]), 'queue_limit', 10);
        $draft = v2Draft($user);
        v2Assert402($this->actingAs($user)->postJson("/api/v1/social-posts/{$draft->ulid}/send", ['approval' => 'client']), 'queue_limit', 10);

        // A draft is only saved, and publishing now joins no queue.
        $this->actingAs($user)->postJson('/api/v1/social-posts', ['caption' => 'Hi', 'account_ids' => [v2Account($user)->ulid], 'draft' => true])->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/social-posts', ['caption' => 'Now', 'account_ids' => [v2Account($user)->ulid]])->assertCreated();
    });

    it('keeps existing posts past the limit and the window as they are', function () {
        $user = v2Free();
        v2Queue($user, 12, ['scheduled_at' => now()->addDays(30)]);

        $this->actingAs($user)->getJson('/api/v1/social-posts')->assertOk();
        expect(SocialPost::query()->where('status', 'scheduled')->count())->toBe(12);
        v2Assert402(v2Schedule($this, $user, now()->addDay()->toIso8601String()), 'queue_limit', 10);
    });

    it('has no queue limit on Pro or a Pro trial', function (string $status) {
        $user = v2Pro($status);
        v2Queue($user, 25);

        v2Schedule($this, $user, now()->addDay()->toIso8601String())->assertCreated();
    })->with(['active', 'trialing']);
});

describe('rule 2 — approval on Free', function () {
    it('allows the first client and refuses a second', function () {
        $user = v2Free();
        $a = v2Draft($user);
        $b = v2Draft($user);
        $c = v2Draft($user);

        $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => 'acme@client.test', 'post_ids' => [$a->ulid], 'client_name' => 'Acme'])->assertCreated();
        // The same approver again (any case / spacing) is still one client,
        // whatever the link calls them.
        $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => '  ACME@Client.test ', 'post_ids' => [$b->ulid], 'client_name' => 'Acme marketing'])->assertCreated();

        v2Assert402($this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => 'globex@client.test', 'post_ids' => [$c->ulid], 'client_name' => 'Globex']), 'approval_clients', 1);
        // A different address is a different client, even under the same name.
        v2Assert402($this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => 'someone@else.test', 'post_ids' => [$c->ulid], 'client_name' => 'Acme']), 'approval_clients', 1);
        expect($c->fresh()->status->value)->toBe('draft');
    });

    it('frees the slot when the open link is revoked or has expired', function () {
        $user = v2Free();
        $link = SocialApprovalLink::create(['user_id' => $user->id, 'client_name' => 'Acme']);
        $expired = SocialApprovalLink::create(['user_id' => $user->id, 'client_name' => 'Old', 'expires_at' => now()->subHour()]);

        expect(PlanQuota::approvalClients($user)->all())->toBe(['name:acme']);
        $this->actingAs($user)->deleteJson("/api/v1/social-approval-links/{$link->ulid}")->assertNoContent();

        $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => 'globex@client.test', 'post_ids' => [v2Draft($user)->ulid], 'client_name' => 'Globex'])->assertCreated();
        expect($expired->fresh()->revoked_at)->toBeNull();
    });

    it('refuses renaming an open link to a second client, not renaming the only one', function () {
        $user = v2Free();
        $acme = SocialApprovalLink::create(['user_id' => $user->id, 'client_name' => 'Acme']);
        $acme->posts()->attach(v2Draft($user, ['status' => 'scheduled', 'approval' => 'client'])->id);

        $this->actingAs($user)->patchJson("/api/v1/social-approval-links/{$acme->ulid}", ['client_name' => 'Acme Corp'])->assertOk();

        $other = SocialApprovalLink::create(['user_id' => $user->id, 'client_name' => 'Acme Corp']);
        $other->posts()->attach(v2Draft($user, ['status' => 'scheduled', 'approval' => 'client'])->id);
        v2Assert402($this->actingAs($user)->patchJson("/api/v1/social-approval-links/{$other->ulid}", ['client_name' => 'Globex']), 'approval_clients', 1);
    });

    it('only puts posts inside the 7-day window on a link', function () {
        $user = v2Free();
        $edge = v2Draft($user, ['status' => 'scheduled', 'approval' => 'awaiting', 'scheduled_at' => now()->addDays(7)]);
        $far = v2Draft($user, ['status' => 'scheduled', 'approval' => 'awaiting', 'scheduled_at' => now()->addDays(7)->addMinute()]);

        v2Assert402($this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [$far->ulid]]), 'schedule_window', 7);
        $link = $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [$edge->ulid]])->assertCreated()->json('data.id');
        v2Assert402($this->actingAs($user)->patchJson("/api/v1/social-approval-links/{$link}", ['add_post_ids' => [$far->ulid]]), 'schedule_window', 7);
    });

    it('counts drafts put on a link against the queue', function () {
        $user = v2Free();
        v2Queue($user, 9);
        $one = v2Draft($user);
        $two = v2Draft($user);

        v2Assert402($this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [$one->ulid, $two->ulid]]), 'queue_limit', 10);
        $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [$one->ulid]])->assertCreated();
    });

    it('is unchanged on Pro: many clients, any slot', function () {
        $user = v2Pro();
        foreach (['Acme', 'Globex', 'Initech'] as $client) {
            $post = v2Draft($user, ['status' => 'scheduled', 'approval' => 'awaiting', 'scheduled_at' => now()->addDays(40)]);
            $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => strtolower($client).'@client.test', 'post_ids' => [$post->ulid], 'client_name' => $client])->assertCreated();
        }
    });
});

describe('rule 3 — features gated off on Free', function () {
    it('refuses AI captions and hashtags on Free, answers on Pro', function (string $mode) {
        v2Assert402($this->actingAs(v2Free())->postJson('/api/v1/social-captions/assist', ['mode' => $mode, 'caption' => 'x']), 'ai_caption');
        $this->actingAs(v2Pro())->postJson('/api/v1/social-captions/assist', ['mode' => $mode, 'caption' => 'x'])->assertOk();
    })->with(['rewrite', 'hashtags']);

    it('refuses insight (analytics) on Free, answers on Pro', function () {
        v2Assert402($this->actingAs(v2Free())->getJson('/api/v1/me/analytics'), 'insight');
        $this->actingAs(v2Pro())->getJson('/api/v1/me/analytics')->assertOk();
    });

    it('refuses creating, editing and publishing Spaces on Free, allows them on Pro', function () {
        $free = v2Free();
        $space = Space::factory()->for($free)->create(['status' => 'draft']);

        v2Assert402($this->actingAs($free)->postJson('/api/v1/spaces', ['title' => 'New']), 'spaces');
        v2Assert402($this->actingAs($free)->patchJson("/api/v1/spaces/{$space->ulid}", ['title' => 'Edit']), 'spaces');
        v2Assert402($this->actingAs($free)->postJson("/api/v1/spaces/{$space->ulid}/publish"), 'spaces');
        v2Assert402($this->actingAs($free)->postJson("/api/v1/spaces/{$space->ulid}/duplicate"), 'spaces');
        v2Assert402($this->actingAs($free)->postJson("/api/v1/spaces/{$space->ulid}/items", ['items' => []]), 'spaces');

        $pro = v2Pro();
        $id = $this->actingAs($pro)->postJson('/api/v1/spaces', ['title' => 'New'])->assertCreated()->json('data.id');
        $this->actingAs($pro)->patchJson("/api/v1/spaces/{$id}", ['title' => 'Edit'])->assertOk();
        $this->actingAs($pro)->postJson("/api/v1/spaces/{$id}/publish")->assertOk();
    });

    it('keeps a Free user\'s existing Spaces: listed, still published, can be unpublished or deleted', function () {
        $free = v2Free();
        $published = Space::factory()->for($free)->create(['status' => 'published']);
        $draft = Space::factory()->for($free)->create(['status' => 'draft']);

        $this->actingAs($free)->getJson('/api/v1/spaces')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($free)->getJson("/api/v1/spaces/{$published->ulid}")->assertOk();
        expect($published->fresh()->status->value)->toBe('published');

        $this->actingAs($free)->postJson("/api/v1/spaces/{$published->ulid}/unpublish")->assertOk();
        $this->actingAs($free)->deleteJson("/api/v1/spaces/{$draft->ulid}")->assertSuccessful();
    });

    it('refuses editing the link in bio (public profile) on Free; account settings stay open', function () {
        $free = v2Free();

        v2Assert402($this->actingAs($free)->patchJson('/api/v1/me/profile', ['bio' => 'Hello']), 'spaces');
        $this->actingAs($free)->patchJson('/api/v1/me/profile', ['name' => 'New name', 'theme' => 'dark', 'locale' => 'id'])->assertOk();
        $this->actingAs(v2Pro())->patchJson('/api/v1/me/profile', ['bio' => 'Hello'])->assertOk();
    });

    it('has a gate and a helper for the features with no endpoint yet', function (string $feature, string $helper) {
        $free = v2Free();
        $pro = v2Pro();

        expect(fn () => PlanQuota::{$helper}($free))
            ->toThrow(fn (PlanFeatureRequired $e) => expect($e->feature)->toBe($feature)
                ->and($e->render()->getStatusCode())->toBe(402)
                ->and($e->body())->toMatchArray(['reason' => 'plan_required', 'feature' => $feature, 'required_plan' => 'pro']));

        PlanQuota::{$helper}($pro); // no throw
        expect(PlanQuota::FEATURES)->toContain($feature)
            ->and(Plan::query()->where('key', 'free')->sole()->feature($feature))->toBeFalse()
            ->and(Plan::query()->where('key', 'pro')->sole()->feature($feature))->toBeTrue();
    })->with([
        'alt text' => ['alt_text', 'requireAltText'],
        'AI reply suggestions' => ['ai_reply_suggestions', 'requireAiReplySuggestions'],
        'comment to DM' => ['comment_to_dm', 'requireCommentToDm'],
    ]);

    it('turns every gate on for Pro, Max and Enterprise and off for Free, from the plans table', function () {
        foreach (Plan::all() as $plan) {
            foreach (PlanQuota::FEATURES as $feature) {
                expect($plan->feature($feature))->toBe($plan->key->value !== 'free', "{$plan->key->value}.{$feature}");
            }
        }
    });
});

describe('rule 4 — Enterprise', function () {
    it('lists Enterprise with contact_sales and no checkout, and no storage upgrades', function () {
        $rows = collect($this->getJson('/api/v1/plans')->assertOk()->json('data'))->keyBy('key');

        expect($rows->keys()->all())->toBe(['free', 'pro', 'max', 'enterprise'])
            ->and($rows['enterprise'])->toMatchArray(['contact_sales' => true, 'checkout' => false, 'price_monthly' => null, 'price_yearly' => null])
            ->and($rows['pro'])->toMatchArray(['contact_sales' => false, 'checkout' => true])
            ->and($rows->keys()->filter(fn ($k) => str_contains($k, 'storage'))->all())->toBe([]);

        $this->actingAs(v2Free())->postJson('/api/v1/me/subscription/checkout', ['plan' => 'enterprise', 'period' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors('plan');
    });

    $form = ['name' => 'Rina', 'email' => 'Rina@Studio.test', 'company' => 'Studio', 'team_size' => '11-50', 'message' => 'We have 30 brands.'];

    it('stores the lead, emails halo@creatif.space, answers 202 — signed out', function () use ($form) {
        Notification::fake();

        $this->postJson('/api/v1/enterprise/contact', $form)->assertStatus(202)->assertJsonPath('status', 'received');

        $lead = EnterpriseLead::query()->sole();
        expect($lead->only(['name', 'email', 'company', 'team_size', 'user_id']))
            ->toBe(['name' => 'Rina', 'email' => 'rina@studio.test', 'company' => 'Studio', 'team_size' => '11-50', 'user_id' => null]);
        Notification::assertSentOnDemand(EnterpriseContactReceived::class, function ($n, $channels, AnonymousNotifiable $to) use ($lead) {
            $mail = $n->toMail($to);

            return $to->routes['mail'] === 'halo@creatif.space'
                && $n->lead->is($lead)
                && $mail->replyTo === [['rina@studio.test', 'Rina']];
        });
    });

    it('links a signed-in person, and takes a numeric team size', function () use ($form) {
        Notification::fake();
        $user = v2Free();

        $this->actingAs($user)->postJson('/api/v1/enterprise/contact', ['team_size' => 25] + $form)->assertStatus(202);

        expect(EnterpriseLead::query()->sole()->only(['user_id', 'team_size']))->toBe(['user_id' => $user->id, 'team_size' => '25']);
    });

    it('swallows the honeypot: same 202, nothing stored or sent', function () use ($form) {
        Notification::fake();

        $this->postJson('/api/v1/enterprise/contact', $form + ['website' => 'http://spam.test'])->assertStatus(202);

        expect(EnterpriseLead::query()->count())->toBe(0);
        Notification::assertNothingSent();
    });

    it('validates the form', function () {
        Notification::fake();

        $this->postJson('/api/v1/enterprise/contact', ['email' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'company', 'team_size', 'message']);
        Notification::assertNothingSent();
    });

    it('is throttled: the 6th request in a minute is 429', function () use ($form) {
        Notification::fake();
        RateLimiter::clear('');

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/enterprise/contact', $form)->assertStatus(202);
        }
        $this->postJson('/api/v1/enterprise/contact', $form)->assertStatus(429);

        expect(EnterpriseLead::query()->count())->toBe(5);
    });
});

describe('rule 6 — MCP / API keys get the same answers', function () {
    it('get_credits (GET /credits) shows the Free limits and gates', function () {
        $user = v2Free();
        v2Queue($user, 3);
        SocialApprovalLink::create(['user_id' => $user->id, 'client_name' => 'Acme']);

        $limits = bearer($this, v2ApiKey($this, $user, ['read']))->getJson('/api/v1/credits')->assertOk()->json('data.limits');

        expect($limits['scheduling'])->toBe(['window_days' => 7, 'queued_posts' => ['limit' => 10, 'used' => 3]])
            ->and($limits['approval_clients'])->toBe(['limit' => 1, 'used' => 1])
            ->and($limits['features'])->toMatchArray([
                'approval_links' => true, 'ai_caption' => false, 'insight' => false, 'spaces' => false,
                'alt_text' => false, 'ai_reply_suggestions' => false, 'comment_to_dm' => false,
            ]);
    });

    it('get_credits shows no limits on Pro', function () {
        $limits = bearer($this, v2ApiKey($this, v2Pro(), ['read']))->getJson('/api/v1/credits')->json('data.limits');

        expect($limits['scheduling'])->toBe(['window_days' => null, 'queued_posts' => ['limit' => null, 'used' => 0]])
            ->and($limits['approval_clients']['limit'])->toBeNull()
            ->and($limits['features']['spaces'])->toBeTrue();
    });

    it('schedule_post: the preview and the send answer 402 schedule_window with the same body', function () {
        $user = v2Free();
        $draft = v2Draft($user);
        $key = v2ApiKey($this, $user);
        $far = now()->addDays(7)->addMinute()->toIso8601String();

        $preview = bearer($this, $key)->getJson("/api/v1/social-posts/{$draft->ulid}/send-preview?scheduled_at=".urlencode($far));
        v2Assert402($preview, 'schedule_window', 7);

        $token = bearer($this, $key)->getJson("/api/v1/social-posts/{$draft->ulid}/send-preview")->assertOk()->json('data.confirm_token');
        $send = bearer($this, $key)->postJson("/api/v1/social-posts/{$draft->ulid}/send", ['scheduled_at' => $far, 'confirm_token' => $token]);
        v2Assert402($send, 'schedule_window', 7);
        expect($send->json())->toBe($preview->json());
    });

    it('schedule_post / publish_post: 402 queue_limit through a key', function () {
        $user = v2Free();
        v2Queue($user, 10);
        $draft = v2Draft($user);
        $key = v2ApiKey($this, $user);

        v2Assert402(bearer($this, $key)->getJson("/api/v1/social-posts/{$draft->ulid}/send-preview?scheduled_at=".urlencode(now()->addDay()->toIso8601String())), 'queue_limit', 10);
        // Publishing now joins no queue: the preview is fine.
        bearer($this, $key)->getJson("/api/v1/social-posts/{$draft->ulid}/send-preview")->assertOk();
    });

    it('create_approval_link: 402 approval_clients through a key', function () {
        $user = v2Free();
        SocialApprovalLink::create(['user_id' => $user->id, 'client_name' => 'Acme']);

        v2Assert402(bearer($this, v2ApiKey($this, $user))
            ->postJson('/api/v1/social-approval-links', ['approver_email' => 'globex@client.test', 'post_ids' => [v2Draft($user)->ulid], 'client_name' => 'Globex']), 'approval_clients', 1);
    });
});

describe('rule 7 — existing data and the migration', function () {
    it('adds the plans v2 keys to existing rows without touching storage, prices or Credits, and adds Enterprise', function () {
        // Rows as production has them before the migration.
        Plan::query()->where('key', 'enterprise')->delete();
        $before = [];
        foreach (Plan::all() as $plan) {
            $quotas = collect($plan->quotas)->except(['schedule_window_days', 'queued_posts', 'approval_clients'])->all();
            $features = collect($plan->features)->except(PlanQuota::FEATURES)->all();
            if ($plan->key->value === 'free') {
                $features['client_approvals'] = false;
                $quotas['storage_bytes'] = 123; // whatever Media v2 has set stays
            }
            DB::table('plans')->where('id', $plan->id)->update(['quotas' => json_encode($quotas), 'features' => json_encode($features)]);
            $before[$plan->key->value] = [$plan->price_monthly, $quotas['storage_bytes'], $quotas['credits_monthly']];
        }

        (require database_path('migrations/2026_11_17_100000_plans_v2.php'))->up();

        foreach (Plan::all()->where('key.value', '!=', 'enterprise') as $plan) {
            expect([$plan->price_monthly, $plan->quota('storage_bytes'), $plan->quota('credits_monthly')])->toBe($before[$plan->key->value]);
        }
        $free = Plan::query()->where('key', 'free')->sole();
        expect($free->quota('schedule_window_days'))->toBe(7)
            ->and($free->quota('queued_posts'))->toBe(10)
            ->and($free->quota('approval_clients'))->toBe(1)
            ->and($free->feature('client_approvals'))->toBeTrue()
            ->and($free->feature('spaces'))->toBeFalse();
        $enterprise = Plan::query()->where('key', 'enterprise')->sole();
        expect($enterprise->feature('contact_sales'))->toBeTrue()
            ->and($enterprise->is_active)->toBeTrue()
            ->and($enterprise->quota('queued_posts'))->toBeNull();
    });

    it('reports existing rows past the limits without changing them', function () {
        $user = v2Free();
        Space::factory()->count(2)->for($user)->create(['status' => 'published']);
        v2Queue($user, 12, ['scheduled_at' => now()->addDays(20)]);
        SocialApprovalLink::create(['user_id' => $user->id, 'client_name' => 'Acme']);
        SocialApprovalLink::create(['user_id' => $user->id, 'client_name' => 'Globex']);
        v2Pro(); // not counted

        Artisan::call('plans:v2-report');
        $out = Artisan::output();

        expect($out)->toMatch('/with Spaces.*\|\s+1\s+\|/')
            ->toMatch('/Spaces on them \(published\)\s+\|\s+2 \(2\)/')
            ->toMatch('/Posts beyond the window\s+\|\s+12\s+\|/')
            ->toMatch('/Posts past the limit\s+\|\s+2\s+\|/')
            ->toMatch('/more approval clients than allowed.*\|\s+1\s+\|/')
            ->and(SocialPost::query()->where('status', 'scheduled')->count())->toBe(12)
            ->and(Space::query()->where('status', 'published')->count())->toBe(2);
    });
});
