<?php

use App\Contracts\SocialProvider;
use App\Jobs\SendAutoDm;
use App\Models\SocialAccount;
use App\Models\SocialAutoDm;
use App\Models\SocialComment;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\AutoDm;
use App\Support\Credits;
use App\Support\InboxStore;
use App\Support\SocialProviders;
use Illuminate\Support\Facades\Queue;

/* v10.5 Auto DM on comment (Rizal 21 Nov): per post, keyword, Fixed text
   100 / Written by AI 300 Credits a DM, IG + FB, one DM per person per
   post, Credits back on fail, pauses + bell when Credits run out. */

function adSetup(array $rule = ['keyword' => 'INFO', 'mode' => 'fixed', 'message' => 'Here is the menu: x.y/menu'], int $credits = 1_000): array
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    if ($credits > 0) {
        Credits::grant($user, $credits, 'Test');
    }
    $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram', 'provider' => 'outstand', 'username' => 'kopisenja']);
    $post = SocialPost::factory()->for($user)->create(['provider' => 'outstand', 'provider_post_id' => 'op_1', 'status' => 'published', 'auto_dm' => $rule]);

    return [$user, $ig, $post];
}

function adComment(SocialAccount $a, string $id, string $text, string $author = 'u1'): SocialComment
{
    Queue::fake();

    return InboxStore::upsertComment($a, ['id' => $id, 'post_id' => 'op_1', 'author_id' => $author, 'author_username' => 'sari', 'text' => $text], 'instagram');
}

/** A provider that records private replies (or throws). */
function adProviders(?Throwable $fail = null): array
{
    $sent = new ArrayObject;
    $provider = Mockery::mock(SocialProvider::class);
    $provider->shouldReceive('privateReply')->andReturnUsing(function ($acc, $cid, $aid, $text) use ($sent, $fail) {
        if ($fail) {
            throw $fail;
        }
        $sent[] = $text;

        return ['conversation_id' => null];
    });
    $providers = Mockery::mock(SocialProviders::class);
    $providers->shouldReceive('for')->andReturn($provider);

    return [$providers, $sent];
}

it('matches the keyword as a word, any case', function () {
    expect(AutoDm::matches('INFO', 'info please'))->toBeTrue()
        ->and(AutoDm::matches('info', 'Mau #INFO dong'))->toBeTrue()
        ->and(AutoDm::matches('info', 'information'))->toBeFalse()
        ->and(AutoDm::matches('', 'info'))->toBeFalse();
});

it('a new comment on a post with a rule queues one job', function () {
    [, $ig] = adSetup();
    adComment($ig, 'c1', 'INFO');
    Queue::assertPushed(SendAutoDm::class, 1);
    InboxStore::upsertComment($ig, ['id' => 'c1', 'post_id' => 'op_1', 'author_id' => 'u1', 'text' => 'INFO'], 'instagram');
    Queue::assertPushed(SendAutoDm::class, 1); // seen again: not new
});

it('sends the fixed DM once per person, 100 Credits', function () {
    [$user, $ig, $post] = adSetup();
    [$providers, $sent] = adProviders();
    $before = Credits::total($user);

    AutoDm::handle(adComment($ig, 'c1', 'info pls'), $providers);
    AutoDm::handle(adComment($ig, 'c2', 'INFO again'), $providers); // same person
    AutoDm::handle(adComment($ig, 'c3', 'nice photo', 'u2'), $providers); // no keyword

    expect($sent->getArrayCopy())->toBe(['Here is the menu: x.y/menu'])
        ->and(Credits::total($user))->toBe($before - 100)
        ->and(SocialAutoDm::query()->where('status', 'sent')->count())->toBe(1);
    $this->actingAs($user)->getJson("/api/v1/social-posts/{$post->ulid}/auto-dm")
        ->assertOk()->assertJsonPath('data.sent', 1)->assertJsonPath('data.credits_per_dm', 100);
});

it('gives the Credits back when the DM fails', function () {
    [$user, $ig] = adSetup();
    [$providers] = adProviders(new RuntimeException('window closed'));
    $before = Credits::total($user);

    $row = AutoDm::handle(adComment($ig, 'c1', 'INFO'), $providers);

    expect($row->status)->toBe('failed')->and(Credits::total($user))->toBe($before);
});

it('pauses and rings the bell when Credits run out; saving resumes', function () {
    [$user, $ig, $post] = adSetup(credits: 0);
    Credits::grant($user, 50, 'Test');
    [$providers, $sent] = adProviders();

    $row = AutoDm::handle(adComment($ig, 'c1', 'INFO'), $providers);

    expect($row->status)->toBe('no_credits')->and($sent->count())->toBe(0)
        ->and($post->fresh()->auto_dm['paused_at'])->not->toBeNull()
        ->and($user->notifications()->where('data->type', 'auto_dm.paused')->count())->toBe(1);
    expect(AutoDm::handle(adComment($ig, 'c2', 'INFO', 'u2'), $providers))->toBeNull(); // paused

    $this->actingAs($user)->putJson("/api/v1/social-posts/{$post->ulid}/auto-dm", ['keyword' => 'INFO', 'mode' => 'ai', 'message' => 'Share the menu link'])
        ->assertOk()->assertJsonPath('data.paused', false)->assertJsonPath('data.credits_per_dm', 300);
});

it('PUT / DELETE the rule; Free is refused (402)', function () {
    [$user, , $post] = adSetup();
    $this->actingAs($user)->deleteJson("/api/v1/social-posts/{$post->ulid}/auto-dm")->assertOk()->assertJsonPath('data.on', false);
    $this->actingAs($user)->putJson("/api/v1/social-posts/{$post->ulid}/auto-dm", ['keyword' => ' ', 'mode' => 'fixed', 'message' => 'x'])->assertStatus(422);

    $free = User::factory()->create();
    $fp = SocialPost::factory()->for($free)->create();
    $this->actingAs($free)->putJson("/api/v1/social-posts/{$fp->ulid}/auto-dm", ['keyword' => 'INFO', 'mode' => 'fixed', 'message' => 'Hi'])->assertStatus(402);
});
