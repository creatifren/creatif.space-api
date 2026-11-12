<?php

use App\Enums\SocialAccountStatus;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use App\Models\User;
use App\Support\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * P1 privacy (QA, 13 Nov): Rizal disconnected an Instagram channel, but
 * list_conversations still returned its old DMs to every connected AI.
 * A disconnected channel's DMs, messages and comments are hidden at once
 * (app API and MCP's /inbox/feed) and deleted (retention: none). After a
 * reconnect only what the next sync brings in is listed.
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
    Http::fake(['api.outstand.test/*' => Http::response(['success' => true, 'data' => []])]);
    Cache::flush();
    Workspace::forget();
});

/** A user with two Instagram channels, each with a DM thread (two messages) and a comment. */
function edWorld(): array
{
    $user = User::factory()->create();
    $make = fn (string $name) => SocialAccount::factory()->for($user)->create([
        'platform' => 'instagram', 'provider' => 'outstand', 'username' => $name,
        'provider_account_id' => 'acc_'.$name, 'messages_scope_at' => now(), 'inbox_synced_at' => now(),
    ]);
    $gone = $make('mrizalpurnama_');
    $kept = $make('studio');

    foreach ([$gone, $kept] as $account) {
        $thread = SocialConversation::query()->create([
            'user_id' => $user->id, 'social_account_id' => $account->id, 'provider' => 'outstand',
            'provider_conversation_id' => 'conv_'.$account->username, 'network' => 'instagram',
            'contact_id' => 'ig_'.$account->username, 'contact_username' => 'fan_of_'.$account->username,
            'last_message_at' => now()->subHour(), 'last_inbound_at' => now()->subHour(),
            'unread_count' => 1, 'status' => 'open', 'messages_synced_at' => now(),
        ]);
        foreach (['m1', 'm2'] as $m) {
            SocialMessage::query()->create([
                'social_conversation_id' => $thread->id, 'provider_message_id' => $m.'_'.$account->username,
                'direction' => 'in', 'content' => 'private words', 'status' => 'received',
            ]);
        }
        SocialComment::query()->create([
            'user_id' => $user->id, 'social_account_id' => $account->id, 'provider' => 'outstand',
            'provider_post_id' => 'post_'.$account->username, 'platform_post_id' => '1789',
            'comment_id' => 'c_'.$account->username, 'author_id' => 'ig_fan', 'author_username' => 'fan',
            'text' => 'Love this!', 'network' => 'instagram', 'commented_at' => now()->subHour(),
        ]);
    }

    return [$user, $gone, $kept];
}

function edThreadOf(SocialAccount $a): ?SocialConversation
{
    return SocialConversation::query()->where('social_account_id', $a->id)->first();
}

it('disconnect: the channel\'s DMs, messages and comments are deleted; the other channel is untouched', function () {
    [$user, $gone, $kept] = edWorld();

    $this->actingAs($user)->deleteJson("/api/v1/social-accounts/{$gone->ulid}")->assertNoContent();

    expect(SocialConversation::query()->where('social_account_id', $gone->id)->count())->toBe(0)
        ->and(SocialComment::query()->where('social_account_id', $gone->id)->count())->toBe(0)
        ->and(SocialMessage::query()->count())->toBe(2) // only the kept channel's two
        ->and($gone->fresh()->inbox_synced_at)->toBeNull()
        ->and(SocialConversation::query()->where('social_account_id', $kept->id)->count())->toBe(1)
        ->and(SocialComment::query()->where('social_account_id', $kept->id)->count())->toBe(1);

    // Engage (app) and the MCP feed list only the connected channel.
    $this->actingAs($user)->getJson('/api/v1/inbox/conversations')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.account.id', $kept->ulid);
    $this->getJson('/api/v1/inbox/comments')->assertOk()->assertJsonCount(1, 'data');
    $feed = $this->getJson('/api/v1/inbox/feed')->assertOk()->json('data');
    expect(collect($feed)->pluck('channel.id')->unique()->values()->all())->toBe([$kept->ulid]);
});

it('hides a disconnected channel\'s data at once, even before it is purged (a bulk status update)', function () {
    [$user, $gone, $kept] = edWorld();
    $thread = edThreadOf($gone);
    $comment = SocialComment::query()->where('social_account_id', $gone->id)->sole();
    // A bulk update fires no model event: the rows are still there.
    SocialAccount::query()->whereKey($gone->id)->update(['status' => SocialAccountStatus::Disconnected->value]);
    expect(SocialConversation::query()->whereKey($thread->id)->exists())->toBeTrue();

    $this->actingAs($user);
    $this->getJson('/api/v1/inbox/conversations')->assertJsonCount(1, 'data')->assertJsonPath('data.0.account.id', $kept->ulid);
    $this->getJson('/api/v1/inbox/conversations?account='.$gone->ulid)->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/inbox/comments')->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/inbox/conversations/{$thread->ulid}")->assertNotFound();
    $this->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['content' => 'hi'])->assertNotFound();
    $this->postJson("/api/v1/inbox/comments/{$comment->ulid}/reply", ['content' => 'hi'])->assertNotFound();
    $this->getJson("/api/v1/inbox/feed/dm_{$thread->ulid}")->assertNotFound();
    $this->getJson("/api/v1/inbox/feed/comment_{$comment->ulid}")->assertNotFound();
    $this->postJson("/api/v1/inbox/feed/dm_{$thread->ulid}/reply", ['content' => 'hi'])->assertNotFound();
    expect(collect($this->getJson('/api/v1/inbox/feed')->json('data'))->pluck('channel.id')->unique()->values()->all())->toBe([$kept->ulid]);
    $summary = $this->getJson('/api/v1/inbox/summary')->assertOk()->json('data');
    expect(json_encode($summary))->not->toContain($gone->ulid);

    // The daily sweep deletes what the bulk update left.
    $this->artisan('inbox:purge-disconnected')->assertSuccessful();
    expect(SocialConversation::query()->whereKey($thread->id)->exists())->toBeFalse()
        ->and(SocialComment::query()->whereKey($comment->id)->exists())->toBeFalse()
        ->and(SocialConversation::query()->where('social_account_id', $kept->id)->count())->toBe(1);
});

it('reaches MCP list_conversations (GET /inbox/feed) through a token as well', function () {
    ensurePassportKeys();
    [$user, $gone, $kept] = edWorld();
    $gone->update(['status' => SocialAccountStatus::Disconnected]);
    $key = $this->actingAs($user, 'web')->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'scopes' => ['read']])
        ->assertCreated()->json('data.key');
    app('auth')->forgetGuards();

    $feed = bearer($this, $key)->getJson('/api/v1/inbox/feed')->assertOk()->json('data');
    expect($feed)->toHaveCount(2) // the kept channel's DM and comment
        ->and(collect($feed)->pluck('channel.id')->unique()->values()->all())->toBe([$kept->ulid]);
});

it('reconnect: nothing from before comes back; only what the next sync brings in is listed', function () {
    [$user, $gone] = edWorld();
    $gone->update(['status' => SocialAccountStatus::Disconnected]);

    // The same row comes back connected (the relay reports it again).
    $gone->update(['status' => SocialAccountStatus::Connected]);
    expect($gone->fresh()->inbox_synced_at)->toBeNull(); // the first sync after the reconnect backfills afresh
    \Illuminate\Support\Facades\Queue::fake(); // that backfill is the relay's; here only what is stored is read
    $this->actingAs($user)->getJson('/api/v1/inbox/conversations?account='.$gone->ulid)->assertJsonCount(0, 'data');

    // A thread the sync brings in after the reconnect is listed.
    SocialConversation::query()->create([
        'user_id' => $user->id, 'social_account_id' => $gone->id, 'provider' => 'outstand',
        'provider_conversation_id' => 'conv_new', 'network' => 'instagram', 'contact_id' => 'ig_new',
        'contact_username' => 'new_fan', 'last_message_at' => now(), 'unread_count' => 1, 'status' => 'open',
    ]);
    $this->getJson('/api/v1/inbox/conversations?account='.$gone->ulid)
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.contact.username', 'new_fan');
});

it('files no webhook into a disconnected channel', function () {
    [, $gone] = edWorld();
    $gone->update(['status' => SocialAccountStatus::Disconnected]);

    [$account] = \App\Support\InboxStore::resolveAccount(app(\App\Support\SocialProviders::class)->for($gone), 'acc_mrizalpurnama_', 'instagram');

    expect($account)->toBeNull();
});
