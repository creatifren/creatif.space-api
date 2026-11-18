<?php

use App\Exceptions\MediaRefused;
use App\Exceptions\NotEnoughCredits;
use App\Exceptions\PlanFeatureRequired;
use App\Exceptions\PublishPreflightFailed;
use App\Exceptions\ScheduleBeyondHorizon;
use App\Jobs\SendApprovedHeldPosts;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SocialPostDecided;
use App\Notifications\SocialPostFailed;
use App\Services\Analytics;
use App\Services\ErrorTracking;
use App\Support\Credits;
use App\Support\PlanQuota;
use App\Support\ScheduleHorizon;
use App\Support\SocialPostRelay;
use App\Support\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/*
 * Launch fixes from QA's live smoke (11 Nov 2026):
 * - P2 a client's yes on a post past the relay's scheduling horizon
 * - P3 refusals logged as ERROR
 * - P3 committed.rent in get_credits
 */

beforeEach(function () {
    Workspace::forget();
    Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'UTC'));
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
        // The relay under test refuses slots further than 60 days out.
        'services.postforme.max_schedule_days' => 60,
        'app.frontend_url' => 'https://app.test',
    ]);
    // What the relay answers; a test swaps it with lfRelay().
    $GLOBALS['lf_relay'] = fn () => Http::response(['id' => 'post_x', 'status' => 'scheduled']);
    Http::fake(['api.postforme.test/*' => fn () => ($GLOBALS['lf_relay'])()]);
});

function lfRelay(array $body, int $status): void
{
    $GLOBALS['lf_relay'] = fn () => Http::response($body, $status);
}

afterEach(fn () => Carbon::setTestNow());

function lfOwner(): User
{
    $owner = User::factory()->create();
    Subscription::factory()->for($owner)->create(); // Pro: no 7-day window
    Credits::grant($owner, 30_000, 'Test');

    return $owner;
}

function lfAccount(User $owner): SocialAccount
{
    return SocialAccount::factory()->for($owner)->create(['platform' => 'linkedin']);
}

/** A post held for the client, on a link, at $at. */
function lfHeld(User $owner, CarbonInterface $at): array
{
    $post = SocialPost::factory()->for($owner)->create([
        'provider_post_id' => null, 'status' => 'scheduled', 'approval' => 'client',
        'caption' => 'January launch', 'scheduled_at' => $at, 'media' => null,
    ]);
    $post->targets()->create(['social_account_id' => lfAccount($owner)->id, 'status' => 'pending']);
    $link = SocialApprovalLink::create(['user_id' => $owner->id, 'title' => 'Q1', 'client_name' => 'Andi']);
    $link->posts()->attach($post->id);

    return [$post, $link];
}

function lfCreates(): int
{
    return count(Http::recorded(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/social-posts')));
}

function lfDecide($test, SocialApprovalLink $link, SocialPost $post)
{
    return asApprover($test)->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/decision", ['name' => 'Andi', 'decision' => 'approve']);
}

describe('P2 — scheduling horizon: past it the post is held, not refused (Pro/Max 90 days, 18 Nov 2026)', function () {
    it('sends day 60 at once, holds day 61 here (no relay call, nothing charged), and refuses day 90 + 1 minute', function () {
        $owner = lfOwner();
        $account = lfAccount($owner);
        $post = fn (CarbonInterface $at) => $this->actingAs($owner)->postJson('/api/v1/social-posts', [
            'caption' => 'Hi', 'account_ids' => [$account->ulid], 'scheduled_at' => $at->toIso8601String(),
        ]);

        $post(now()->addDays(60))->assertCreated();
        expect(lfCreates())->toBe(1);
        $balance = Credits::balance($owner);

        $id = $post(now()->addDays(61))->assertCreated()->json('data.id');
        $held = SocialPost::query()->where('ulid', $id)->first();
        expect($held->status->value)->toBe('scheduled')
            ->and($held->provider_post_id)->toBeNull()
            ->and($held->approval)->toBeNull()
            ->and(SocialPostRelay::horizonHeld($held))->toBeTrue()
            ->and(SocialPostRelay::heldForLater($held))->toBeTrue()
            ->and(lfCreates())->toBe(1)
            ->and(Credits::balance($owner))->toBe($balance);

        $post(now()->addDays(90)->addMinute())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at')
            ->assertJsonPath('errors.scheduled_at.0', fn (string $m) => str_contains($m, 'at most 90 days ahead') && str_contains($m, '9 Feb 2027'));
        $post(now()->addDays(90))->assertCreated();
        expect(lfCreates())->toBe(1);
    });

    it('holds a draft sent for a slot past the horizon, and a draft held for the client keeps the client', function () {
        $owner = lfOwner();
        $far = now()->addDays(65)->toIso8601String();
        $draft = SocialPost::factory()->for($owner)->create(['status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => null]);
        $draft->targets()->create(['social_account_id' => lfAccount($owner)->id, 'status' => 'pending']);
        $forClient = SocialPost::factory()->for($owner)->create(['status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => null]);
        $forClient->targets()->create(['social_account_id' => lfAccount($owner)->id, 'status' => 'pending']);

        $this->actingAs($owner)->postJson("/api/v1/social-posts/{$draft->ulid}/send", ['scheduled_at' => $far])->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/social-posts/{$forClient->ulid}/send", ['scheduled_at' => $far, 'approval' => 'client'])->assertOk();

        expect($draft->fresh()->status->value)->toBe('scheduled')
            ->and($draft->fresh()->provider_post_id)->toBeNull()
            ->and(SocialPostRelay::horizonHeld($draft->fresh()))->toBeTrue()
            ->and($forClient->fresh()->approval->value)->toBe('client')
            ->and(lfCreates())->toBe(0)
            ->and(Credits::balance($owner))->toBe(30_000);
    });

    it('takes a sent post moved past the horizon back from the relay, returns its Credits, and holds it', function () {
        $owner = lfOwner();
        $account = lfAccount($owner);
        $id = $this->actingAs($owner)->postJson('/api/v1/social-posts', [
            'caption' => 'Hi', 'account_ids' => [$account->ulid], 'scheduled_at' => now()->addDays(10)->toIso8601String(),
        ])->assertCreated()->json('data.id');
        expect(Credits::balance($owner))->toBeLessThan(30_000);

        $this->actingAs($owner)->patchJson("/api/v1/social-posts/{$id}", ['scheduled_at' => now()->addDays(70)->toIso8601String()])->assertOk();

        $post = SocialPost::query()->where('ulid', $id)->first();
        expect($post->provider_post_id)->toBeNull()
            ->and($post->scheduled_at->getTimestamp())->toBe(now()->addDays(70)->getTimestamp())
            ->and(SocialPostRelay::heldForLater($post))->toBeTrue()
            ->and(Credits::balance($owner))->toBe(30_000)
            ->and(count(Http::recorded(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/social-posts/post_x'))))->toBe(1);
    });

    it('schedules a batch with one post past the horizon: that one held, the other sent', function () {
        $owner = lfOwner();
        $drafts = collect([1, 2])->map(function () use ($owner) {
            $d = SocialPost::factory()->for($owner)->create(['status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => null]);
            $d->targets()->create(['social_account_id' => lfAccount($owner)->id, 'status' => 'pending']);

            return $d;
        });

        $this->actingAs($owner)->postJson('/api/v1/social-posts/send-batch', ['posts' => [
            ['post_id' => $drafts[0]->ulid, 'scheduled_at' => now()->addDays(20)->toIso8601String()],
            ['post_id' => $drafts[1]->ulid, 'scheduled_at' => now()->addDays(65)->toIso8601String()],
        ]])->assertOk();

        expect($drafts[0]->fresh()->provider_post_id)->toBe('post_x')
            ->and($drafts[1]->fresh()->provider_post_id)->toBeNull()
            ->and(SocialPostRelay::heldForLater($drafts[1]->fresh()))->toBeTrue()
            ->and(lfCreates())->toBe(1);
    });

    it('puts a post past the horizon on an approval link', function () {
        $owner = lfOwner();
        $far = SocialPost::factory()->for($owner)->create(['status' => 'scheduled', 'approval' => 'awaiting', 'provider_post_id' => null, 'scheduled_at' => now()->addDays(75)]);

        $this->actingAs($owner)->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [$far->ulid]])
            ->assertCreated();
    });

    it('sends at once on a relay without a horizon', function () {
        config(['services.postforme.max_schedule_days' => null]);
        $owner = lfOwner();

        $this->actingAs($owner)->postJson('/api/v1/social-posts', [
            'caption' => 'Hi', 'account_ids' => [lfAccount($owner)->ulid], 'scheduled_at' => now()->addDays(85)->toIso8601String(),
        ])->assertCreated();
        expect(ScheduleHorizon::days('postforme'))->toBeNull()->and(lfCreates())->toBe(1);
    });

    it('gives paid plans a 90-day window from config; Free keeps 7', function () {
        $pro = lfOwner();
        expect(PlanQuota::scheduleWindowDays($pro))->toBe(90)
            ->and(PlanQuota::scheduleWindowDays(User::factory()->create()))->toBe(7);
        config(['plans.paid_schedule_window_days' => 120]);
        expect(PlanQuota::scheduleWindowDays($pro))->toBe(120);
    });

    it('defaults Outstand to a configured horizon: 30 days (Outstand docs; Rizal, 14 Nov 2026)', function () {
        expect(ScheduleHorizon::days('outstand'))->toBe(30);
        config(['services.outstand.max_schedule_days' => 90]);
        expect(ScheduleHorizon::days('outstand'))->toBe(90);
    });
});

describe('P2 — the client\'s yes is never a dead end', function () {
    it('records the approval of a post past the horizon, holds it, tells the owner, and sends nothing yet', function () {
        Notification::fake();
        $owner = lfOwner();
        [$post, $link] = lfHeld($owner, Carbon::parse('2027-01-15 09:00:00', 'UTC'));

        lfDecide($this, $link, $post)
            ->assertOk()
            ->assertJsonPath('data.approval', 'approved');

        $fresh = $post->fresh();
        expect($fresh->approval->value)->toBe('approved')
            ->and($fresh->approval_by)->toBe('Andi')
            ->and($fresh->status->value)->toBe('scheduled')
            ->and($fresh->provider_post_id)->toBeNull()
            ->and(lfCreates())->toBe(0)
            ->and(Credits::balance($owner))->toBe(30_000);

        Notification::assertSentTo($owner, SocialPostDecided::class, fn (SocialPostDecided $n, array $channels) => $n->decision === SocialPostDecided::HELD
            && $channels === ['mail', 'database']
            && str_contains($n->reason, '15 Jan 2027')
            && str_contains($n->reason, 'at most 60 days')
            && str_contains($n->reason, 'sent automatically from 16 Nov 2026'));
        Notification::assertNotSentTo($owner, SocialPostDecided::class, fn (SocialPostDecided $n) => $n->decision === SocialPostDecided::APPROVED);

        // The same yes again is answered, not refused.
        lfDecide($this, $link, $post)->assertOk();
    });

    it('holds the post when the relay itself refuses the slot as too far out', function () {
        Notification::fake();
        lfRelay(['message' => 'scheduledAt cannot be more than 30 days in the future'], 400);
        $owner = lfOwner();
        [$post, $link] = lfHeld($owner, now()->addDays(40)); // inside our 60, past the relay's own

        lfDecide($this, $link, $post)->assertOk()->assertJsonPath('data.approval', 'approved');

        expect($post->fresh()->provider_post_id)->toBeNull()
            ->and(Credits::balance($owner))->toBe(30_000); // charged, then given back
        Notification::assertSentTo($owner, SocialPostDecided::class, fn ($n) => $n->decision === SocialPostDecided::HELD);
    });

    it('still sends a post inside the horizon at once', function () {
        Notification::fake();
        $owner = lfOwner();
        [$post, $link] = lfHeld($owner, now()->addDays(20));

        lfDecide($this, $link, $post)->assertOk();

        expect($post->fresh()->provider_post_id)->toBe('post_x')
            ->and(lfCreates())->toBe(1);
        Notification::assertSentTo($owner, SocialPostDecided::class, fn ($n) => $n->decision === SocialPostDecided::APPROVED);
    });

    it('approves and holds on the owner\'s own Approve past the horizon, sending nothing yet', function () {
        $owner = lfOwner();
        [$post] = lfHeld($owner, now()->addDays(70));

        $this->actingAs($owner)->postJson("/api/v1/social-posts/{$post->ulid}/approve")->assertOk();
        expect($post->fresh()->approval->value)->toBe('approved')
            ->and(SocialPostRelay::heldForLater($post->fresh()))->toBeTrue()
            ->and(lfCreates())->toBe(0);
    });
});

describe('P2 — SendApprovedHeldPosts sends a held post once it is inside the horizon', function () {
    it('waits while the slot is too far out, then sends and charges it', function () {
        Notification::fake();
        $owner = lfOwner();
        [$post, $link] = lfHeld($owner, Carbon::parse('2027-01-15 09:00:00', 'UTC'));
        lfDecide($this, $link, $post)->assertOk();

        (new SendApprovedHeldPosts)->handle(app(SocialPostRelay::class));
        expect($post->fresh()->provider_post_id)->toBeNull()->and(lfCreates())->toBe(0);

        Carbon::setTestNow(Carbon::parse('2026-11-16 09:00:00', 'UTC')); // 60 days before
        (new SendApprovedHeldPosts)->handle(app(SocialPostRelay::class));

        $fresh = $post->fresh();
        expect($fresh->provider_post_id)->toBe('post_x')
            ->and($fresh->approval->value)->toBe('approved')
            ->and($fresh->scheduled_at->toIso8601String())->toBe('2027-01-15T09:00:00+00:00')
            ->and(lfCreates())->toBe(1)
            ->and(Credits::balance($owner))->toBeLessThan(30_000);

        // Sent once: a second run finds nothing.
        (new SendApprovedHeldPosts)->handle(app(SocialPostRelay::class));
        expect(lfCreates())->toBe(1);
    });

    it('tells the owner once a day when a held post still cannot go, and keeps it approved', function () {
        Notification::fake();
        $owner = lfOwner();
        [$post, $link] = lfHeld($owner, Carbon::parse('2027-01-15 09:00:00', 'UTC'));
        lfDecide($this, $link, $post)->assertOk();
        lfRelay(['message' => 'Media URL could not be fetched'], 422);

        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'UTC'));
        (new SendApprovedHeldPosts)->handle(app(SocialPostRelay::class));
        (new SendApprovedHeldPosts)->handle(app(SocialPostRelay::class));

        expect($post->fresh()->provider_post_id)->toBeNull()
            ->and($post->fresh()->approval->value)->toBe('approved');
        Notification::assertSentToTimes($owner, SocialPostDecided::class, 2); // HELD + one BLOCKED
        Notification::assertSentTo($owner, SocialPostDecided::class, fn ($n) => $n->decision === SocialPostDecided::BLOCKED
            && $n->reason === 'LinkedIn did not accept it: Media URL could not be fetched. It stays approved; we try again automatically.');
    });
});

/** The owner's own post, held here because its slot is past the horizon. */
function lfOwnHeld(User $owner, CarbonInterface $at): SocialPost
{
    $post = SocialPost::factory()->for($owner)->create([
        'provider_post_id' => null, 'status' => 'scheduled', 'approval' => null,
        'caption' => 'February sale', 'scheduled_at' => $at, 'media' => null,
        'raw_payload' => SocialPostRelay::HORIZON_HOLD,
    ]);
    $post->targets()->create(['social_account_id' => lfAccount($owner)->id, 'status' => 'pending']);

    return $post;
}

describe('SendApprovedHeldPosts — the owner\'s own held posts', function () {
    it('waits while the slot is past the horizon, then sends and charges it once', function () {
        $owner = lfOwner();
        $post = lfOwnHeld($owner, Carbon::parse('2027-01-20 09:00:00', 'UTC')); // 70 days out

        (new SendApprovedHeldPosts)->handle(app(SocialPostRelay::class));
        expect($post->fresh()->provider_post_id)->toBeNull()->and(lfCreates())->toBe(0);

        Carbon::setTestNow(Carbon::parse('2026-11-22 09:00:00', 'UTC')); // 59 days before
        (new SendApprovedHeldPosts)->handle(app(SocialPostRelay::class));
        (new SendApprovedHeldPosts)->handle(app(SocialPostRelay::class));

        $fresh = $post->fresh();
        expect($fresh->provider_post_id)->toBe('post_x')
            ->and($fresh->raw_payload)->toBeNull()
            ->and($fresh->scheduled_at->toIso8601String())->toBe('2027-01-20T09:00:00+00:00')
            ->and(lfCreates())->toBe(1)
            ->and(Credits::balance($owner))->toBeLessThan(30_000);
    });

    it('leaves a scheduled post that is not held alone', function () {
        $owner = lfOwner();
        $post = lfOwnHeld($owner, now()->addDays(5));
        $post->update(['raw_payload' => null]);

        (new SendApprovedHeldPosts)->handle(app(SocialPostRelay::class));
        expect(lfCreates())->toBe(0);
    });

    it('marks one that cannot go Failed and tells the owner once, nothing charged', function () {
        Notification::fake();
        $owner = lfOwner();
        $post = lfOwnHeld($owner, now()->addDays(20));
        lfRelay(['message' => 'Media URL could not be fetched'], 422);

        (new SendApprovedHeldPosts)->handle(app(SocialPostRelay::class));
        (new SendApprovedHeldPosts)->handle(app(SocialPostRelay::class));

        $fresh = $post->fresh();
        expect($fresh->status->value)->toBe('failed')
            ->and($fresh->fail_reason)->toContain('Media URL could not be fetched')
            ->and($fresh->raw_payload)->toBeNull()
            ->and($fresh->targets()->first()->status->value)->toBe('failed')
            ->and(Credits::balance($owner))->toBe(30_000)
            ->and(lfCreates())->toBe(1);
        Notification::assertSentToTimes($owner, SocialPostFailed::class, 1);
    });

    it('fails an owner-approved held post off any link too, so it shows', function () {
        Notification::fake();
        $owner = User::factory()->create();
        Subscription::factory()->for($owner)->create(); // Pro, and no Credits
        $post = lfOwnHeld($owner, now()->addDays(20));
        $post->update(['raw_payload' => null, 'approval' => 'approved', 'approval_by' => 'Owner']);

        (new SendApprovedHeldPosts)->handle(app(SocialPostRelay::class));

        expect($post->fresh()->status->value)->toBe('failed')
            ->and($post->fresh()->approval)->toBeNull()
            ->and($post->fresh()->approval_by)->toBe('Owner');
        Notification::assertSentTo($owner, SocialPostFailed::class);
    });
});

describe('P2 — owner copy names the network, never the relay', function () {
    it('says "{Network} did not accept it: {reason}" when the relay refuses a client\'s yes', function () {
        Notification::fake();
        lfRelay(['message' => 'Caption contains a banned word'], 422);
        $owner = lfOwner();
        [$post, $link] = lfHeld($owner, now()->addDays(3));

        lfDecide($this, $link, $post)
            ->assertUnprocessable()
            ->assertJsonPath('message', "This can’t go out right now — {$owner->name} has been told.");

        Notification::assertSentTo($owner, SocialPostDecided::class, function (SocialPostDecided $n) {
            return $n->decision === SocialPostDecided::BLOCKED
                && $n->reason === 'LinkedIn did not accept it: Caption contains a banned word. Try sending it again from Approval links.'
                && ! str_contains($n->reason, 'Post for Me');
        });
        expect($post->fresh()->approval->value)->toBe('client');
    });

    it('has no "Post for Me" left in the approval controllers', function () {
        foreach (['PublicApprovalController', 'SocialApprovalController'] as $c) {
            expect(file_get_contents(app_path("Http/Controllers/Api/V1/{$c}.php")))->not->toContain('Post for Me did not accept');
        }
    });
});

describe('P3 — refusals are not logged as errors', function () {
    beforeEach(function () {
        ErrorTracking::flushState();
        Route::middleware('api')->group(function () {
            Route::get('api/_test/refuse/{kind}', function (string $kind) {
                $user = User::factory()->create();
                throw match ($kind) {
                    'plan' => PlanFeatureRequired::feature($user, 'insight'),
                    'preflight' => new PublishPreflightFailed([['account_id' => 'a', 'platform' => 'instagram', 'code' => 'needs_media', 'reason' => 'Instagram needs an image.']]),
                    'format' => MediaRefused::unsupportedFormat(),
                    'too_large' => MediaRefused::tooLarge(null, null),
                    'credits' => NotEnoughCredits::for($user, 300),
                    'horizon' => new ScheduleBeyondHorizon(60, now()->toImmutable()),
                    default => new RuntimeException('real failure'),
                };
            });
        });
    });

    it('logs each refusal at info or warning and keeps it out of error tracking; a real error stays error', function (string $kind, int $status, string $level) {
        $fake = Analytics::fake();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->level;
        });

        $this->getJson("/api/_test/refuse/{$kind}")->assertStatus($status);

        expect($logged)->toBe([$level]);
        expect($fake->captured('$exception'))->toHaveCount($level === 'error' ? 1 : 0);
    })->with([
        'plan_required 402' => ['plan', 402, 'info'],
        'preflight 422' => ['preflight', 422, 'info'],
        'unsupported_format 422' => ['format', 422, 'warning'],
        'file_too_large 422' => ['too_large', 422, 'warning'],
        'insufficient credits 422' => ['credits', 422, 'info'],
        'schedule horizon 422' => ['horizon', 422, 'info'],
        'real error 500' => ['boom', 500, 'error'],
    ]);
});

describe('P3 — get_credits has no committed.rent', function () {
    it('answers committed with the sends only', function () {
        $owner = lfOwner();

        $committed = $this->actingAs($owner)->getJson('/api/v1/credits')->assertOk()->json('data.committed');

        expect(array_keys($committed))->toBe(['sends', 'sends_monthly']);
    });
});
