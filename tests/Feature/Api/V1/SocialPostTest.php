<?php

use App\Models\Activity;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
});

/** A Premium creator with Credits to spend — sending costs Credits::PER_SEND a destination. */
function premiumUser(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, 30_000, 'Test');

    return $user;
}

describe('creating posts', function () {
    it('creates a scheduled post with pending targets and per-account captions', function () {
        Http::fake([
            'api.postforme.test/*' => Http::response(['id' => 'post_abc', 'status' => 'scheduled']),
        ]);
        $user = premiumUser();
        $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
        $x = SocialAccount::factory()->for($user)->create(['platform' => 'x']);

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', [
                'caption' => 'Winter Noel — the full set.',
                'account_ids' => [$ig->ulid, $x->ulid],
                'captions' => [$x->ulid => 'Short one for X.'],
                'media' => [['url' => 'https://assets.creatif.space/winter-noel-03.jpg']],
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonCount(2, 'data.targets');

        $post = SocialPost::query()->first();
        expect($post->provider_post_id)->toBe('post_abc')
            ->and($post->targets()->count())->toBe(2)
            ->and($post->targets()->where('social_account_id', $x->id)->first()->configuration)
            ->toBe(['caption' => 'Short one for X.']);

        Http::assertSent(function ($request) use ($x) {
            $body = $request->data();

            return str_ends_with($request->url(), '/social-posts')
                && count($body['social_accounts']) === 2
                && $body['account_configurations'][0]['social_account_id'] === $x->provider_account_id;
        });
    });

    it('sends a video cover as the media thumbnail', function () {
        Http::fake([
            'api.postforme.test/*' => Http::response(['id' => 'post_cov', 'status' => 'scheduled']),
        ]);
        $user = premiumUser();
        $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
        $media = [[
            'url' => 'https://assets.creatif.space/reel.mp4',
            'thumbnail_url' => 'https://assets.creatif.space/cover.jpg',
        ]];

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', [
                'caption' => 'Reel with a cover.',
                'account_ids' => [$ig->ulid],
                'media' => $media,
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertCreated();

        Http::assertSent(fn ($request) => ($request->data()['media'] ?? null) === $media);
    });

    it('marks an immediate post published when the provider says processed', function () {
        Http::fake([
            'api.postforme.test/*' => Http::response(['id' => 'post_now', 'status' => 'processed']),
        ]);
        $user = premiumUser();
        $account = SocialAccount::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', [
                'caption' => 'Now.',
                'account_ids' => [$account->ulid],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'published');
    });

    it("refuses another user's account and sends nothing", function () {
        Http::fake();
        $foreign = SocialAccount::factory()->create();

        $this->actingAs(premiumUser())
            ->postJson('/api/v1/social-posts', [
                'caption' => 'Sneaky.',
                'account_ids' => [$foreign->ulid],
            ])
            ->assertUnprocessable();

        Http::assertNothingSent();
    });

    it('refuses a disconnected account of your own', function () {
        Http::fake();
        $user = premiumUser();
        $account = SocialAccount::factory()->for($user)->disconnected()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', [
                'caption' => 'Nope.',
                'account_ids' => [$account->ulid],
            ])
            ->assertUnprocessable();

        Http::assertNothingSent();
    });

    it('validates caption length, media scheme, and past schedule', function () {
        $user = premiumUser();
        $account = SocialAccount::factory()->for($user)->create();

        $base = ['account_ids' => [$account->ulid]];

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', $base + ['caption' => str_repeat('a', 63207)])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', $base + [
                'caption' => 'ok',
                'media' => [['url' => 'http://insecure.example/x.jpg']],
            ])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', $base + [
                'caption' => 'ok',
                'scheduled_at' => now()->subHour()->toIso8601String(),
            ])
            ->assertUnprocessable();
    });
});

describe('credits', function () {
    it('refuses a send the balance cannot pay for, and sends nothing', function () {
        Http::fake();
        $user = User::factory()->create(); // no Credits yet
        $account = SocialAccount::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', ['caption' => 'Broke.', 'account_ids' => [$account->ulid]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('caption');

        Http::assertNothingSent();
    });

    it('takes PER_SEND a destination when sent, and gives a failed one back', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_c', 'status' => 'scheduled'])]);
        config(['services.postforme.webhook_secret' => 's']);
        $user = User::factory()->create();
        Credits::grant($user, 3000, 'Welcome');
        $a = SocialAccount::factory()->for($user)->create();
        $b = SocialAccount::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/v1/social-posts', ['caption' => 'Two.', 'account_ids' => [$a->ulid, $b->ulid]])
            ->assertCreated();
        expect(Credits::balance($user))->toBe(2400);

        $failure = [
            'event_type' => 'social.post.result.created',
            'data' => ['post_id' => 'post_c', 'social_account_id' => $b->provider_account_id, 'success' => false, 'error' => 'Video too long'],
        ];
        $this->postJson('/api/v1/webhooks/postforme', $failure, ['Post-For-Me-Webhook-Secret' => 's'])->assertOk();
        // A redelivery gives nothing back twice.
        $this->postJson('/api/v1/webhooks/postforme', $failure, ['Post-For-Me-Webhook-Secret' => 's'])->assertOk();

        expect(Credits::balance($user))->toBe(2700);
    });

    it('returns the price of every destination still pending on cancel', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_x', 'status' => 'scheduled'])]);
        $user = premiumUser(); // 30000
        $account = SocialAccount::factory()->for($user)->create();

        $id = $this->actingAs($user)
            ->postJson('/api/v1/social-posts', [
                'caption' => 'Later.', 'account_ids' => [$account->ulid],
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])->json('data.id');
        expect(Credits::balance($user))->toBe(29_700);

        $this->actingAs($user)->deleteJson("/api/v1/social-posts/{$id}")->assertNoContent();
        expect(Credits::balance($user))->toBe(30_000);
    });

    it('charges nothing to hold a post for approval or save a draft', function () {
        Http::fake();
        $user = User::factory()->create();
        $account = SocialAccount::factory()->for($user)->create();

        foreach ([['approval' => 'awaiting'], ['draft' => true]] as $extra) {
            $this->actingAs($user)
                ->postJson('/api/v1/social-posts', ['caption' => 'Free to hold.', 'account_ids' => [$account->ulid]] + $extra)
                ->assertCreated();
        }

        expect(Credits::balance($user))->toBe(0);
        Http::assertNothingSent();
    });
});

describe('listing and cancelling', function () {
    it('filters by status and hides other users', function () {
        $user = User::factory()->create();
        SocialPost::factory()->for($user)->create();
        SocialPost::factory()->for($user)->published()->create();
        SocialPost::factory()->create(); // someone else's

        $this->actingAs($user)
            ->getJson('/api/v1/social-posts?status=scheduled')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'scheduled');

        $this->actingAs($user)
            ->getJson('/api/v1/social-posts')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('settles an overdue Scheduled post from the provider results when the webhook never came', function () {
        $user = User::factory()->create();
        $account = SocialAccount::factory()->for($user)->create();
        $post = SocialPost::factory()->for($user)->create([
            'provider_post_id' => 'post_late',
            'scheduled_at' => now()->subHour(),
        ]);
        $post->targets()->create(['social_account_id' => $account->id]);

        Http::fake(['api.postforme.test/*' => Http::response(['data' => [[
            'id' => 'res_9',
            'post_id' => 'post_late',
            'social_account_id' => $account->provider_account_id,
            'success' => true,
            'platform_data' => ['url' => 'https://instagram.com/p/9'],
        ]]])]);

        $this->actingAs($user)
            ->getJson('/api/v1/social-posts')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'published')
            ->assertJsonPath('data.0.targets.0.platform_url', 'https://instagram.com/p/9');
    });

    it('cancels a scheduled post via the provider', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['message' => 'ok'])]);
        $user = User::factory()->create();
        $post = SocialPost::factory()->for($user)->create();

        $this->actingAs($user)
            ->deleteJson("/api/v1/social-posts/{$post->ulid}")
            ->assertNoContent();

        expect($post->fresh()->status->value)->toBe('cancelled');
        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    });

    it('refuses to cancel a published post', function () {
        Http::fake();
        $user = User::factory()->create();
        $post = SocialPost::factory()->for($user)->published()->create();

        $this->actingAs($user)
            ->deleteJson("/api/v1/social-posts/{$post->ulid}")
            ->assertUnprocessable();

        Http::assertNothingSent();
    });

    it("forbids cancelling another user's post", function () {
        $post = SocialPost::factory()->create();

        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/v1/social-posts/{$post->ulid}")
            ->assertForbidden();
    });

    it('reschedules via a full PUT that keeps destinations and overrides', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_x'])]);
        $user = User::factory()->create();
        $post = SocialPost::factory()->for($user)->create();
        $account = SocialAccount::factory()->for($user)->create();
        $post->targets()->create([
            'social_account_id' => $account->id,
            'configuration' => ['caption' => 'Own line.'],
            'status' => 'pending',
        ]);
        $when = now()->addDays(3)->startOfMinute();

        $this->actingAs($user)
            ->patchJson("/api/v1/social-posts/{$post->ulid}", ['scheduled_at' => $when->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('data.caption', $post->caption);

        expect($post->fresh()->scheduled_at->equalTo($when))->toBeTrue()
            ->and(Activity::query()->where('action', 'post.reschedule')->where('subject_id', $post->ulid)->first()?->meta['to'])
            ->toBe($when->toIso8601String());
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), "/social-posts/{$post->provider_post_id}")
            && $request['social_accounts'] === [$account->provider_account_id]
            && $request['caption'] === $post->caption
            && $request['account_configurations'][0]['configuration'] === ['caption' => 'Own line.']);
    });

    it('refuses to change a post that already went or is going out', function () {
        Http::fake();
        $user = User::factory()->create();
        $published = SocialPost::factory()->for($user)->published()->create();
        $immediate = SocialPost::factory()->for($user)->create(['scheduled_at' => null]);

        foreach ([$published, $immediate] as $post) {
            $this->actingAs($user)
                ->patchJson("/api/v1/social-posts/{$post->ulid}", ['caption' => 'Late edit.'])
                ->assertUnprocessable();
        }

        Http::assertNothingSent();
    });

    it("forbids changing another user's post", function () {
        $post = SocialPost::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patchJson("/api/v1/social-posts/{$post->ulid}", ['caption' => 'Mine now.'])
            ->assertForbidden();
    });

    it('rejects guests', function () {
        $this->getJson('/api/v1/social-posts')->assertUnauthorized();
    });
});

describe('drafts', function () {
    it('saves a draft without the provider or Credits, then sends it', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_d', 'status' => 'scheduled'])]);
        $user = User::factory()->create(); // no Credits
        $account = SocialAccount::factory()->for($user)->create();

        $id = $this->actingAs($user)
            ->postJson('/api/v1/social-posts', ['caption' => 'Later.', 'account_ids' => [$account->ulid], 'draft' => true])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');
        Http::assertNothingSent();

        // Sending runs the gate the draft skipped.
        $this->actingAs($user)->postJson("/api/v1/social-posts/{$id}/send")->assertUnprocessable();
        Http::assertNothingSent();

        Credits::grant($user, Credits::PER_SEND, 'Test');

        $this->actingAs($user)
            ->postJson("/api/v1/social-posts/{$id}/send", ['scheduled_at' => now()->addDay()->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');
        Http::assertSentCount(1);
    });

    it('edits and discards a draft, and only a draft', function () {
        $user = User::factory()->create();
        $draft = SocialPost::factory()->for($user)->create(['status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => null]);
        $sent = SocialPost::factory()->for($user)->create();

        $this->actingAs($user)
            ->patchJson("/api/v1/social-posts/{$draft->ulid}", ['caption' => 'Better.'])
            ->assertOk()
            ->assertJsonPath('data.caption', 'Better.');

        $this->actingAs($user)->deleteJson("/api/v1/social-posts/{$sent->ulid}/draft")->assertUnprocessable();
        $this->actingAs($user)->deleteJson("/api/v1/social-posts/{$draft->ulid}/draft")->assertNoContent();
        expect(SocialPost::query()->find($draft->id))->toBeNull();
    });
});

it('sends Reels as a placement where the platform has one, and plainly elsewhere', function () {
    Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_r', 'status' => 'scheduled'])]);
    $user = premiumUser();
    $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
    $tt = SocialAccount::factory()->for($user)->create(['platform' => 'tiktok']);

    $this->actingAs($user)
        ->postJson('/api/v1/social-posts', [
            'caption' => 'Reel.',
            'account_ids' => [$ig->ulid, $tt->ulid],
            'type' => 'reels',
            'media' => [['url' => 'https://assets.creatif.space/r.mp4']],
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'reels');

    Http::assertSent(fn ($request) => count($request['account_configurations']) === 1
        && $request['account_configurations'][0]['social_account_id'] === $ig->provider_account_id
        && $request['account_configurations'][0]['configuration'] === ['placement' => 'reels']);
});

describe('swap', function () {
    it('trades two slots in one act', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['id' => 'x'])]);
        $user = User::factory()->create();
        $a = SocialPost::factory()->for($user)->create(['scheduled_at' => now()->addDay()->startOfMinute()]);
        $b = SocialPost::factory()->for($user)->create(['scheduled_at' => now()->addDays(2)->startOfMinute()]);
        [$atA, $atB] = [$a->scheduled_at, $b->scheduled_at];

        $this->actingAs($user)->postJson('/api/v1/social-posts/swap', ['a' => $a->ulid, 'b' => $b->ulid])->assertNoContent();

        expect($a->fresh()->scheduled_at->equalTo($atB))->toBeTrue()
            ->and($b->fresh()->scheduled_at->equalTo($atA))->toBeTrue();
        Http::assertSentCount(2);
    });

    it('puts the first back when the second replace fails, and changes no row', function () {
        Http::fakeSequence('api.postforme.test/*')
            ->push(['id' => 'x'])          // a → b's slot
            ->push(['message' => 'no'], 500) // b fails
            ->push(['id' => 'x']);         // a put back
        $user = User::factory()->create();
        $a = SocialPost::factory()->for($user)->create(['scheduled_at' => now()->addDay()->startOfMinute()]);
        $b = SocialPost::factory()->for($user)->create(['scheduled_at' => now()->addDays(2)->startOfMinute()]);
        $atA = $a->scheduled_at;

        $this->actingAs($user)->postJson('/api/v1/social-posts/swap', ['a' => $a->ulid, 'b' => $b->ulid])->assertServerError();

        expect($a->fresh()->scheduled_at->equalTo($atA))->toBeTrue();
        Http::assertSentCount(3);
    });

    it("refuses someone else's post", function () {
        $user = User::factory()->create();
        $a = SocialPost::factory()->for($user)->create();
        $b = SocialPost::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/social-posts/swap', ['a' => $a->ulid, 'b' => $b->ulid])->assertUnprocessable();
    });
});
