<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use App\Models\User;
use App\Support\Credits;
use App\Support\Workspace;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/*
 * Engage for connected apps (MCP list_conversations / reply_to_conversation):
 * GET /inbox/feed, GET /inbox/feed/{id}, POST /inbox/feed/{id}/reply —
 * exercised through real API keys, the way the MCP server calls them.
 */

beforeEach(function () {
    ensurePassportKeys();
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
        'app.frontend_url' => 'https://app.test',
    ]);
    Cache::flush();
    Workspace::forget();
    Queue::fake();
});

function feedKey(TestCase $test, User $user, array $scopes = ['read', 'publish']): string
{
    $key = $test->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Engage key', 'scopes' => $scopes])
        ->assertCreated()
        ->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

function feedAccount(User $user, array $attrs = []): SocialAccount
{
    return SocialAccount::factory()->for($user)->create($attrs + [
        'platform' => 'instagram',
        'provider' => 'outstand',
        'username' => 'studio',
        'messages_scope_at' => now(),
        'inbox_synced_at' => now(),
    ]);
}

function feedThread(SocialAccount $account, array $attrs = []): SocialConversation
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
        'last_message_preview' => 'Is the studio open Sunday?',
        'last_message_at' => now()->subHour(),
        'last_inbound_at' => now()->subHour(),
        'unread_count' => 1,
        'status' => 'open',
        'messages_synced_at' => now(),
    ]);
}

function feedComment(SocialAccount $account, array $attrs = []): SocialComment
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
        'commented_at' => now()->subMinutes(30),
    ]);
}

function feedPaid(User $user): void
{
    $user->subscriptions()->create([
        'plan_id' => Plan::query()->where('key', 'pro')->value('id'),
        'billing_period' => 'monthly',
        'seats' => 1,
        'status' => 'active',
    ]);
    Workspace::forget();
}

describe('GET /inbox/feed (list_conversations)', function () {
    it('lists comments and DMs together, newest first, with author, last message, time and post link', function () {
        $user = User::factory()->create();
        feedPaid($user);
        $account = feedAccount($user);
        $thread = feedThread($account);
        $comment = feedComment($account);

        $res = bearer($this, feedKey($this, $user, ['read']))
            ->getJson('/api/v1/inbox/feed')
            ->assertOk();

        expect($res->json('data'))->toHaveCount(2)
            ->and($res->json('data.0'))->toMatchArray([
                'id' => 'comment_'.$comment->ulid,
                'type' => 'comment',
                'last_message' => 'Love this!',
                'unread' => true,
                'post_url' => 'https://www.instagram.com/studio/',
            ])
            ->and($res->json('data.0.author.name'))->toBe('fan')
            ->and($res->json('data.0.channel'))->toMatchArray(['id' => $account->ulid, 'platform' => 'instagram', 'username' => 'studio'])
            ->and($res->json('data.1'))->toMatchArray([
                'id' => 'dm_'.$thread->ulid,
                'type' => 'dm',
                'last_message' => 'Is the studio open Sunday?',
                'post_url' => null,
            ])
            ->and($res->json('data.1.author.name'))->toBe('Ana')
            ->and($res->json('data.1.time'))->not->toBeNull()
            ->and($res->json('meta.next_cursor'))->toBeNull();
    });

    it('filters by type, channel, unread and since', function () {
        $user = User::factory()->create();
        $ig = feedAccount($user);
        $fb = feedAccount($user, ['platform' => 'facebook', 'username' => 'studio.fb']);
        $readDm = feedThread($ig, ['unread_count' => 0, 'last_message_at' => now()->subDays(3)]);
        $unreadDm = feedThread($ig);
        $fbComment = feedComment($fb, ['network' => 'facebook']);
        feedComment($ig, ['read_at' => now()]);

        $client = bearer($this, feedKey($this, $user, ['read']));
        $ids = fn (string $q) => array_column($client->getJson('/api/v1/inbox/feed?'.$q)->assertOk()->json('data'), 'id');

        expect($ids('type=dm'))->toBe(['dm_'.$unreadDm->ulid, 'dm_'.$readDm->ulid])
            ->and($ids('type=comment&account='.$fb->ulid))->toBe(['comment_'.$fbComment->ulid])
            ->and($ids('unread=1'))->toBe(['comment_'.$fbComment->ulid, 'dm_'.$unreadDm->ulid])
            ->and($ids('type=dm&since='.urlencode(now()->subDay()->toIso8601String())))->toBe(['dm_'.$unreadDm->ulid]);
    });

    it('pages through both kinds with a cursor, every item once', function () {
        $user = User::factory()->create();
        $account = feedAccount($user);
        $expected = [];
        foreach (range(1, 4) as $i) {
            $expected[] = 'dm_'.feedThread($account, ['last_message_at' => now()->subMinutes($i * 10)])->ulid;
            $expected[] = 'comment_'.feedComment($account, ['commented_at' => now()->subMinutes($i * 10 + 5)])->ulid;
        }
        // Same instant for a DM and a comment: the order still holds.
        $tieAt = now()->subHours(5);
        $expected[] = 'dm_'.feedThread($account, ['last_message_at' => $tieAt])->ulid;
        $expected[] = 'comment_'.feedComment($account, ['commented_at' => $tieAt])->ulid;

        $client = bearer($this, feedKey($this, $user, ['read']));
        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $res = $client->getJson('/api/v1/inbox/feed?per_page=3'.($cursor ? '&cursor='.$cursor : ''))->assertOk();
            $seen = [...$seen, ...array_column($res->json('data'), 'id')];
            $cursor = $res->json('meta.next_cursor');
            $pages++;
        } while ($cursor !== null && $pages < 10);

        expect($seen)->toBe($expected)->and($pages)->toBe(4);
    });

    it('refuses a forged cursor with a readable 422', function () {
        $user = User::factory()->create();
        bearer($this, feedKey($this, $user, ['read']))
            ->getJson('/api/v1/inbox/feed?cursor=bm9wZQ')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');
    });

    it('never lists another workspace\'s conversations', function () {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        feedThread(feedAccount($theirs));
        feedComment(feedAccount($theirs));

        bearer($this, feedKey($this, $mine, ['read']))
            ->getJson('/api/v1/inbox/feed')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('is on every plan, as Engage is in the app: a Free key lists (no 402)', function () {
        $user = User::factory()->create(); // no subscription = Free
        feedThread(feedAccount($user));

        bearer($this, feedKey($this, $user, ['read']))
            ->getJson('/api/v1/inbox/feed')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

describe('GET /inbox/feed/{id} (the reply preview)', function () {
    it('says what a reply would be and sends nothing', function () {
        Http::fake();
        $user = User::factory()->create();
        $thread = feedThread(feedAccount($user));

        bearer($this, feedKey($this, $user, ['read']))
            ->getJson('/api/v1/inbox/feed/dm_'.$thread->ulid)
            ->assertOk()
            ->assertJsonPath('data.id', 'dm_'.$thread->ulid)
            ->assertJsonPath('data.reply.can_reply', true)
            ->assertJsonPath('data.reply.kind', 'direct_message')
            ->assertJsonPath('data.reply.max_length', 1000)
            ->assertJsonPath('data.reply.credit_cost', 0);

        Http::assertNothingSent();
        expect(SocialMessage::query()->count())->toBe(0);
    });

    it('names what blocks a reply: the 24h window', function () {
        $user = User::factory()->create();
        $thread = feedThread(feedAccount($user), ['last_inbound_at' => now()->subDays(2)]);

        bearer($this, feedKey($this, $user, ['read']))
            ->getJson('/api/v1/inbox/feed/dm_'.$thread->ulid)
            ->assertOk()
            ->assertJsonPath('data.reply.can_reply', false)
            ->assertJsonPath('data.reply.blocked.code', 'reply_window_closed');
    });

    it('answers 404 for another workspace\'s conversation, a malformed id, and a missing one', function () {
        $mine = User::factory()->create();
        $theirs = feedThread(feedAccount(User::factory()->create()));
        $theirComment = feedComment(feedAccount(User::factory()->create()));
        $client = bearer($this, feedKey($this, $mine, ['read']));

        foreach (['dm_'.$theirs->ulid, 'comment_'.$theirComment->ulid, 'comment_'.$theirs->ulid, 'dm_nope', $theirs->ulid] as $id) {
            $client->getJson('/api/v1/inbox/feed/'.$id)->assertNotFound()->assertJsonPath('code', 'not_found');
        }
    });
});

describe('POST /inbox/feed/{id}/reply (reply_to_conversation, confirmed)', function () {
    it('sends a DM through the relay: 202 pending, thread read', function () {
        Http::fake(['api.outstand.test/v1/conversations/*/messages' => Http::response(['data' => ['id' => 'msg_9', 'status' => 'pending']], 201)]);
        $user = User::factory()->create();
        $thread = feedThread(feedAccount($user));

        bearer($this, feedKey($this, $user))
            ->postJson('/api/v1/inbox/feed/dm_'.$thread->ulid.'/reply', ['content' => 'Yes, 10 to 4!'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.content', 'Yes, 10 to 4!');

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/conversations/'.$thread->provider_conversation_id.'/messages')
            && $r['content'] === 'Yes, 10 to 4!');
        expect($thread->fresh()->unread_count)->toBe(0);
    });

    it('answers a comment in public, threaded under it', function () {
        Http::fake(['api.outstand.test/v1/posts/post_enc_1/replies' => Http::response(['data' => ['id' => 'reply_1']], 201)]);
        $user = User::factory()->create();
        $comment = feedComment(feedAccount($user));

        bearer($this, feedKey($this, $user))
            ->postJson('/api/v1/inbox/feed/comment_'.$comment->ulid.'/reply', ['content' => 'Thank you!'])
            ->assertOk()
            ->assertJsonPath('data.id', $comment->ulid);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/posts/post_enc_1/replies')
            && $r['parent_comment_id'] === $comment->comment_id && $r['content'] === 'Thank you!');
        expect($comment->fresh()->replied_at)->not->toBeNull();
    });

    it('is on every plan, as Engage is in the app: a Free key replies (no 402)', function () {
        Http::fake(['api.outstand.test/v1/conversations/*/messages' => Http::response(['data' => ['id' => 'msg_f']], 201)]);
        $user = User::factory()->create();
        $thread = feedThread(feedAccount($user));

        bearer($this, feedKey($this, $user))
            ->postJson('/api/v1/inbox/feed/dm_'.$thread->ulid.'/reply', ['content' => 'Hi'])
            ->assertStatus(202);
    });

    it('needs the publish scope; read alone sends nothing', function () {
        Http::fake();
        $user = User::factory()->create();
        $thread = feedThread(feedAccount($user));

        bearer($this, feedKey($this, $user, ['read']))
            ->postJson('/api/v1/inbox/feed/dm_'.$thread->ulid.'/reply', ['content' => 'Hi'])
            ->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope');

        Http::assertNothingSent();
    });

    it('answers 404 for another workspace\'s conversation and sends nothing', function () {
        Http::fake();
        $mine = User::factory()->create();
        $theirs = feedThread(feedAccount(User::factory()->create()));

        bearer($this, feedKey($this, $mine))
            ->postJson('/api/v1/inbox/feed/dm_'.$theirs->ulid.'/reply', ['content' => 'Hi'])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        Http::assertNothingSent();
    });

    it('turns a relay failure into a readable error and gives the Credits back', function () {
        config(['services.inbox.credits_per_reply' => 5]);
        Http::fake(['api.outstand.test/v1/conversations/*/messages' => Http::response(['error' => 'upstream down'], 503)]);
        $user = User::factory()->create();
        Credits::grant($user, 100, 'Test');
        $before = Credits::total($user);
        $thread = feedThread(feedAccount($user));

        bearer($this, feedKey($this, $user))
            ->postJson('/api/v1/inbox/feed/dm_'.$thread->ulid.'/reply', ['content' => 'Hi'])
            ->assertStatus(502)
            ->assertJsonPath('code', 'provider_error')
            ->assertJsonPath('message', 'Instagram could not be reached just now. Try again shortly.');

        expect(Credits::total($user))->toBe($before)
            ->and(SocialMessage::query()->count())->toBe(0);
    });

    it('turns a relay that does not answer at all into the same readable 502', function () {
        Http::fake(['api.outstand.test/v1/posts/*' => Http::failedConnection()]);
        $user = User::factory()->create();
        $comment = feedComment(feedAccount($user));

        bearer($this, feedKey($this, $user))
            ->postJson('/api/v1/inbox/feed/comment_'.$comment->ulid.'/reply', ['content' => 'Hi'])
            ->assertStatus(502)
            ->assertJsonPath('code', 'provider_error');

        expect($comment->fresh()->replied_at)->toBeNull();
    });

    it('takes text only', function () {
        Http::fake();
        $user = User::factory()->create();
        $thread = feedThread(feedAccount($user));

        bearer($this, feedKey($this, $user))
            ->postJson('/api/v1/inbox/feed/dm_'.$thread->ulid.'/reply', ['content' => 'Hi', 'file_ids' => ['x']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file_ids');

        Http::assertNothingSent();
    });
});
