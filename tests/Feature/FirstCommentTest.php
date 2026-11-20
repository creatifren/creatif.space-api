<?php

use App\Jobs\PostFirstComment;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/* v10.2 First comment (Rizal option A): kept on the post, posted once per
   Instagram / Facebook / LinkedIn account after the post is live, never
   on other networks. */

beforeEach(function () {
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
    ]);
    Cache::flush();
});

function fcPost(): array
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram', 'provider' => 'outstand', 'username' => 'kopisenja']);
    $x = SocialAccount::factory()->for($user)->create(['platform' => 'x', 'provider' => 'outstand', 'username' => 'kopi_x']);
    $post = SocialPost::factory()->for($user)->create(['provider' => 'outstand', 'provider_post_id' => 'op_1', 'status' => 'scheduled', 'first_comment' => '#kopisenja #kopilokal']);
    $post->targets()->create(['social_account_id' => $ig->id, 'status' => 'published']);
    $post->targets()->create(['social_account_id' => $x->id, 'status' => 'published']);

    return [$user, $post];
}

it('queues the first comment when the post goes live', function () {
    Queue::fake();
    [, $post] = fcPost();
    $post->update(['status' => 'published']);
    Queue::assertPushed(PostFirstComment::class, fn ($job) => $job->postId === $post->id);
});

it('posts it once on Instagram and skips X', function () {
    Http::fake(['api.outstand.test/v1/posts/op_1/replies' => Http::response(['data' => ['id' => 'c1']])]);
    [$user, $post] = fcPost();

    (new PostFirstComment($post->id))->handle(app(\App\Services\OutstandService::class));
    (new PostFirstComment($post->id))->handle(app(\App\Services\OutstandService::class));

    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r['content'] === '#kopisenja #kopilokal' && $r['account_username'] === 'kopisenja' && ! isset($r['parent_comment_id']));
    $res = $this->actingAs($user)->getJson("/api/v1/social-posts/{$post->ulid}")->assertOk();
    $res->assertJsonPath('data.first_comment', '#kopisenja #kopilokal');
    expect(collect($res->json('data.targets'))->pluck('first_comment')->all())->toBe(['posted', null]);
});

it('keeps the first comment from Compose and lets it change before it goes out', function () {
    [$user, $post] = fcPost();
    $post->update(['status' => 'draft']);
    $this->actingAs($user)->patchJson("/api/v1/social-posts/{$post->ulid}", ['first_comment' => '  #new  '])
        ->assertOk()->assertJsonPath('data.first_comment', '#new');
    $this->actingAs($user)->patchJson("/api/v1/social-posts/{$post->ulid}", ['first_comment' => null])
        ->assertOk()->assertJsonPath('data.first_comment', null);
});
