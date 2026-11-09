<?php

use App\Exceptions\ProviderRateLimited;
use App\Jobs\SyncInbox;
use App\Jobs\SyncInboxes;
use App\Models\File;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use App\Models\SocialPost;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\InboxActivity;
use App\Support\Credits;
use App\Support\SocialProviders;
use App\Support\Workspace;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * The Inbox: DMs and comments through Outstand. Payload shapes are from
 * Outstand's docs (Oct 2026) as given in the brief; no real key was used.
 */

const INBOX_API = 'api.outstand.test/v1';

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
        'services.outstand.webhook_secret' => 'whsec_out',
        'services.outstand.private_reply_shape' => 'auto',
        'services.inbox.enabled' => true,
        'services.inbox.credits_per_reply' => 0,
        'app.frontend_url' => 'https://app.test',
    ]);
    Cache::flush();
    Workspace::forget();
});

function inboxAccount(?User $user = null, array $attrs = []): SocialAccount
{
    return SocialAccount::factory()->for($user ?? User::factory()->create())->create($attrs + [
        'platform' => 'instagram',
        'provider' => 'outstand',
        'username' => 'studio',
        'messages_scope_at' => now(),
        'inbox_synced_at' => now(),
    ]);
}

function inboxThread(SocialAccount $account, array $attrs = []): SocialConversation
{
    return SocialConversation::query()->create($attrs + [
        'user_id' => $account->user_id,
        'social_account_id' => $account->id,
        'provider' => 'outstand',
        'provider_conversation_id' => 'conv_'.str()->random(8),
        'network' => 'instagram',
        'contact_id' => 'ig_contact',
        'contact_username' => 'ana',
        'contact_name' => 'Ana',
        'last_message_at' => now()->subHour(),
        'last_inbound_at' => now()->subHour(),
        'unread_count' => 1,
        'status' => 'open',
        'messages_synced_at' => now(),
    ]);
}

function inboxComment(SocialAccount $account, array $attrs = []): SocialComment
{
    return SocialComment::query()->create($attrs + [
        'user_id' => $account->user_id,
        'social_account_id' => $account->id,
        'provider' => 'outstand',
        'provider_post_id' => 'post_enc_1',
        'platform_post_id' => '1789',
        'comment_id' => 'c_'.str()->random(8),
        'author_id' => 'ig_fan',
        'author_username' => 'fan',
        'text' => 'Love this!',
        'network' => 'instagram',
        'commented_at' => now()->subHour(),
    ]);
}

function inboxHook($test, string $event, array $data, ?string $secret = 'whsec_out', array $extra = [])
{
    $body = json_encode(['event' => $event, 'data' => $data] + $extra, JSON_THROW_ON_ERROR);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($secret !== null) {
        $server['HTTP_X_OUTSTAND_SIGNATURE'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return $test->call('POST', '/api/v1/webhooks/outstand', [], [], [], $server, $body);
}

function inboxBell(User $user): ?array
{
    return $user->notifications()->where('type', InboxActivity::class)->latest()->first()?->data;
}

describe('webhooks', function () {
    it('refuses an unsigned or mis-signed delivery', function () {
        inboxHook($this, 'message.received', ['conversationId' => 'conv_1'], null)->assertForbidden();
        inboxHook($this, 'message.received', ['conversationId' => 'conv_1'], 'wrong')->assertForbidden();

        expect(SocialMessage::count())->toBe(0);
    });

    it('files a DM from an unknown thread under its account, once, and rings the bell (no email)', function () {
        Mail::fake();
        $account = inboxAccount(attrs: ['messages_scope_at' => null]);
        Http::fake([INBOX_API.'/conversations/conv_1' => Http::response(['data' => [
            'id' => 'conv_1', 'socialAccountId' => $account->provider_account_id, 'network' => 'instagram',
            'participant' => ['id' => 'ig_ana', 'username' => 'ana', 'name' => 'Ana', 'profilePictureUrl' => 'https://cdn.test/a.jpg'],
        ]])]);
        $payload = ['conversationId' => 'conv_1', 'messageId' => 'msg_1', 'orgId' => 'org', 'network' => 'instagram',
            'content' => 'Hi! Price?', 'senderId' => 'ig_ana', 'sentAt' => now()->subMinute()->toIso8601String()];

        inboxHook($this, 'message.received', $payload)->assertOk();
        inboxHook($this, 'message.received', $payload)->assertOk(); // redelivery

        $thread = SocialConversation::sole();
        expect($thread->user_id)->toBe($account->user_id)
            ->and($thread->contact_username)->toBe('ana')
            ->and($thread->unread_count)->toBe(1)
            ->and($thread->last_inbound_at)->not->toBeNull()
            ->and($thread->messages()->count())->toBe(1)
            ->and($thread->messages()->first()->direction)->toBe('in')
            ->and(DB::table('webhook_events')->where('source', 'outstand')->count())->toBe(1)
            // A DM arriving proves the grant reads messages.
            ->and($account->fresh()->messages_scope_at)->not->toBeNull();

        expect(inboxBell($account->user))->toMatchArray(['type' => 'inbox.activity', 'messages' => 1, 'comments' => 0]);
        Mail::assertNothingOutgoing();
    });

    it('batches the bell: many arrivals, one unread row', function () {
        $account = inboxAccount();
        $a = inboxThread($account, ['provider_conversation_id' => 'conv_a']);
        $b = inboxThread($account, ['provider_conversation_id' => 'conv_b']);

        foreach ([['conv_a', 'm1'], ['conv_a', 'm2'], ['conv_b', 'm3']] as [$conv, $msg]) {
            inboxHook($this, 'message.received', ['conversationId' => $conv, 'messageId' => $msg, 'content' => 'yo', 'senderId' => 'x'])->assertOk();
        }

        expect($account->user->notifications()->count())->toBe(1)
            ->and(inboxBell($account->user))->toMatchArray(['messages' => 3, 'conversations' => 2])
            ->and(inboxBell($account->user)['line'])->toBe('3 new messages in 2 conversations')
            ->and($a->fresh()->unread_count)->toBe(3)
            ->and($b->fresh()->unread_count)->toBe(2);

        // Once read, the next arrival starts a new row.
        $account->user->unreadNotifications->markAsRead();
        inboxHook($this, 'message.received', ['conversationId' => 'conv_b', 'messageId' => 'm4', 'content' => 'again'])->assertOk();
        expect($account->user->notifications()->count())->toBe(2);
    });

    it('reopens a thread marked done when the contact writes again', function () {
        $thread = inboxThread(inboxAccount(), ['status' => 'done', 'unread_count' => 0]);

        inboxHook($this, 'message.received', ['conversationId' => $thread->provider_conversation_id, 'messageId' => 'mx', 'content' => '?'])->assertOk();

        expect($thread->fresh()->status)->toBe('open');
    });

    it('keeps a provider\'s UTC timestamp as the same instant (24h window is not shifted)', function () {
        $thread = inboxThread(inboxAccount(), ['last_inbound_at' => now()->subDays(2), 'last_message_at' => now()->subDays(2)]);
        $sent = now()->utc()->subHours(20)->startOfSecond();

        inboxHook($this, 'message.received', ['conversationId' => $thread->provider_conversation_id, 'messageId' => 'tz_1',
            'content' => 'hi', 'sentAt' => $sent->format('Y-m-d\\TH:i:s\\Z')])->assertOk();

        $thread->refresh();
        expect($thread->last_inbound_at->equalTo($sent))->toBeTrue()
            ->and($thread->messages()->sole()->sent_at->equalTo($sent))->toBeTrue()
            ->and($thread->canReplyUntil()->equalTo($sent->addDay()))->toBeTrue();
    });

    it('turns our pending message sent, then records a failure, and a read', function () {
        $thread = inboxThread(inboxAccount());
        $mine = $thread->messages()->create(['provider_message_id' => 'out_1', 'direction' => 'out', 'content' => 'Hi', 'status' => 'pending', 'source' => 'api']);
        $other = $thread->messages()->create(['provider_message_id' => 'out_2', 'direction' => 'out', 'content' => 'Hey', 'status' => 'pending', 'source' => 'api']);

        inboxHook($this, 'message.sent', ['conversationId' => $thread->provider_conversation_id, 'messageId' => 'out_1',
            'platformMessageId' => 'mid.123', 'source' => 'api', 'sentAt' => now()->toIso8601String()])->assertOk();
        inboxHook($this, 'message.failed', ['conversationId' => $thread->provider_conversation_id, 'messageId' => 'out_2',
            'error' => ['code' => 'instagram_no_inbound_message', 'message' => 'Outside the 24h window']])->assertOk();
        inboxHook($this, 'message.read', ['conversationId' => $thread->provider_conversation_id, 'readAt' => now()->addMinute()->toIso8601String()])->assertOk();

        expect($mine->fresh())->status->toBe('sent')->platform_message_id->toBe('mid.123')->read_at->not->toBeNull()
            ->and($other->fresh())->status->toBe('failed')->error->toBe('Outside the 24h window')
            ->and($thread->fresh()->unread_count)->toBe(1);
    });

    it('records a message sent from the Instagram app itself', function () {
        $thread = inboxThread(inboxAccount());

        inboxHook($this, 'message.sent', ['conversationId' => $thread->provider_conversation_id, 'messageId' => 'native_1',
            'content' => 'Sent from my phone', 'source' => 'native_app'])->assertOk();

        expect($thread->messages()->sole())->direction->toBe('out')->source->toBe('native_app')->status->toBe('sent');
    });

    it('files a comment for the account, ignores our own, and dedups', function () {
        $account = inboxAccount();
        $post = SocialPost::factory()->for($account->user)->create(['provider' => 'outstand', 'provider_post_id' => 'post_enc_1']);
        $comment = ['accountId' => $account->provider_account_id, 'postId' => 'post_enc_1', 'platformPostId' => '1789',
            'commentId' => 'c1', 'parentCommentId' => null, 'authorId' => 'ig_fan', 'authorUsername' => 'fan',
            'text' => 'Love it', 'orgId' => 'org', 'network' => 'instagram'];

        inboxHook($this, 'comment.received', $comment)->assertOk();
        inboxHook($this, 'comment.received', $comment)->assertOk();
        inboxHook($this, 'comment.received', ['commentId' => 'c2', 'authorUsername' => '@Studio', 'text' => 'Thanks!'] + $comment)->assertOk();

        expect(SocialComment::sole())->comment_id->toBe('c1')->social_post_id->toBe($post->id)->user_id->toBe($account->user_id)
            ->and(inboxBell($account->user))->toMatchArray(['comments' => 1]);
    });

    it('ignores events for accounts nobody of ours holds', function () {
        Http::fake([INBOX_API.'/*' => Http::response([], 404)]);

        inboxHook($this, 'comment.received', ['accountId' => 'acc_unknown', 'commentId' => 'c9', 'network' => 'instagram'])->assertOk();
        inboxHook($this, 'message.received', ['conversationId' => 'conv_unknown', 'messageId' => 'm9'])->assertOk();

        expect(SocialComment::count())->toBe(0)->and(SocialConversation::count())->toBe(0);
    });

    it('ignores inbox events while the Inbox is off', function () {
        config(['services.inbox.enabled' => false]);
        $thread = inboxThread(inboxAccount());

        inboxHook($this, 'message.received', ['conversationId' => $thread->provider_conversation_id, 'messageId' => 'm1'])->assertOk();

        expect(SocialMessage::count())->toBe(0);
    });
});

describe('conversations', function () {
    it('lists the owner\'s threads with filters, can_reply_until, and cursor pages', function () {
        Http::fake();
        $account = inboxAccount();
        $recent = inboxThread($account, ['last_inbound_at' => now()->subHours(2), 'last_message_at' => now()->subHours(2)]);
        $old = inboxThread($account, ['contact_username' => 'bob', 'contact_name' => 'Bob', 'unread_count' => 0,
            'status' => 'done', 'last_inbound_at' => now()->subDays(3), 'last_message_at' => now()->subDays(3)]);

        $res = $this->actingAs($account->user)->getJson('/api/v1/inbox/conversations')->assertOk();
        $res->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $recent->ulid)
            ->assertJsonPath('data.0.can_reply', true)
            ->assertJsonPath('data.0.account.id', $account->ulid)
            ->assertJsonPath('data.0.contact.username', 'ana')
            ->assertJsonPath('data.1.can_reply', false);
        expect($res->json('data.0.can_reply_until'))->toStartWith($recent->last_inbound_at->addDay()->utc()->format('Y-m-d\TH:i'));

        $this->getJson('/api/v1/inbox/conversations?unread=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $recent->ulid);
        $this->getJson('/api/v1/inbox/conversations?status=done')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->ulid);
        $this->getJson('/api/v1/inbox/conversations?q=bob')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->ulid);
        $this->getJson('/api/v1/inbox/conversations?account='.$account->ulid)->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/inbox/conversations?account=01NOTMINE0000000000000000')->assertJsonCount(0, 'data');

        $page = $this->getJson('/api/v1/inbox/conversations?per_page=1')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/inbox/conversations?per_page=1&cursor='.$page->json('meta.next_cursor'))
            ->assertJsonPath('data.0.id', $old->ulid)
            ->assertJsonPath('meta.next_cursor', null);
    });

    it('never shows account A\'s inbox to account B', function () {
        Http::fake();
        $a = inboxAccount();
        $thread = inboxThread($a);
        $comment = inboxComment($a);
        $b = User::factory()->create();

        $this->actingAs($b)->getJson('/api/v1/inbox/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/inbox/comments')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/inbox/conversations/{$thread->ulid}")->assertForbidden();
        $this->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['content' => 'hi'])->assertForbidden();
        $this->postJson("/api/v1/inbox/conversations/{$thread->ulid}/read")->assertForbidden();
        $this->patchJson("/api/v1/inbox/conversations/{$thread->ulid}", ['status' => 'done'])->assertForbidden();
        $this->postJson("/api/v1/inbox/comments/{$comment->ulid}/reply", ['content' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/inbox/comments/{$comment->ulid}/private-reply", ['content' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/inbox/comments/{$comment->ulid}/read")->assertForbidden();
        $this->getJson('/api/v1/inbox/summary')->assertJsonPath('data.total_unread', 0);

        Http::assertNothingSent();
    });

    it('lets an active team member read the owner\'s inbox; a viewer cannot reply', function () {
        Http::fake();
        $owner = User::factory()->create();
        $owner->subscriptions()->create(['plan_id' => Plan::query()->where('key', 'team')->value('id'),
            'billing_period' => 'monthly', 'seats' => 3, 'status' => 'active']);
        $thread = inboxThread(inboxAccount($owner));
        $viewer = User::factory()->create();
        TeamMember::factory()->active($viewer)->create(['owner_id' => $owner->id, 'role' => 'viewer']);
        Workspace::forget();

        $this->actingAs($viewer)->getJson('/api/v1/inbox/conversations')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/inbox/conversations/{$thread->ulid}")->assertOk();
        $this->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['content' => 'hi'])->assertForbidden();
    });

    it('reads a thread\'s messages from Outstand on first open', function () {
        $account = inboxAccount();
        $thread = inboxThread($account, ['messages_synced_at' => null, 'provider_conversation_id' => 'conv_1']);
        Http::fake([INBOX_API.'/conversations/conv_1/messages*' => Http::response(['data' => [
            ['id' => 'm2', 'direction' => 'outbound', 'content' => 'Sure, 50k', 'status' => 'sent', 'source' => 'native_app', 'sentAt' => now()->subMinutes(5)->toIso8601String()],
            ['id' => 'm1', 'direction' => 'inbound', 'content' => 'Price?', 'sentAt' => now()->subMinutes(10)->toIso8601String()],
        ]])]);

        $this->actingAs($account->user)->getJson("/api/v1/inbox/conversations/{$thread->ulid}")
            ->assertOk()
            ->assertJsonPath('data.id', $thread->ulid)
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.content', 'Price?')
            ->assertJsonPath('data.messages.1.direction', 'out')
            ->assertJsonPath('meta.has_more_messages', false);

        // Second open reads locally.
        $this->getJson("/api/v1/inbox/conversations/{$thread->ulid}")->assertOk();
        Http::assertSentCount(1);
    });

    it('replies inside the 24h window with library images as asset URLs: 202 pending', function () {
        Storage::fake('s3');
        Storage::disk('s3')->buildTemporaryUrlsUsing(fn (string $path) => "https://assets.test/{$path}?sig=1");
        $account = inboxAccount();
        $thread = inboxThread($account, ['provider_conversation_id' => 'conv_1']);
        $file = File::factory()->for($account->user)->create();
        Http::fake([INBOX_API.'/conversations/conv_1/messages' => Http::response(['data' => ['id' => 'out_9', 'status' => 'pending']], 202)]);

        $this->actingAs($account->user)
            ->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['content' => 'Here it is', 'file_ids' => [$file->ulid]])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.direction', 'out')
            ->assertJsonPath('data.media_urls.0', "https://assets.test/{$file->path}?sig=1");

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && $r['content'] === 'Here it is'
            && $r['media_urls'] === ["https://assets.test/{$file->path}?sig=1"]);
        expect($thread->messages()->sole()->provider_message_id)->toBe('out_9')
            ->and($thread->fresh()->unread_count)->toBe(0);
    });

    it('refuses library files that are not mine or not images', function () {
        Http::fake();
        $account = inboxAccount();
        $thread = inboxThread($account);
        $theirs = File::factory()->create();
        $pdf = File::factory()->for($account->user)->create(['mime_type' => 'application/pdf']);

        $this->actingAs($account->user)->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['file_ids' => [$theirs->ulid]])
            ->assertUnprocessable()->assertJsonValidationErrors('file_ids');
        $this->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['file_ids' => [$pdf->ulid]])
            ->assertUnprocessable()->assertJsonValidationErrors('file_ids');
        $this->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", [])
            ->assertUnprocessable()->assertJsonValidationErrors('content');

        Http::assertNothingSent();
    });

    it('refuses a reply after the 24h window without calling Outstand', function () {
        Http::fake();
        $account = inboxAccount();
        $thread = inboxThread($account, ['last_inbound_at' => now()->subHours(25)]);

        $this->actingAs($account->user)->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['content' => 'late'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'reply_window_closed');

        Http::assertNothingSent();
    });

    it('maps Outstand\'s 422 / 403 / 409 to clear errors', function () {
        $account = inboxAccount();
        $thread = inboxThread($account, ['provider_conversation_id' => 'conv_1']);
        $this->actingAs($account->user);
        $reply = null;
        Http::fake(function () use (&$reply) {
            return $reply;
        });

        $reply = Http::response(['error' => ['code' => 'instagram_no_inbound_message', 'message' => 'No inbound message in 24h']], 422);
        $this->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['content' => 'hi'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'reply_window_closed')
            ->assertJsonPath('provider_code', 'instagram_no_inbound_message');

        $reply = Http::response(['error' => ['code' => 'conversation_locked', 'message' => 'Locked']], 409);
        $this->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['content' => 'hi'])
            ->assertStatus(409)->assertJsonPath('code', 'conflict');

        $reply = Http::response(['error' => ['code' => 'insufficient_scope']], 403);
        $this->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['content' => 'hi'])
            ->assertForbidden()
            ->assertJsonPath('code', 'permission_missing')
            ->assertJsonPath('needs_reconnect', true);

        expect($account->fresh()->messages_scope_at)->toBeNull()
            ->and(SocialMessage::count())->toBe(0);
        Http::assertSentCount(3);

        // Now the account itself says reconnect, before any call.
        $this->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['content' => 'hi'])
            ->assertForbidden()->assertJsonPath('code', 'permission_missing');
        Http::assertSentCount(3);
    });

    it('marks a thread read at Outstand and here', function () {
        $account = inboxAccount();
        $thread = inboxThread($account, ['provider_conversation_id' => 'conv_1', 'unread_count' => 4]);
        Http::fake([INBOX_API.'/conversations/conv_1/read' => Http::response(['success' => true])]);

        $this->actingAs($account->user)->postJson("/api/v1/inbox/conversations/{$thread->ulid}/read")
            ->assertOk()->assertJsonPath('data.unread_count', 0);

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/conversations/conv_1/read'));
    });

    it('sets a thread\'s status', function () {
        $account = inboxAccount();
        $thread = inboxThread($account);

        $this->actingAs($account->user)->patchJson("/api/v1/inbox/conversations/{$thread->ulid}", ['status' => 'done'])
            ->assertOk()->assertJsonPath('data.status', 'done');
        $this->patchJson("/api/v1/inbox/conversations/{$thread->ulid}", ['status' => 'archived'])
            ->assertUnprocessable();
    });

    it('says plainly that a Post for Me account has no Inbox', function () {
        $account = inboxAccount(attrs: ['provider' => 'postforme']);
        $thread = inboxThread($account, ['provider' => 'postforme']);
        Http::fake();

        $this->actingAs($account->user)->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['content' => 'hi'])
            ->assertUnprocessable()->assertJsonPath('code', 'inbox_unsupported');
        Http::assertNothingSent();
    });
});

describe('comments', function () {
    it('lists comments with filters', function () {
        Http::fake();
        $account = inboxAccount();
        $post = SocialPost::factory()->for($account->user)->create(['provider' => 'outstand', 'provider_post_id' => 'post_enc_1']);
        $open = inboxComment($account, ['social_post_id' => $post->id]);
        inboxComment($account, ['replied_at' => now(), 'commented_at' => now()->subDays(2)]);

        $this->actingAs($account->user)->getJson('/api/v1/inbox/comments')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $open->ulid)
            ->assertJsonPath('data.0.post_id', $post->ulid)
            ->assertJsonPath('data.0.author.username', 'fan')
            ->assertJsonPath('data.0.can_private_reply', true);
        $this->getJson('/api/v1/inbox/comments?unreplied=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open->ulid);
        $this->getJson('/api/v1/inbox/comments?post='.$post->ulid)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/inbox/comments?account='.$account->ulid)->assertJsonCount(2, 'data');
    });

    it('replies in public, threaded under the comment, and skips its own echo', function () {
        $account = inboxAccount();
        $comment = inboxComment($account);
        Http::fake([INBOX_API.'/posts/post_enc_1/replies' => Http::response(['data' => ['id' => 'reply_77']], 201)]);

        $this->actingAs($account->user)->postJson("/api/v1/inbox/comments/{$comment->ulid}/reply", ['content' => 'Thank you!'])
            ->assertOk()->assertJsonPath('data.replied_at', fn ($v) => $v !== null);

        Http::assertSent(fn (HttpRequest $r) => $r['content'] === 'Thank you!'
            && $r['account_username'] === 'studio'
            && $r['parent_comment_id'] === $comment->comment_id);

        // Outstand echoes our reply as comment.received — not news.
        inboxHook($this, 'comment.received', ['accountId' => $account->provider_account_id, 'commentId' => 'reply_77',
            'authorUsername' => 'someone_else', 'postId' => 'post_enc_1', 'network' => 'instagram'])->assertOk();
        expect(SocialComment::count())->toBe(1);
    });

    it('sends the private reply nested first, falls back to top level, and only once', function () {
        $account = inboxAccount();
        $comment = inboxComment($account);
        Http::fake([INBOX_API.'/conversations' => Http::sequence()
            ->push(['error' => ['code' => 'validation_error', 'message' => 'comment_id is required']], 422)
            ->push(['data' => ['id' => 'conv_new', 'messageId' => 'pm_1']], 201)]);

        $this->actingAs($account->user)->postJson("/api/v1/inbox/comments/{$comment->ulid}/private-reply", ['content' => 'DMing you the price'])
            ->assertOk()
            ->assertJsonPath('data.can_private_reply', false)
            ->assertJsonPath('conversation.contact.username', 'fan');

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();
        expect($sent[0])->toMatchArray(['social_account_id' => $account->provider_account_id, 'instagram' => ['comment_id' => $comment->comment_id, 'author_id' => 'ig_fan']])
            ->and($sent[1])->toMatchArray(['comment_id' => $comment->comment_id, 'content' => 'DMing you the price']);

        $thread = SocialConversation::sole();
        expect($comment->fresh()->private_reply_conversation_id)->toBe($thread->id)
            ->and($thread->messages()->sole()->status)->toBe('pending');

        $this->postJson("/api/v1/inbox/comments/{$comment->ulid}/private-reply", ['content' => 'again'])
            ->assertStatus(409)->assertJsonPath('code', 'already_replied');
    });

    it('uses only the configured private reply shape', function () {
        config(['services.outstand.private_reply_shape' => 'top_level']);
        $account = inboxAccount();
        $comment = inboxComment($account);
        Http::fake([INBOX_API.'/conversations' => Http::response(['data' => ['id' => 'conv_new']], 201)]);

        $this->actingAs($account->user)->postJson("/api/v1/inbox/comments/{$comment->ulid}/private-reply", ['content' => 'hi'])->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r['comment_id'] === $comment->comment_id && ! isset($r['instagram']));
    });

    it('maps a provider 409 on a private reply, and refuses one after 7 days or on Facebook', function () {
        $account = inboxAccount();
        $this->actingAs($account->user);

        Http::fake([INBOX_API.'/conversations' => Http::response(['error' => ['code' => 'private_reply_exists']], 409)]);
        $this->postJson('/api/v1/inbox/comments/'.inboxComment($account)->ulid.'/private-reply', ['content' => 'hi'])
            ->assertStatus(409)->assertJsonPath('code', 'already_replied');

        Http::fake();
        $this->postJson('/api/v1/inbox/comments/'.inboxComment($account, ['commented_at' => now()->subDays(8)])->ulid.'/private-reply', ['content' => 'hi'])
            ->assertUnprocessable()->assertJsonPath('code', 'private_reply_window_closed');
        $this->postJson('/api/v1/inbox/comments/'.inboxComment($account, ['network' => 'facebook'])->ulid.'/private-reply', ['content' => 'hi'])
            ->assertUnprocessable()->assertJsonPath('code', 'private_reply_unsupported');
        Http::assertNothingSent();
    });

    it('marks a comment read', function () {
        $account = inboxAccount();
        $comment = inboxComment($account);

        $this->actingAs($account->user)->postJson("/api/v1/inbox/comments/{$comment->ulid}/read")
            ->assertOk()->assertJsonPath('data.read_at', fn ($v) => $v !== null);
    });
});

describe('summary and settings', function () {
    it('counts unread for the badges', function () {
        $account = inboxAccount();
        inboxThread($account, ['unread_count' => 3]);
        inboxThread($account, ['unread_count' => 0]);
        inboxComment($account);
        inboxComment($account, ['read_at' => now(), 'replied_at' => now()]);

        $this->actingAs($account->user)->getJson('/api/v1/inbox/summary')
            ->assertOk()
            ->assertJsonPath('data.unread_conversations', 1)
            ->assertJsonPath('data.unread_messages', 3)
            ->assertJsonPath('data.unread_comments', 1)
            ->assertJsonPath('data.unreplied_comments', 1)
            ->assertJsonPath('data.total_unread', 2)
            ->assertJsonPath('data.accounts.0.id', $account->ulid);
    });

    it('answers 404 everywhere while INBOX_ENABLED is off', function () {
        config(['services.inbox.enabled' => false]);
        $this->actingAs(User::factory()->create())->getJson('/api/v1/inbox/summary')
            ->assertNotFound()->assertJsonPath('code', 'inbox_disabled');
    });

    it('requires a session', function () {
        $this->getJson('/api/v1/inbox/conversations')->assertUnauthorized();
    });

    it('charges CREDITS_PER_REPLY and gives it back when Instagram refuses', function () {
        config(['services.inbox.credits_per_reply' => 50]);
        $account = inboxAccount();
        Credits::grant($account->user, 100, 'Test');
        $thread = inboxThread($account, ['provider_conversation_id' => 'conv_1']);
        $this->actingAs($account->user);

        $reply = Http::response(['data' => ['id' => 'o1']], 202);
        Http::fake(function () use (&$reply) {
            return $reply;
        });
        $this->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['content' => 'hi'])->assertStatus(202);
        expect(Credits::balance($account->user))->toBe(50);

        $reply = Http::response(['error' => ['code' => 'instagram_no_inbound_message']], 422);
        $this->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['content' => 'hi'])->assertUnprocessable();
        expect(Credits::balance($account->user))->toBe(50);

        // 50 left: one more reply, then the balance is short.
        $reply = Http::response(['data' => ['id' => 'o2']], 202);
        $this->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['content' => 'hi'])->assertStatus(202);
        $this->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['content' => 'hi'])
            ->assertUnprocessable()->assertJsonValidationErrors('content');
        expect(Credits::balance($account->user))->toBe(0);
        Http::assertSentCount(3);
    });

    it('asks Instagram for the full default scopes plus messages, then marks the account', function () {
        config(['services.outstand.instagram_scopes' => ['instagram_business_basic', 'instagram_business_content_publish']]);
        $user = User::factory()->create();
        $old = inboxAccount($user, ['messages_scope_at' => null, 'provider_account_id' => 'acc_old']);
        $this->actingAs($user)->getJson('/api/v1/social-accounts')
            ->assertJsonPath('data.0.messaging_enabled', false)
            ->assertJsonPath('data.0.needs_reconnect_for_messages', true);

        Http::fake([INBOX_API.'/social-networks/instagram/auth-url' => Http::response(['data' => ['auth_url' => 'https://outstand.test/o']])]);
        $this->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'instagram'])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r['scopes'] === [
            'instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_messages',
        ]);

        Http::fake([INBOX_API.'/social-accounts*' => Http::response(['data' => [
            ['id' => 'acc_old', 'network' => 'instagram', 'username' => 'studio', 'tenant_id' => $user->ulid, 'isActive' => 1],
        ]])]);
        $this->postJson('/api/v1/social-accounts/sync')
            ->assertOk()
            ->assertJsonPath('data.0.messaging_enabled', true)
            ->assertJsonPath('data.0.needs_reconnect_for_messages', false);
        expect($old->fresh()->messages_scope_at)->not->toBeNull();
    });

    it('does not add scopes for other networks', function () {
        Http::fake([INBOX_API.'/*' => Http::response(['data' => ['auth_url' => 'https://outstand.test/o']])]);

        $this->actingAs(User::factory()->create())->postJson('/api/v1/social-accounts/auth-url', ['platform' => 'tiktok'])->assertOk();

        Http::assertSent(fn (HttpRequest $r) => ! isset($r['scopes']));
    });
});

describe('sync', function () {
    it('backfills threads, their messages and recent post comments on first open', function () {
        $account = inboxAccount(attrs: ['inbox_synced_at' => null, 'messages_scope_at' => null]);
        $post = SocialPost::factory()->for($account->user)->published()->create(['provider' => 'outstand', 'provider_post_id' => 'post_enc_1']);
        $post->targets()->create(['social_account_id' => $account->id]);
        Http::fake([
            INBOX_API.'/conversations/conv_1/messages*' => Http::response(['data' => [
                ['id' => 'm1', 'direction' => 'inbound', 'content' => 'Hello', 'sentAt' => now()->subMinutes(3)->toIso8601String()],
            ]]),
            INBOX_API.'/conversations?*' => Http::response(['data' => [
                ['id' => 'conv_1', 'participant' => ['username' => 'ana'], 'lastMessageAt' => now()->subMinutes(3)->toIso8601String(), 'unreadCount' => 1],
            ]]),
            INBOX_API.'/posts/post_enc_1/replies*' => Http::response(['data' => [
                ['id' => 'c1', 'text' => 'Nice', 'author' => ['id' => 'ig_fan', 'username' => 'fan'], 'createdAt' => now()->subHour()->toIso8601String(),
                    'replies' => [['id' => 'c1r', 'text' => 'Thanks', 'author' => ['username' => 'studio']], ['id' => 'c1s', 'text' => '+1', 'author' => ['username' => 'bob']]]],
            ]]),
        ]);

        $this->actingAs($account->user)->getJson('/api/v1/inbox/conversations')->assertOk();

        $thread = SocialConversation::sole();
        expect($thread->contact_username)->toBe('ana')
            ->and($thread->unread_count)->toBe(1)
            ->and($thread->messages()->sole()->content)->toBe('Hello')
            ->and($thread->last_inbound_at)->not->toBeNull()
            ->and(SocialComment::pluck('comment_id')->sort()->values()->all())->toBe(['c1', 'c1s'])
            ->and(SocialComment::where('comment_id', 'c1s')->value('parent_comment_id'))->toBe('c1')
            ->and($account->fresh()->inbox_synced_at)->not->toBeNull()
            ->and($account->fresh()->messages_scope_at)->not->toBeNull()
            // A backfill is not news.
            ->and($account->user->notifications()->count())->toBe(0);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/conversations?')
            && $r['social_account_id'] === $account->provider_account_id && $r['network'] === 'instagram');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/posts/post_enc_1/replies') && $r['network'] === 'instagram');
    });

    it('stops calling when X-RateLimit-Remaining hits 0, until the reset', function () {
        $account = inboxAccount();
        $reply = Http::response(['data' => []], 200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '120']);
        Http::fake(function () use (&$reply) {
            return $reply;
        });

        (new SyncInbox($account->id))->handle(app(SocialProviders::class));
        Http::assertSentCount(1);

        expect(fn () => (new SyncInbox($account->id))->handle(app(SocialProviders::class)))
            ->toThrow(ProviderRateLimited::class);
        Http::assertSentCount(1);

        $this->travel(3)->minutes();
        $reply = Http::response(['data' => []]);
        (new SyncInbox($account->id))->handle(app(SocialProviders::class));
        Http::assertSentCount(2);
    });

    it('honours Retry-After on a 429', function () {
        $account = inboxAccount();
        Http::fake([INBOX_API.'/*' => Http::response(['error' => 'rate_limited'], 429, ['Retry-After' => '30'])]);

        try {
            (new SyncInbox($account->id))->handle(app(SocialProviders::class));
            $this->fail('expected a rate limit');
        } catch (ProviderRateLimited $e) {
            expect($e->retryAfter)->toBe(30);
        }

        // Released, not retried in a loop: one call only.
        Http::assertSentCount(1);
    });

    it('marks an account for reconnect when Outstand refuses its threads with 403', function () {
        $account = inboxAccount();
        Http::fake([INBOX_API.'/conversations*' => Http::response(['error' => 'insufficient_scope'], 403)]);

        (new SyncInbox($account->id))->handle(app(SocialProviders::class));

        expect($account->fresh())->messages_scope_at->toBeNull()
            ->and($account->fresh()->needsReconnectForMessages())->toBeTrue();
    });

    it('queues the hourly sync only for accounts whose Inbox was opened', function () {
        Queue::fake();
        $opened = inboxAccount();
        inboxAccount(attrs: ['inbox_synced_at' => null]);

        (new SyncInboxes)->handle();

        Queue::assertPushed(SyncInbox::class, 1);
        Queue::assertPushed(SyncInbox::class, fn (SyncInbox $j) => $j->accountId === $opened->id);
    });
});
