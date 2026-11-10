<?php

use App\Jobs\SyncInbox;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\User;
use App\Support\SocialProviders;
use App\Support\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Engage e2e on IG @creatif_space (10 Nov): DMs worked but the sender read
 * "Instagram user"; comments on the account's latest post never showed.
 * Payload shapes from https://www.outstand.so/docs (conversations,
 * webhooks#commentreceived-event), 10 Nov 2026.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
        'services.outstand.webhook_secret' => 'whsec_out',
        'services.inbox.enabled' => true,
        'services.inbox.credits_per_reply' => 0,
    ]);
    Cache::flush();
    Workspace::forget();
});

function e2eAccount(): SocialAccount
{
    return SocialAccount::factory()->for(User::factory()->create())->create([
        'platform' => 'instagram',
        'provider' => 'outstand',
        'provider_account_id' => 'beUgT',
        'username' => 'creatif_space',
        'messages_scope_at' => now(),
        'inbox_synced_at' => now(),
    ]);
}

function e2eHook($test, string $event, array $data, string $secret = 'whsec_out')
{
    $body = json_encode(['event' => $event, 'timestamp' => now()->toIso8601String(), 'data' => $data], JSON_THROW_ON_ERROR);

    return $test->call('POST', '/api/v1/webhooks/outstand', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_OUTSTAND_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], $body);
}

describe('(A) the DM sender', function () {
    it('takes the name and picture from Outstand\'s participant fields', function () {
        // GET /v1/conversations as documented: flat participant* fields.
        Http::fake([
            'api.outstand.test/v1/conversations?*' => Http::response(['success' => true, 'data' => [[
                'id' => 'conv_1', 'orgId' => 'org', 'socialAccountId' => 'beUgT', 'network' => 'instagram',
                'platformConversationId' => 'aWdf', 'participantId' => '17841400000000000',
                'participantDisplayName' => 'rizal.dev', 'participantProfilePicture' => 'https://cdn.test/rizal.jpg',
                'lastMessageAt' => now()->toIso8601String(), 'lastInboundAt' => now()->toIso8601String(),
                'unreadCount' => 1, 'status' => 'open',
            ]]]),
            'api.outstand.test/v1/conversations/*/messages*' => Http::response(['success' => true, 'data' => []]),
        ]);
        $account = e2eAccount();

        (new SyncInbox($account->id))->handle(app(SocialProviders::class));

        expect(SocialConversation::sole())
            ->contact_id->toBe('17841400000000000')
            ->contact_name->toBe('rizal.dev')
            ->contact_avatar_url->toBe('https://cdn.test/rizal.jpg');

        $this->actingAs($account->user)->getJson('/api/v1/inbox/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.contact.name', 'rizal.dev')
            ->assertJsonPath('data.0.contact.avatar_url', 'https://cdn.test/rizal.jpg');
    });

    it('fills the name in on a thread first filed from a bare webhook', function () {
        $account = e2eAccount();
        // conversation.started carries ids only.
        e2eHook($this, 'conversation.started', [
            'conversationId' => 'conv_2', 'socialAccountId' => 'beUgT', 'network' => 'instagram', 'participantId' => '1784',
        ])->assertOk();
        expect(SocialConversation::sole()->contact_name)->toBeNull();

        Http::fake([
            'api.outstand.test/v1/conversations?*' => Http::response(['success' => true, 'data' => [[
                'id' => 'conv_2', 'socialAccountId' => 'beUgT', 'network' => 'instagram',
                'participantId' => '1784', 'participantDisplayName' => 'Rizal', 'lastMessageAt' => now()->toIso8601String(),
            ]]]),
            'api.outstand.test/v1/conversations/*/messages*' => Http::response(['success' => true, 'data' => []]),
        ]);
        (new SyncInbox($account->id))->handle(app(SocialProviders::class));

        expect(SocialConversation::sole()->contact_name)->toBe('Rizal');
    });
});

describe('(B) comments on posts published outside Creatif Space', function () {
    it('files comment.received for a post Outstand does not hold (postId null)', function () {
        $account = e2eAccount();

        e2eHook($this, 'comment.received', [
            'orgId' => 'org', 'network' => 'instagram', 'accountId' => 'beUgT',
            'postId' => null, 'platformPostId' => '18050000000000000', 'isLive' => false,
            'commentId' => '17990000000000001', 'parentCommentId' => null,
            'authorId' => '1784555', 'authorUsername' => 'rizal.dev', 'authorName' => null,
            'text' => 'Test comment', 'author' => null,
        ])->assertOk();

        expect(SocialComment::sole())
            ->provider_post_id->toBeNull()
            ->social_post_id->toBeNull()
            ->platform_post_id->toBe('18050000000000000')
            ->author_username->toBe('rizal.dev');

        $this->actingAs($account->user)->getJson('/api/v1/inbox/comments')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('falls back to the author name when no username is sent', function () {
        e2eAccount();

        e2eHook($this, 'comment.received', [
            'network' => 'facebook', 'accountId' => 'beUgT', 'postId' => null, 'platformPostId' => 'p1',
            'commentId' => 'fb_1', 'authorId' => 'u1', 'authorUsername' => null, 'authorName' => 'Rizal M', 'text' => 'Hi',
        ])->assertOk();

        expect(SocialComment::sole()->author_username)->toBe('Rizal M');
    });

    it('says in the log when a comment cannot be matched, instead of dropping it silently', function () {
        Log::spy();

        e2eHook($this, 'comment.received', ['accountId' => 'not_ours', 'commentId' => 'c1', 'network' => 'instagram'])->assertOk();

        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => $msg === 'inbox.comment_dropped'
            && $ctx === ['provider' => 'outstand', 'account' => 'not_ours', 'reason' => 'unknown_account']);
        expect(SocialComment::count())->toBe(0);
    });

    it('logs each event type that arrives, without content', function () {
        e2eAccount();
        Log::spy();

        e2eHook($this, 'comment.received', ['accountId' => 'beUgT', 'commentId' => 'c2', 'text' => 'secret words', 'network' => 'instagram'])->assertOk();

        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) => $msg === 'webhook.event'
            && $ctx === ['provider' => 'outstand', 'type' => 'comment', 'account' => 'beUgT']);
    });

    it('logs a signature it refuses — a wrong secret otherwise drops every comment unseen', function () {
        Log::spy();

        e2eHook($this, 'comment.received', ['accountId' => 'beUgT', 'commentId' => 'c3'], 'wrong_secret')->assertForbidden();

        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => $msg === 'webhook.signature_rejected'
            && $ctx === ['provider' => 'outstand', 'event' => 'comment.received']);
    });
});
