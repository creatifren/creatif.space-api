<?php

use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;
use App\Support\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/* Backlog #2: AI reply suggestions — Pro/Max, 300 Credits a tap, 3 replies,
   Credits back when the AI does not answer. */

beforeEach(function () {
    config([
        'services.social.provider' => 'outstand',
        'services.inbox.enabled' => true,
        'services.inbox.credits_per_suggestion' => 300,
        'services.ai.key' => 'sk-ant-test',
        'services.ai.base_url' => 'https://ai.test/v1',
        'services.ai.model' => 'claude-opus-5-5',
    ]);
    Cache::flush();
    Workspace::forget();
});

/** @return array{0: User, 1: SocialConversation, 2: SocialComment} */
function sgWorld(bool $pro = true): array
{
    $user = User::factory()->create();
    if ($pro) {
        Subscription::factory()->for($user)->create();
    }
    Credits::grant($user, 1_000, 'Test');
    Credits::renewMonthly($user); // Pro's Monthly Credits in place before the tap
    $account = SocialAccount::factory()->for($user)->create([
        'platform' => 'instagram', 'provider' => 'outstand', 'username' => 'kopisenja',
        'provider_account_id' => 'acc_kopi', 'messages_scope_at' => now(), 'inbox_synced_at' => now(),
    ]);
    $thread = SocialConversation::query()->create([
        'user_id' => $user->id, 'social_account_id' => $account->id, 'provider' => 'outstand',
        'provider_conversation_id' => 'conv_1', 'network' => 'instagram', 'contact_id' => 'ig_1',
        'contact_username' => 'fan', 'last_message_at' => now(), 'last_inbound_at' => now(),
        'unread_count' => 1, 'status' => 'open', 'messages_synced_at' => now(),
    ]);
    SocialMessage::query()->create([
        'social_conversation_id' => $thread->id, 'provider_message_id' => 'm1',
        'direction' => 'in', 'content' => 'Buka jam berapa besok?', 'status' => 'received',
    ]);
    $comment = SocialComment::query()->create([
        'user_id' => $user->id, 'social_account_id' => $account->id, 'provider' => 'outstand',
        'provider_post_id' => 'p1', 'platform_post_id' => '1789', 'comment_id' => 'c1',
        'author_id' => 'ig_fan', 'author_username' => 'fan', 'text' => 'Love this!',
        'network' => 'instagram', 'commented_at' => now(),
    ]);

    return [$user, $thread, $comment];
}

it('gives three suggestions for a DM and takes 300 Credits', function () {
    Http::fake(['ai.test/*' => Http::response(['content' => [['type' => 'text', 'text' => "1. Kami buka jam 7 pagi!\n2. Jam 7, sampai ketemu!\n3. \"Besok buka jam 7 ya.\""]]])]);
    [$user, $thread] = sgWorld();
    $before = Credits::total($user);

    $this->actingAs($user)->postJson("/api/v1/inbox/feed/dm_{$thread->ulid}/suggestions")
        ->assertOk()
        ->assertJsonPath('data.suggestions', ['Kami buka jam 7 pagi!', 'Jam 7, sampai ketemu!', 'Besok buka jam 7 ya.'])
        ->assertJsonPath('data.credits', 300);

    expect(Credits::total($user))->toBe($before - 300);
    Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'], 'Them: Buka jam berapa besok?'));
});

it('works for a comment too', function () {
    Http::fake(['ai.test/*' => Http::response(['content' => [['type' => 'text', 'text' => "Thank you!\nSo glad you like it\nThanks for the love"]]])]);
    [$user, , $comment] = sgWorld();

    $this->actingAs($user)->postJson("/api/v1/inbox/feed/comment_{$comment->ulid}/suggestions")
        ->assertOk()->assertJsonCount(3, 'data.suggestions');
});

it('gives the Credits back when the AI does not answer', function () {
    Http::fake(['ai.test/*' => Http::response(['error' => 'overloaded'], 529)]);
    [$user, $thread] = sgWorld();
    $before = Credits::total($user);

    $this->actingAs($user)->postJson("/api/v1/inbox/feed/dm_{$thread->ulid}/suggestions")->assertStatus(502);

    expect(Credits::total($user))->toBe($before);
});

it('is on Pro and Max only', function () {
    Http::fake();
    [$user, $thread] = sgWorld(pro: false);

    $this->actingAs($user)->postJson("/api/v1/inbox/feed/dm_{$thread->ulid}/suggestions")
        ->assertStatus(402)->assertJsonPath('feature', 'ai_reply_suggestions');
    Http::assertNothingSent();
});

it('refuses when Credits are short, without calling the AI', function () {
    Http::fake();
    [$user, $thread] = sgWorld();
    $before = Credits::total($user);
    config(['services.inbox.credits_per_suggestion' => $before + 1]);

    $this->actingAs($user)->postJson("/api/v1/inbox/feed/dm_{$thread->ulid}/suggestions")->assertStatus(422);
    Http::assertNothingSent();
    expect(Credits::total($user))->toBe($before);
});

it('answers 404 for another workspace', function () {
    [, $thread] = sgWorld();
    $other = User::factory()->create();
    Subscription::factory()->for($other)->create();

    $this->actingAs($other)->postJson("/api/v1/inbox/feed/dm_{$thread->ulid}/suggestions")->assertNotFound();
});
