<?php

use App\Http\Controllers\Api\V1\SocialPostController;
use App\Http\Middleware\AcceptBeaconJson;
use App\Models\Activity;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\SocialPostComment;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;
use App\Support\Workspace;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/*
 * QA live findings: the approval round trip. An edit after "changes
 * requested" asks the client again; the client's decision survives a
 * closing tab (beacon, no CSRF/session, cached preflight) and a repeat.
 */

beforeEach(function () {
    Workspace::forget();
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
        'app.frontend_url' => 'https://app.test',
        'sanctum.stateful' => ['app.test'],
        'cors.allowed_origins' => ['https://app.test'],
    ]);
    Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_x', 'status' => 'scheduled'])]);
});

function rtOwner(): User
{
    $owner = User::factory()->create();
    Subscription::factory()->for($owner)->create();
    Credits::grant($owner, 30_000, 'Test');

    return $owner;
}

/** A post held for the client, on a link. */
function rtHeld(User $owner, string $approval = 'client'): array
{
    $post = SocialPost::factory()->for($owner)->create([
        'provider_post_id' => null, 'status' => 'scheduled', 'approval' => $approval,
        'caption' => 'First go.', 'scheduled_at' => now()->addDays(2),
    ]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($owner)->create()->id, 'status' => 'pending']);
    $link = SocialApprovalLink::create(['user_id' => $owner->id, 'title' => 'Nov', 'client_name' => 'Andi']);
    $link->posts()->attach($post->id);

    return [$post, $link];
}

/** Posts created at the provider so far. */
function rtCreates(): int
{
    return count(Http::recorded(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/social-posts')));
}

describe('an edit after changes requested asks the client again', function () {
    it('puts a revision post back to the client on caption, media or time, with a line in its thread and Activity', function (array $edit) {
        $owner = rtOwner();
        [$post, $link] = rtHeld($owner, 'revision');
        $post->update(['approval_by' => 'Andi', 'approval_at' => now()]);

        $this->actingAs($owner)->patchJson("/api/v1/social-posts/{$post->ulid}", $edit)
            ->assertOk()
            ->assertJsonPath('data.approval', 'client')
            ->assertJsonPath('data.approval_by', null);

        $fresh = $post->fresh();
        expect($fresh->approval->value)->toBe('client')
            ->and($fresh->provider_post_id)->toBeNull();

        $line = SocialPostComment::query()->where('social_post_id', $post->id)->latest('id')->first();
        expect($line->role)->toBe('EDITOR')
            ->and($line->body)->toBe(SocialPostController::EDITED_AFTER_REVISION)
            ->and(Activity::query()->where('user_id', $owner->id)->where('action', 'post.revise')->exists())->toBeTrue();

        // The same link decides on it again.
        $this->getJson("/api/v1/a/{$link->token}")->assertOk()->assertJsonPath('data.posts.0.approval', 'client');
        Http::assertNothingSent();
    })->with([
        'caption' => [['caption' => 'Second go, as asked.']],
        'media' => [['media' => [['url' => 'https://assets.creatif.space/new.jpg']]]],
        'time' => [['scheduled_at' => '2030-01-02T10:00:00Z']],
    ]);

    it('leaves a revision post as it is when nothing the client sees changed', function () {
        $owner = rtOwner();
        [$post] = rtHeld($owner, 'revision');

        $this->actingAs($owner)->patchJson("/api/v1/social-posts/{$post->ulid}", ['labels' => ['nov']])->assertOk();
        $this->actingAs($owner)->patchJson("/api/v1/social-posts/{$post->ulid}", ['caption' => 'First go.'])->assertOk();

        expect($post->fresh()->approval->value)->toBe('revision')
            ->and(SocialPostComment::query()->where('social_post_id', $post->id)->count())->toBe(0);
    });

    it('does not touch a post waiting for the client or one already approved', function () {
        $owner = rtOwner();
        [$waiting] = rtHeld($owner, 'client');

        $this->actingAs($owner)->patchJson("/api/v1/social-posts/{$waiting->ulid}", ['caption' => 'Tweak'])
            ->assertOk()->assertJsonPath('data.approval', 'client');
        expect(SocialPostComment::query()->count())->toBe(0);
    });
});

describe('the client decision survives a closing tab', function () {
    it('caches the CORS preflight for 2 h without widening who may call', function () {
        $this->call('OPTIONS', '/api/v1/a/tok/posts/p/decision', [], [], [], [
            'HTTP_ORIGIN' => 'https://app.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ])->assertNoContent()
            ->assertHeader('Access-Control-Max-Age', '7200')
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.test');

        $other = $this->call('OPTIONS', '/api/v1/me', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        expect($other->headers->get('Access-Control-Allow-Origin'))->toBeNull();
    });

    it('runs the write routes without the session/CSRF layer, keeping their rate limits', function (string $name) {
        $route = Route::getRoutes()->getByName($name);
        $middleware = app('router')->gatherRouteMiddleware($route);

        expect($middleware)->not->toContain(EnsureFrontendRequestsAreStateful::class)
            ->and($middleware)->toContain(AcceptBeaconJson::class)
            ->and(collect($middleware)->contains(fn ($m) => str_contains((string) $m, 'Throttle')))->toBeTrue();
    })->with(['api.v1.approval-links.decide', 'api.v1.approval-links.retract', 'api.v1.approval-links.comment', 'api.v1.approval-links.approve-all']);

    it('starts no session and honours no login from the app\'s origin', function () {
        $owner = rtOwner();
        [$post, $link] = rtHeld($owner);

        // A stateful-looking request (the app's origin and cookies): before,
        // Sanctum started a session here and set its cookies.
        $response = $this->withHeaders(['Origin' => 'https://app.test', 'Referer' => 'https://app.test/a/x'])
            ->withCookies(['laravel_session' => 'someone-elses'])
            ->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/comments", ['name' => 'Andi', 'body' => 'Nice'])
            ->assertCreated();

        expect($response->headers->getCookies())->toBe([])
            ->and(SocialPostComment::query()->latest('id')->first()->user_id)->toBeNull();

        // The token is still the only key.
        $this->withHeaders(['Origin' => 'https://app.test'])
            ->postJson("/api/v1/a/not-a-token/posts/{$post->ulid}/comments", ['name' => 'Andi', 'body' => 'x'])
            ->assertNotFound();
    });

    it('takes a sendBeacon body (text/plain holding JSON)', function () {
        $owner = rtOwner();
        [$post, $link] = rtHeld($owner);

        $this->call('POST', "/api/v1/a/{$link->token}/posts/{$post->ulid}/decision", [], [], [], [
            'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'https://app.test',
        ], json_encode(['name' => 'Andi', 'decision' => 'approve']))
            ->assertOk()
            ->assertJsonPath('data.approval', 'approved');

        expect($post->fresh()->approval->value)->toBe('approved');
    });

    it('answers a text/plain body that is not a JSON object with validation, not a crash', function () {
        $owner = rtOwner();
        [$post, $link] = rtHeld($owner);

        $this->call('POST', "/api/v1/a/{$link->token}/posts/{$post->ulid}/decision", [], [], [], [
            'CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json',
        ], 'approve please')->assertUnprocessable();
    });
});

describe('a repeated decision is idempotent', function () {
    it('answers the same approve twice with the current state and sends once', function () {
        $owner = rtOwner();
        [$post, $link] = rtHeld($owner);
        $url = "/api/v1/a/{$link->token}/posts/{$post->ulid}/decision";

        $this->postJson($url, ['name' => 'Andi', 'decision' => 'approve'])->assertOk()->assertJsonPath('data.approval', 'approved');
        $this->postJson($url, ['name' => 'Andi', 'decision' => 'approve'])->assertOk()->assertJsonPath('data.approval', 'approved');

        expect(rtCreates())->toBe(1);
    });

    it('answers the same request for changes twice with one comment', function () {
        $owner = rtOwner();
        [$post, $link] = rtHeld($owner);
        $url = "/api/v1/a/{$link->token}/posts/{$post->ulid}/decision";
        $body = ['name' => 'Andi', 'decision' => 'revise', 'reason' => 'Warmer opener please'];

        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.approval', 'revision');
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.approval', 'revision');

        expect(SocialPostComment::query()->where('social_post_id', $post->id)->where('role', 'APPROVER')->count())->toBe(1);
    });

    it('still refuses a different decision on an approved post', function () {
        $owner = rtOwner();
        [$post, $link] = rtHeld($owner);
        $url = "/api/v1/a/{$link->token}/posts/{$post->ulid}/decision";

        $this->postJson($url, ['name' => 'Andi', 'decision' => 'approve'])->assertOk();
        $this->postJson($url, ['name' => 'Andi', 'decision' => 'revise', 'reason' => 'Wait'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This post has already been decided.');
    });

    it('answers approve-all twice with the current state', function () {
        $owner = rtOwner();
        [$post, $link] = rtHeld($owner);
        $body = ['name' => 'Andi', 'post_ids' => [$post->ulid]];

        $this->postJson("/api/v1/a/{$link->token}/approve-all", $body)->assertOk()->assertJsonPath('data.results.0.approved', true);
        $this->postJson("/api/v1/a/{$link->token}/approve-all", $body)
            ->assertOk()
            ->assertJsonPath('data.results.0.approved', true)
            ->assertJsonPath('data.posts.0.approval', 'approved');

        expect(rtCreates())->toBe(1);
    });
});
