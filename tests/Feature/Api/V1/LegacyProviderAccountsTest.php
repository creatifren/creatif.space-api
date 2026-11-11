<?php

use App\Enums\SocialAccountStatus;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SocialPostDecided;
use App\Services\Analytics;
use App\Support\Credits;
use App\Support\PlanQuota;
use App\Support\ProductEvents;
use App\Support\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * Rows left under Post for Me after the switch to Outstand: listed as
 * disconnected with reconnect_required, never counted toward the plan,
 * refused by name in every send path, and replaced by a reconnect.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
        'services.inbox.enabled' => true,
        'app.frontend_url' => 'https://app.test',
    ]);
});

function legacyPayer(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, 30_000, 'Test');

    return $user;
}

function legacyRow(User $user, string $platform = 'instagram', array $attrs = []): SocialAccount
{
    return SocialAccount::factory()->for($user)->create(
        $attrs + ['provider' => 'postforme', 'platform' => $platform, 'username' => 'rani'],
    );
}

function currentRow(User $user, string $platform = 'instagram', array $attrs = []): SocialAccount
{
    return SocialAccount::factory()->for($user)->create($attrs + ['provider' => 'outstand', 'platform' => $platform]);
}

/** A post aimed at one account, held for approval or saved as a draft. */
function postFor(User $user, SocialAccount $account, array $attrs = []): SocialPost
{
    $post = SocialPost::factory()->for($user)->create($attrs + ['provider_post_id' => null]);
    $post->targets()->create(['social_account_id' => $account->id, 'status' => 'pending']);

    return $post;
}

describe('the model', function () {
    it('has one meaning of usable', function () {
        $user = User::factory()->create();
        $legacy = legacyRow($user);
        $current = currentRow($user);
        $gone = currentRow($user, 'tiktok', ['status' => 'disconnected']);

        expect($legacy->isUsable())->toBeFalse()->and($legacy->isLegacy())->toBeTrue()
            ->and($current->isUsable())->toBeTrue()->and($current->isLegacy())->toBeFalse()
            ->and($gone->isUsable())->toBeFalse()->and($gone->isLegacy())->toBeFalse()
            ->and($user->socialAccounts()->usable()->pluck('id')->all())->toBe([$current->id]);
    });
});

describe('listing', function () {
    it('lists a legacy row as disconnected and reconnect_required, keeping its id', function () {
        $user = User::factory()->create();
        $legacy = legacyRow($user, 'instagram', ['messages_scope_at' => now()]);
        $current = currentRow($user, 'tiktok');

        $rows = collect($this->actingAs($user)->getJson('/api/v1/social-accounts')->assertOk()->json('data'))->keyBy('id');

        expect($rows[$legacy->ulid])->toMatchArray([
            'status' => 'disconnected',
            'reconnect_required' => true,
            'messaging_enabled' => false,
            'needs_reconnect_for_messages' => false,
        ])->and($rows[$current->ulid])->toMatchArray([
            'status' => 'connected',
            'reconnect_required' => false,
        ]);
        // Nothing was written: the row is only reported differently.
        expect($legacy->fresh()->status)->toBe(SocialAccountStatus::Connected);
    });

    it('does not flag an account that is simply disconnected', function () {
        $user = User::factory()->create();
        $gone = legacyRow($user, 'instagram', ['status' => 'disconnected']);

        $this->actingAs($user)->getJson('/api/v1/social-accounts')
            ->assertJsonPath('data.0.id', $gone->ulid)
            ->assertJsonPath('data.0.status', 'disconnected')
            ->assertJsonPath('data.0.reconnect_required', false);
    });
});

describe('sending', function () {
    it('refuses a new post to a legacy account by name, before the provider or a charge', function () {
        Http::fake();
        $user = legacyPayer();
        $legacy = legacyRow($user);

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', ['caption' => 'Hi', 'account_ids' => [$legacy->ulid]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'account_reconnect_required')
            ->assertJsonPath('message', 'This account needs to be reconnected: instagram @rani')
            ->assertJsonPath('accounts.0.id', $legacy->ulid)
            ->assertJsonPath('errors.account_ids.0', 'This account needs to be reconnected: instagram @rani');

        expect(SocialPost::query()->count())->toBe(0)
            ->and(Credits::balance($user))->toBe(30_000);
        Http::assertNothingSent();
    });

    it('refuses the whole post when one of several accounts is legacy, and names only that one', function () {
        Http::fake();
        $user = legacyPayer();
        $ok = currentRow($user, 'tiktok');
        $legacy = legacyRow($user, 'facebook', ['username' => '@studio']);

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', ['caption' => 'Hi', 'account_ids' => [$ok->ulid, $legacy->ulid], 'draft' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'account_reconnect_required')
            ->assertJsonPath('message', 'This account needs to be reconnected: facebook @studio')
            ->assertJsonCount(1, 'accounts');

        expect(SocialPost::query()->count())->toBe(0);
    });

    it('still answers "not yours" for an id the user does not own', function () {
        $user = legacyPayer();
        $theirs = currentRow(User::factory()->create());

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', ['caption' => 'Hi', 'account_ids' => [$theirs->ulid]])
            ->assertStatus(422)
            ->assertJsonMissingPath('code')
            ->assertJsonValidationErrors('account_ids');
    });

    it('posts to a current account as before', function () {
        Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'post' => ['id' => 'p1', 'publishedAt' => null]])]);
        $user = legacyPayer();
        legacyRow($user);
        $ok = currentRow($user);

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', ['caption' => 'Hi', 'media' => [['url' => 'https://cdn.example.test/photo.jpg']], 'account_ids' => [$ok->ulid]])
            ->assertCreated();
    });

    it('refuses to send a draft aimed at a legacy account, uncharged', function () {
        Http::fake();
        $user = legacyPayer();
        $post = postFor($user, legacyRow($user), ['status' => 'draft']);

        $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/send")
            ->assertStatus(422)
            ->assertJsonPath('code', 'account_reconnect_required');

        expect($post->fresh()->status->value)->toBe('draft')
            ->and(Credits::balance($user))->toBe(30_000);
        Http::assertNothingSent();
    });

    it('refuses the owner\'s approve of a held post aimed at a legacy account', function () {
        Http::fake();
        $user = legacyPayer();
        $post = postFor($user, legacyRow($user), ['approval' => 'awaiting']);

        $this->actingAs($user)->postJson("/api/v1/social-posts/{$post->ulid}/approve")
            ->assertStatus(422)
            ->assertJsonPath('code', 'account_reconnect_required');

        expect($post->fresh()->approval->value)->toBe('awaiting')
            ->and(Credits::balance($user))->toBe(30_000);
        Http::assertNothingSent();
    });

    it('tells the editor which account to reconnect when a client approves a post on it', function () {
        Notification::fake();
        Http::fake();
        $user = legacyPayer();
        $post = postFor($user, legacyRow($user), ['approval' => 'client']);
        $link = SocialApprovalLink::create(['user_id' => $user->id, 'title' => 'Desember', 'client_name' => 'Andi']);
        $link->posts()->attach($post->id);

        $results = asApprover($this)->postJson("/api/v1/a/{$link->token}/approve-all", ['name' => 'Andi', 'post_ids' => [$post->ulid]])
            ->assertOk()
            ->json('data.results');

        expect($results[0]['approved'])->toBeFalse()
            ->and($post->fresh()->approval->value)->toBe('client');
        Notification::assertSentTo($user, SocialPostDecided::class,
            fn ($n) => $n->decision === 'blocked' && $n->reason === 'This account needs to be reconnected: instagram @rani');
        Http::assertNothingSent();
    });

    it('refuses an edit that would reach the provider for a post on a legacy account', function () {
        Http::fake();
        $user = legacyPayer();
        $post = postFor($user, legacyRow($user), [
            'provider' => 'postforme',
            'provider_post_id' => 'sp_old',
            'scheduled_at' => now()->addDay(),
        ]);

        $this->actingAs($user)->patchJson("/api/v1/social-posts/{$post->ulid}", ['caption' => 'New'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'account_reconnect_required');

        expect($post->fresh()->caption)->not->toBe('New');
        Http::assertNothingSent();
    });

    it('still lets a draft on a legacy account be re-captioned, since nothing reaches the provider', function () {
        $user = legacyPayer();
        $post = postFor($user, legacyRow($user), ['status' => 'draft']);

        $this->actingAs($user)->patchJson("/api/v1/social-posts/{$post->ulid}", ['caption' => 'New'])->assertOk();

        expect($post->fresh()->caption)->toBe('New');
    });
});

describe('quota', function () {
    it('lets a Free user with three legacy rows reconnect', function () {
        Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'data' => ['auth_url' => 'https://www.outstand.so/o?state=s']])]);
        $user = User::factory()->create();
        legacyRow($user, 'instagram');
        legacyRow($user, 'facebook');
        legacyRow($user, 'tiktok');

        expect(PlanQuota::canConnectSocialAccount($user))->toBeTrue();
        $this->actingAs($user)
            ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'instagram'])
            ->assertOk();
    });

    it('still stops a Free user at three usable accounts', function () {
        $user = User::factory()->create();
        currentRow($user, 'instagram');
        currentRow($user, 'facebook');
        currentRow($user, 'tiktok');

        expect(PlanQuota::canConnectSocialAccount($user))->toBeFalse();
        $this->actingAs($user)
            ->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'threads'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('platform');
    });

    it('lets a Free user with legacy rows choose pages up to the limit', function () {
        Http::fake([
            'api.outstand.test/v1/social-accounts/pending/sess1/finalize' => Http::response(['success' => true, 'connectedAccounts' => []]),
            'api.outstand.test/v1/social-accounts/pending/sess1' => Http::response(['success' => true, 'data' => [
                'network' => 'facebook',
                'availablePages' => [
                    ['id' => 'org1', 'name' => 'One'], ['id' => 'org2', 'name' => 'Two'], ['id' => 'org3', 'name' => 'Three'],
                ],
            ]]),
        ]);
        $user = User::factory()->create();
        legacyRow($user, 'instagram');
        legacyRow($user, 'facebook');
        legacyRow($user, 'tiktok');
        Cache::put("outstand-pending:{$user->ulid}", 'sess1', 600);

        $this->actingAs($user)
            ->postJson('/api/v1/social-accounts/pending', ['page_ids' => ['org1', 'org2', 'org3']])
            ->assertOk()
            ->assertJsonPath('data.connected', 3);
    });

    it('counts only usable rows as channels', function () {
        $analytics = Analytics::fake();
        $user = User::factory()->create();
        legacyRow($user);
        currentRow($user, 'tiktok');

        ProductEvents::channelsCount($user);

        expect($analytics->setFor($user->ulid))->toBe([['channels_count' => 1]]);
    });
});

describe('reconnecting', function () {
    it('replaces the legacy row with a new Outstand one; the old posts still render', function () {
        $user = legacyPayer();
        $legacy = legacyRow($user, 'instagram');
        $old = postFor($user, $legacy, [
            'provider' => 'postforme',
            'provider_post_id' => 'sp_old',
            'status' => 'published',
            'scheduled_at' => now()->subDay(),
        ]);
        Http::fake([
            'api.outstand.test/v1/posts' => Http::response(['success' => true, 'post' => ['id' => 'p1', 'publishedAt' => null]]),
            'api.outstand.test/*' => Http::response(['success' => true, 'data' => [[
                'id' => 'Kx7vQ', 'network' => 'instagram', 'username' => 'rani', 'tenant_id' => $user->ulid, 'isActive' => 1,
            ]], 'count' => 1, 'total' => 1]),
        ]);

        $rows = collect($this->actingAs($user)->postJson('/api/v1/social-accounts/sync')->assertOk()->json('data'))->keyBy('id');

        $new = $user->socialAccounts()->where('provider', 'outstand')->sole();
        expect($new->provider_account_id)->toBe('Kx7vQ')
            ->and($new->isUsable())->toBeTrue()
            ->and($new->id)->not->toBe($legacy->id)
            ->and($legacy->fresh()->status)->toBe(SocialAccountStatus::Disconnected)
            ->and($rows[$new->ulid]['status'])->toBe('connected')
            ->and($rows[$legacy->ulid])->toMatchArray(['status' => 'disconnected', 'reconnect_required' => false])
            ->and(PlanQuota::canConnectSocialAccount($user))->toBeTrue();

        $this->actingAs($user)->getJson('/api/v1/social-posts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $old->ulid)
            ->assertJsonPath('data.0.targets.0.account.id', $legacy->ulid)
            ->assertJsonPath('data.0.targets.0.account.username', 'rani');

        // And the new row posts.
        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', ['caption' => 'Back', 'media' => [['url' => 'https://cdn.example.test/photo.jpg']], 'account_ids' => [$new->ulid]])
            ->assertCreated();
    });
});

describe('inbox', function () {
    it('refuses a DM, a comment reply and a private reply on a legacy account', function () {
        Http::fake();
        Workspace::forget();
        $user = User::factory()->create();
        $ig = currentRow($user, 'instagram', ['username' => 'studio', 'messages_scope_at' => now()]);
        $thread = SocialConversation::query()->create([
            'user_id' => $user->id, 'social_account_id' => $ig->id, 'provider' => 'outstand',
            'provider_conversation_id' => 'conv_1', 'network' => 'instagram', 'contact_id' => 'ig_contact',
            'last_message_at' => now()->subHour(), 'last_inbound_at' => now()->subHour(), 'status' => 'open',
        ]);
        $comment = SocialComment::query()->create([
            'user_id' => $user->id, 'social_account_id' => $ig->id, 'provider' => 'outstand',
            'provider_post_id' => 'post_1', 'comment_id' => 'c_1', 'author_id' => 'ig_fan',
            'text' => 'Love this!', 'network' => 'instagram', 'commented_at' => now()->subHour(),
        ]);
        // The relay moved on; the row did not.
        config(['services.social.provider' => 'postforme']);

        $this->actingAs($user)->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['content' => 'hi'])
            ->assertStatus(422)->assertJsonPath('code', 'account_reconnect_required')
            ->assertJsonPath('message', 'This account needs to be reconnected: instagram @studio');
        $this->postJson("/api/v1/inbox/comments/{$comment->ulid}/reply", ['content' => 'hi'])
            ->assertStatus(422)->assertJsonPath('code', 'account_reconnect_required');
        $this->postJson("/api/v1/inbox/comments/{$comment->ulid}/private-reply", ['content' => 'hi'])
            ->assertStatus(422)->assertJsonPath('code', 'account_reconnect_required');

        Http::assertNothingSent();
    });

    it('treats an Outstand row as not messaging-enabled once the relay is another one', function () {
        $user = User::factory()->create();
        $ig = currentRow($user, 'instagram', ['messages_scope_at' => now()]);
        expect($ig->messagingEnabled())->toBeTrue();

        config(['services.social.provider' => 'postforme']);

        expect($ig->messagingEnabled())->toBeFalse()
            ->and($ig->needsReconnectForMessages())->toBeFalse()
            ->and($ig->isLegacy())->toBeTrue();
    });
});
