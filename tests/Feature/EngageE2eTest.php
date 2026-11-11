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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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

/**
 * The `webhooks` channel into a temp file, with the app's main level at
 * `error` as in prod — what lands here survives that.
 */
function e2eWebhookLog(): string
{
    $path = tempnam(sys_get_temp_dir(), 'webhooks').'.log';
    config([
        'logging.channels.webhooks' => ['driver' => 'single', 'path' => $path, 'level' => 'info'],
        'logging.channels.single.level' => 'error',
        'logging.channels.stack.channels' => ['single'],
    ]);
    app('log')->forgetChannel('webhooks');
    touch($path);

    return $path;
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

    it('says in the webhook log when a comment cannot be matched, instead of dropping it silently', function () {
        $log = e2eWebhookLog();

        e2eHook($this, 'comment.received', ['accountId' => 'not_ours', 'commentId' => 'c1', 'network' => 'instagram'])->assertOk();

        expect(file_get_contents($log))->toContain('inbox.comment_dropped')->toContain('"reason":"unknown_account"')
            ->and(SocialComment::count())->toBe(0);
    });

    it('logs each event type that arrives, without content, even when prod LOG_LEVEL drops info', function () {
        e2eAccount();
        $log = e2eWebhookLog();

        e2eHook($this, 'comment.received', ['accountId' => 'beUgT', 'commentId' => 'c2', 'text' => 'secret words', 'network' => 'instagram'])->assertOk();

        // Each line carries the request id (AssignRequestId) before its own fields.
        expect(file_get_contents($log))
            ->toMatch('/webhook\.event \{"request_id":"[0-9A-Z]{26}","provider":"outstand","type":"comment","account":"beUgT"\}/')
            ->toMatch('/inbox\.outcome \{"request_id":"[0-9A-Z]{26}","provider":"outstand","type":"comment","outcome":"filed"\}/')
            ->not->toContain('secret words');
    });

    it('logs a signature it refuses — a wrong secret otherwise drops every comment unseen', function () {
        $log = e2eWebhookLog();

        e2eHook($this, 'comment.received', ['accountId' => 'beUgT', 'commentId' => 'c3'], 'wrong_secret')->assertForbidden();

        expect(file_get_contents($log))->toMatch('/webhook\.signature_rejected \{"request_id":"[0-9A-Z]{26}","provider":"outstand","event":"comment\.received"\}/');
    });
});

describe('(QA 11 Nov) a stored comment event becomes a visible comment', function () {
    it('records the outcome next to the receipt and lists the comment with a link', function () {
        $account = e2eAccount();

        e2eHook($this, 'comment.received', [
            'network' => 'instagram', 'accountId' => 'beUgT', 'postId' => null, 'platformPostId' => '18050000000000000',
            'commentId' => '17990000000000002', 'authorId' => '1784555', 'authorUsername' => 'rizal.dev', 'text' => 'Halo',
        ])->assertOk();

        expect(DB::table('webhook_events')->where('type', 'inbox.comment')->value('outcome'))->toBe('filed');

        $this->actingAs($account->user)->getJson('/api/v1/inbox/comments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.post_id', null)
            ->assertJsonPath('data.0.outside_post', true)
            ->assertJsonPath('data.0.platform_post_id', '18050000000000000')
            ->assertJsonPath('data.0.post_url', 'https://www.instagram.com/creatif_space/')
            ->assertJsonPath('data.0.author.username', 'rizal.dev');
    });

    it('links a Facebook comment on an outside post to the post itself', function () {
        $account = SocialAccount::factory()->for(User::factory()->create())->create([
            'platform' => 'facebook', 'provider' => 'outstand', 'provider_account_id' => 'fbAcc', 'username' => 'creatif',
        ]);

        e2eHook($this, 'comment.received', [
            'network' => 'facebook', 'accountId' => 'fbAcc', 'postId' => null, 'platformPostId' => '1234_5678',
            'commentId' => 'fbc1', 'authorName' => 'Rizal', 'text' => 'Hi',
        ])->assertOk();

        $this->actingAs($account->user)->getJson('/api/v1/inbox/comments')
            ->assertJsonPath('data.0.post_url', 'https://www.facebook.com/1234_5678');
    });

    it('names why a comment was not filed', function (array $data, string $outcome) {
        e2eAccount();

        e2eHook($this, 'comment.received', $data + ['network' => 'instagram', 'text' => 'x'])->assertOk();

        expect(DB::table('webhook_events')->where('type', 'inbox.comment')->value('outcome'))->toBe($outcome)
            ->and(SocialComment::count())->toBe(0);
    })->with([
        'unknown account' => [['accountId' => 'not_ours', 'commentId' => 'c1'], 'dropped:unknown_account'],
        'the account itself' => [['accountId' => 'beUgT', 'commentId' => 'c2', 'authorUsername' => 'creatif_space'], 'dropped:own_comment'],
        'no comment id' => [['accountId' => 'beUgT'], 'dropped:no_comment_id'],
    ]);

    it('files a comment sent with the alternative field names', function () {
        e2eAccount();

        e2eHook($this, 'comment.received', [
            'network' => 'instagram', 'socialAccountId' => 'beUgT', 'platformCommentId' => 'c9', 'mediaId' => 'm1', 'authorUsername' => 'fan', 'text' => 'Hi',
        ])->assertOk();

        expect(SocialComment::sole())->comment_id->toBe('c9')->platform_post_id->toBe('m1');
    });
});

describe('(QA 11 Nov) opening Engage refreshes', function () {
    it('re-syncs an account last synced more than 10 minutes ago, not one synced just now', function () {
        Queue::fake();
        $account = e2eAccount();
        $account->forceFill(['inbox_synced_at' => now()->subMinutes(20)])->save();
        $fresh = SocialAccount::factory()->for($account->user)->create([
            'platform' => 'instagram', 'provider' => 'outstand', 'provider_account_id' => 'fresh', 'inbox_synced_at' => now()->subMinutes(2),
        ]);

        $this->actingAs($account->user)->getJson('/api/v1/inbox/conversations')->assertOk();

        Queue::assertPushed(SyncInbox::class, fn ($job) => $job->accountId === $account->id);
        Queue::assertNotPushed(SyncInbox::class, fn ($job) => $job->accountId === $fresh->id);
    });

    it('is on the hourly schedule', function () {
        $this->artisan('schedule:list')->expectsOutputToContain('SyncInboxes')->assertSuccessful();
    });
});

it('files a comment for a reconnected account on the live row, not the one left disconnected', function () {
    $old = SocialAccount::factory()->for(User::factory()->create())->create([
        'platform' => 'instagram', 'provider' => 'outstand', 'provider_account_id' => 'oldAcc',
        'network_unique_id' => '28161365190200706', 'status' => 'disconnected',
    ]);
    $live = e2eAccount();
    $live->forceFill(['network_unique_id' => '28161365190200706'])->save();

    e2eHook($this, 'comment.received', [
        'network' => 'instagram', 'accountId' => 'oldAcc', 'commentId' => 'c_twin', 'authorUsername' => 'fan', 'text' => 'Hi',
    ])->assertOk();

    expect(SocialComment::sole())->social_account_id->toBe($live->id)->user_id->toBe($live->user_id);
});
