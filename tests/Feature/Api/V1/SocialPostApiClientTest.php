<?php

use App\Models\File;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * The small additions the MCP server needs: one post by id, list filters
 * (date window, channel, limit) and drafts that take library file ids.
 */

describe('GET /social-posts/{post}', function () {
    it('returns the owner\'s post with targets and comments', function () {
        $user = User::factory()->create();
        $account = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
        $post = SocialPost::factory()->for($user)->create(['caption' => 'Hello']);
        $post->targets()->create(['social_account_id' => $account->id, 'status' => 'pending']);

        $this->actingAs($user)
            ->getJson("/api/v1/social-posts/{$post->ulid}")
            ->assertOk()
            ->assertJsonPath('data.id', $post->ulid)
            ->assertJsonPath('data.caption', 'Hello')
            ->assertJsonPath('data.targets.0.account.id', $account->ulid)
            ->assertJsonPath('data.comments', []);
    });

    it('refuses someone else\'s post', function () {
        $post = SocialPost::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/social-posts/{$post->ulid}")
            ->assertNotFound();
    });

    it('answers 404 for an unknown id', function () {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/social-posts/01JUNKJUNKJUNKJUNKJUNKJUNK')
            ->assertNotFound();
    });

    it('needs a signed-in user', function () {
        $post = SocialPost::factory()->create();

        $this->getJson("/api/v1/social-posts/{$post->ulid}")->assertUnauthorized();
    });
});

describe('GET /social-posts filters', function () {
    it('narrows by date window, channel and limit', function () {
        Http::fake();
        $user = User::factory()->create();
        $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
        $x = SocialAccount::factory()->for($user)->create(['platform' => 'x']);

        $inWindowIg = SocialPost::factory()->for($user)->create(['scheduled_at' => now()->addDays(2)]);
        $inWindowIg->targets()->create(['social_account_id' => $ig->id, 'status' => 'pending']);
        $inWindowX = SocialPost::factory()->for($user)->create(['scheduled_at' => now()->addDays(3)]);
        $inWindowX->targets()->create(['social_account_id' => $x->id, 'status' => 'pending']);
        $late = SocialPost::factory()->for($user)->create(['scheduled_at' => now()->addDays(20)]);
        $late->targets()->create(['social_account_id' => $ig->id, 'status' => 'pending']);

        $from = now()->addDay()->toIso8601String();
        $to = now()->addDays(5)->toIso8601String();

        $ids = fn ($response) => collect($response->json('data'))->pluck('id')->sort()->values()->all();

        $window = $this->actingAs($user)->getJson('/api/v1/social-posts?'.http_build_query(['from' => $from, 'to' => $to]))->assertOk();
        expect($ids($window))->toBe(collect([$inWindowIg->ulid, $inWindowX->ulid])->sort()->values()->all());

        $channel = $this->actingAs($user)->getJson('/api/v1/social-posts?'.http_build_query(['from' => $from, 'to' => $to, 'account_id' => $ig->ulid]))->assertOk();
        expect($ids($channel))->toBe([$inWindowIg->ulid]);

        $this->actingAs($user)->getJson('/api/v1/social-posts?limit=1')->assertOk()->assertJsonCount(1, 'data');
    });

    it('keeps an unscheduled post in a window by when it was made', function () {
        Http::fake();
        $user = User::factory()->create();
        $draft = SocialPost::factory()->for($user)->create(['scheduled_at' => null, 'status' => 'draft']);

        $this->actingAs($user)
            ->getJson('/api/v1/social-posts?'.http_build_query(['from' => now()->subHour()->toIso8601String()]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $draft->ulid);

        $this->actingAs($user)
            ->getJson('/api/v1/social-posts?'.http_build_query(['to' => now()->subHour()->toIso8601String()]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('rejects a limit over 100', function () {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/social-posts?limit=500')
            ->assertUnprocessable();
    });
});

describe('POST /social-posts with file_ids', function () {
    it('saves a draft whose media are the library files', function () {
        Http::fake();
        Storage::fake('s3');
        $user = User::factory()->create();
        $account = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
        $file = File::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', [
                'caption' => 'From the library.',
                'account_ids' => [$account->ulid],
                'file_ids' => [$file->ulid],
                'media' => [['url' => 'https://assets.example.com/a.jpg']],
                'draft' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonCount(2, 'data.media')
            ->assertJsonPath('data.media.0.url', 'https://assets.example.com/a.jpg');

        Http::assertNothingSent();
        $media = SocialPost::query()->firstOrFail()->media;
        expect($media[1]['url'])->toContain($file->path);
    });

    it('refuses a file that is not the user\'s or not ready', function () {
        Http::fake();
        Storage::fake('s3');
        $user = User::factory()->create();
        $account = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);

        foreach ([File::factory()->create(), File::factory()->for($user)->pending()->create()] as $file) {
            $this->actingAs($user)
                ->postJson('/api/v1/social-posts', [
                    'caption' => 'Nope.',
                    'account_ids' => [$account->ulid],
                    'file_ids' => [$file->ulid],
                    'draft' => true,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('file_ids');
        }

        expect(SocialPost::query()->count())->toBe(0);
    });
});
