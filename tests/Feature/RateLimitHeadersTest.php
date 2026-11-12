<?php

use App\Http\Middleware\CapTokenSends;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\OpenApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
 * Every 429 from the v1 API carries X-RateLimit-Limit, X-RateLimit-Remaining
 * and Retry-After — the route throttle, the token limiter, the daily send
 * cap, the upload caps, reminders, and any other hand-made 429 (the
 * EnsureRateLimitHeaders safety net). Documented in openapi.json.
 */

beforeEach(fn () => Notification::fake());

function rlPresign($test, User $user, string $name)
{
    return $test->actingAs($user)->postJson('/api/v1/files/presign', ['name' => $name, 'mime_type' => 'image/jpeg', 'size_bytes' => 10]);
}

function rlAssertHeaders(TestResponse $response, ?string $limit, ?int $retryAfter = null): void
{
    $response->assertStatus(429)
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertHeader('Retry-After');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
    if ($limit !== null) {
        $response->assertHeader('X-RateLimit-Limit', $limit);
    }
    if ($retryAfter !== null) {
        expect((int) $response->headers->get('Retry-After'))->toBe($retryAfter);
    }
}

it('sends them on a route throttle (feedback, 10 an hour)', function () {
    $user = User::factory()->create();
    foreach (range(1, 10) as $i) {
        $this->actingAs($user)->postJson('/api/v1/feedback', ['kind' => 'idea', 'message' => "Idea {$i}"])->assertSuccessful();
    }

    rlAssertHeaders($this->actingAs($user)->postJson('/api/v1/feedback', ['kind' => 'idea', 'message' => 'One more']), '10');
});

it('sends them on the per-token limit', function () {
    config(['mcp.rate_limit_per_minute' => 2]);
    $user = User::factory()->create();
    $key = $this->actingAs($user, 'web')->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'scopes' => ['read']])->json('data.key');
    app('auth')->forgetGuards();

    bearer($this, $key)->getJson('/api/v1/me')->assertOk();
    bearer($this, $key)->getJson('/api/v1/me')->assertOk();

    rlAssertHeaders(bearer($this, $key)->getJson('/api/v1/me')->assertJsonPath('error', 'rate_limited'), '2');
});

it('sends them on the connected-app daily send cap', function () {
    $this->travelTo(now('UTC')->startOfDay()->addHours(23));
    $user = User::factory()->create();
    Cache::put('token-sends:'.$user->id.':'.now('UTC')->toDateString(), CapTokenSends::DAILY_CAP, 3600);
    $post = SocialPost::factory()->for($user)->create(['status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => null]);

    $response = $this->actingAs($user)->withToken('cs_live_test')
        ->postJson("/api/v1/social-posts/{$post->ulid}/send", ['confirm_token' => 'x'])
        ->assertJsonPath('code', 'daily_send_cap');

    rlAssertHeaders($response, (string) CapTokenSends::DAILY_CAP, 3600);
});

it('sends them on the upload caps without revealing the internal number', function () {
    config(['media.abuse.daily_count' => 2]);
    $user = mediaUser('pro');
    rlPresign($this, $user, 'a.jpg')->assertCreated();
    rlPresign($this, $user, 'b.jpg')->assertCreated();

    $response = rlPresign($this, $user, 'c.jpg')->assertJsonPath('reason', 'upload_limit');

    // The route's public throttle, not the internal cap of 2.
    rlAssertHeaders($response, '30');
});

it('sends them on reminder_too_soon (one reminder per 12 hours)', function () {
    $this->travelTo(now()->startOfMinute());
    $owner = User::factory()->create();
    Subscription::factory()->for($owner)->create();
    $post = SocialPost::factory()->for($owner)->create([
        'provider_post_id' => null, 'status' => 'scheduled', 'approval' => 'client', 'scheduled_at' => now()->addDays(2),
    ]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($owner)->create()->id, 'status' => 'pending']);
    $link = SocialApprovalLink::create(['user_id' => $owner->id, 'title' => 'November', 'approver_email' => 'rani@client.test']);
    $link->posts()->attach($post->id);

    $this->actingAs($owner)->postJson("/api/v1/social-approval-links/{$link->ulid}/remind")->assertOk();
    $this->travel(2)->hours();

    rlAssertHeaders($this->actingAs($owner)->postJson("/api/v1/social-approval-links/{$link->ulid}/remind")
        ->assertJsonPath('code', 'reminder_too_soon'), '1', 10 * 3600);
});

it('fills in any 429 that left them out, from the route throttle and retry_after', function () {
    Route::middleware(['api', 'throttle:7,1'])->get('/api/v1/__test/bare-429', fn () => response()->json(['message' => 'Slow down', 'retry_after' => 42], 429));
    Route::middleware(['api'])->get('/api/v1/__test/bare-429-no-throttle', fn () => response()->json(['message' => 'Slow down'], 429));

    rlAssertHeaders($this->getJson('/api/v1/__test/bare-429'), '7', 42);
    rlAssertHeaders($this->getJson('/api/v1/__test/bare-429-no-throttle'), '0', 60);
});

it('leaves other statuses alone', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized()->assertHeaderMissing('Retry-After');
});

it('documents the three headers on every 429 in openapi.json', function () {
    $doc = OpenApi::document();

    expect($doc['components']['headers'])->toHaveKeys(['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After']);
    foreach ($doc['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            expect(array_keys($operation['responses'][429]['headers'] ?? []))
                ->toBe(['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After'], "{$method} {$path}");
        }
    }
});
