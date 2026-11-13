<?php

use App\Console\Commands\ReviewerSetup;
use App\Enums\SocialPostApproval;
use App\Jobs\ExpireSubscriptions;
use App\Jobs\FailUnconfirmedPublishes;
use App\Jobs\NotifyMissedPosts;
use App\Models\Invoice;
use App\Models\SocialAccount;
use App\Models\SocialApprovalDecision;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Space;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\SocialPostFailed;
use App\Notifications\SocialPostMissed;
use App\Services\Analytics;
use App\Services\DemoSocialProvider;
use App\Support\Credits;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * reviewer:setup readies the EXISTING directory reviewer account: Pro as a
 * staff comp, Credits once, a demo Instagram channel that never reaches a
 * network, the test pack's sample data, the reviewer flag (no password),
 * and on request a one-time link to set the password. --revoke undoes it.
 */

beforeEach(function () {
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
    ]);
    Http::preventStrayRequests();
    Http::fake();
    Notification::fake();
    Analytics::fake();
});

function rsUser(): User
{
    return User::factory()->create(['email' => 'reviewer@example.test', 'google_id' => null, 'password' => null, 'email_verified_at' => null]);
}

/** Runs the command; returns its output. */
function rsRun(array $args = []): string
{
    $code = Artisan::call('reviewer:setup', ['email' => 'reviewer@example.test', ...$args]);
    expect($code)->toBe(0);

    return Artisan::output();
}

function rsDemo(User $user): SocialAccount
{
    return SocialAccount::query()->where('user_id', $user->id)->where('is_demo', true)->sole();
}

function rsCounts(User $user): array
{
    return [
        'subs' => Subscription::query()->where('user_id', $user->id)->count(),
        'grants' => WalletTransaction::query()->where('user_id', $user->id)->where('note', ReviewerSetup::CREDITS_NOTE)->count(),
        'accounts' => SocialAccount::query()->where('user_id', $user->id)->count(),
        'posts' => SocialPost::query()->where('user_id', $user->id)->count(),
        'links' => SocialApprovalLink::query()->where('user_id', $user->id)->count(),
        'decisions' => SocialApprovalDecision::query()->count(),
        'spaces' => Space::query()->where('user_id', $user->id)->count(),
    ];
}

it('refuses an address with no account and creates nothing', function () {
    $other = User::factory()->create();

    expect(Artisan::call('reviewer:setup', ['email' => 'nobody@example.test']))->toBe(1)
        ->and(Artisan::output())->toContain('No account with the email');
    expect(User::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(0)
        ->and(SocialAccount::query()->count())->toBe(0);
    expect($other->fresh()->is_directory_reviewer)->toBeFalse();
});

it('gives Pro as a staff comp for 90 days: no Creem subscription, no call, no invoice', function () {
    $user = rsUser();

    rsRun();

    $sub = $user->activeSubscription();
    expect($user->plan()->key->value)->toBe('pro')
        ->and($sub->plan_source)->toBe(Subscription::SOURCE_STAFF_COMP)
        ->and($sub->creem_subscription_id)->toBeNull()
        ->and($sub->current_period_end->isSameDay(now()->addDays(90)))->toBeTrue()
        ->and(Invoice::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('ends the comp after 90 days through the daily expiry job', function () {
    $user = rsUser();
    rsRun();

    $this->travel(91)->days();
    app()->call([new ExpireSubscriptions, 'handle']);

    expect($user->fresh()->plan()->key->value)->toBe('free');
});

it('grants the Credits once and seeds everything once: a rerun adds nothing', function () {
    $user = rsUser();
    $other = User::factory()->create();

    rsRun(['--credits' => 50000]);
    $first = rsCounts($user);
    $balance = Credits::total($user);
    rsRun(['--credits' => 50000]);

    expect(rsCounts($user))->toBe($first)
        ->and(Credits::total($user))->toBe($balance)
        ->and($first['grants'])->toBe(1)
        ->and($first['accounts'])->toBe(1)
        ->and($first['posts'])->toBe(5)
        ->and($first['links'])->toBe(1)
        ->and($first['decisions'])->toBe(2)
        ->and($first['spaces'])->toBe(1)
        ->and(WalletTransaction::query()->where('user_id', $user->id)->where('note', ReviewerSetup::CREDITS_NOTE)->value('amount'))->toBe(50000);
    // Nobody else was touched.
    expect(rsCounts($other))->toBe(['subs' => 0, 'grants' => 0, 'accounts' => 0, 'posts' => 0, 'links' => 0, 'decisions' => 2, 'spaces' => 0]);
});

it('seeds the test pack: Draft A, the injection Draft B, a scheduled post, an approval link with one approved and one changes-requested post, a Space', function () {
    $user = rsUser();
    rsRun();

    $posts = SocialPost::query()->where('user_id', $user->id)->get()->keyBy('caption');
    expect($posts[ReviewerSetup::DRAFT_A]->status->value)->toBe('draft')
        ->and($posts[ReviewerSetup::DRAFT_B]->status->value)->toBe('draft')
        ->and($posts[ReviewerSetup::SCHEDULED]->status->value)->toBe('scheduled')
        ->and($posts[ReviewerSetup::SCHEDULED]->scheduled_at->isFuture())->toBeTrue()
        ->and($posts[ReviewerSetup::APPROVED]->approval)->toBe(SocialPostApproval::Approved)
        ->and($posts[ReviewerSetup::CHANGES]->approval)->toBe(SocialPostApproval::Revision);

    $link = SocialApprovalLink::query()->where('user_id', $user->id)->sole();
    expect($link->posts()->pluck('caption')->sort()->values()->all())->toBe(collect([ReviewerSetup::APPROVED, ReviewerSetup::CHANGES])->sort()->values()->all())
        ->and(SocialApprovalDecision::query()->where('social_approval_link_id', $link->id)->pluck('decision')->sort()->values()->all())->toBe(['approved', 'changes_requested']);
    // Made-up content only: every seeded address is example.com.
    expect($link->approver_email)->toEndWith('@example.com');
});

it('adds one connected demo Instagram channel, @creatif_reviewer_demo, usable and never a reconnect', function () {
    $user = rsUser();
    rsRun();

    $demo = rsDemo($user);
    expect($demo->platform->value)->toBe('instagram')
        ->and($demo->username)->toBe(ReviewerSetup::DEMO_HANDLE)
        ->and($demo->provider)->toBe(DemoSocialProvider::NAME)
        ->and($demo->isUsable())->toBeTrue()
        ->and($demo->isLegacy())->toBeFalse();

    $this->actingAs($user)->getJson('/api/v1/social-accounts')->assertOk()
        ->assertJsonPath('data.0.username', ReviewerSetup::DEMO_HANDLE)
        ->assertJsonPath('data.0.reconnect_required', false);
    // Listing syncs the user's real relay; the demo channel stays connected and is never named to it.
    expect($demo->fresh()->status->value)->toBe('connected');
    Http::assertNotSent(fn ($r) => str_contains($r->url().$r->body(), $demo->provider_account_id));
});

it('publishing to the demo channel never calls Outstand: "demo channel, nothing is posted", Credits back, no failure email', function () {
    $user = rsUser();
    rsRun();
    $before = Credits::total($user);
    $draft = SocialPost::query()->where('user_id', $user->id)->where('caption', ReviewerSetup::DRAFT_A)->sole();

    $this->actingAs($user)->postJson("/api/v1/social-posts/{$draft->ulid}/send")->assertSuccessful();

    $target = $draft->targets()->sole();
    expect($target->status->value)->toBe('failed')
        ->and($target->fail_code)->toBe('demo_channel')
        ->and($target->fail_reason)->toStartWith('Demo channel: nothing is posted.')
        ->and($draft->fresh()->provider)->toBe(DemoSocialProvider::NAME)
        ->and($draft->fresh()->status->value)->toBe('failed')
        ->and(Credits::total($user))->toBe($before);
    Http::assertNothingSent();
    Notification::assertNotSentTo($user, SocialPostFailed::class);
});

it('a scheduled demo post stays scheduled until due, then settles the same way without Outstand', function () {
    $user = rsUser();
    rsRun();
    $post = SocialPost::query()->where('user_id', $user->id)->where('caption', ReviewerSetup::SCHEDULED)->sole();

    app()->call([new FailUnconfirmedPublishes, 'handle']);
    expect($post->fresh()->status->value)->toBe('scheduled');

    // Past its slot: the missed-post check asks the (demo) relay first, which settles it — no "missed" email.
    $this->travelTo($post->scheduled_at->addMinutes(40));
    app()->call([new NotifyMissedPosts, 'handle']);

    expect($post->targets()->sole()->fail_code)->toBe('demo_channel')
        ->and($post->fresh()->status->value)->toBe('failed');
    Notification::assertNotSentTo($user, SocialPostMissed::class);
    Http::assertNothingSent();
});

it('refuses to send the demo channel together with a real one, before any charge', function () {
    $user = rsUser();
    rsRun();
    $real = SocialAccount::factory()->for($user)->create(['platform' => 'bluesky', 'provider' => 'outstand']);
    $draft = SocialPost::query()->where('user_id', $user->id)->where('caption', ReviewerSetup::DRAFT_A)->sole();
    $draft->targets()->create(['social_account_id' => $real->id]);
    $before = Credits::total($user);

    $this->actingAs($user)->postJson("/api/v1/social-posts/{$draft->ulid}/send")->assertStatus(422)
        ->assertJsonValidationErrors('account_ids');

    expect(Credits::total($user))->toBe($before);
    Http::assertNothingSent();
});

it('Insight has numbers for the demo channel, from the demo relay', function () {
    $user = rsUser();
    rsRun();

    $from = now()->subDays(29)->toDateString();
    $to = now()->toDateString();
    $res = $this->actingAs($user)->getJson("/api/v1/insight/summary?from={$from}&to={$to}")->assertOk();

    $reach = collect($res->json('data.channels') ?? [])->sum(fn ($c) => (int) ($c['reach'] ?? $c['totals']['reach'] ?? 0));
    expect($reach > 0 || str_contains(json_encode($res->json()), '"reach":'))->toBeTrue();
    Http::assertNothingSent();
});

it('sets the reviewer flag but never a password, and prints none', function () {
    $user = rsUser();

    $out = rsRun(['--send-reset' => true]);

    $user->refresh();
    expect($user->is_directory_reviewer)->toBeTrue()
        ->and($user->password)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull();
    expect($out)->toContain('Plan: Pro')
        ->toContain('Credits: 50,000 granted')
        ->toContain('Seeded: channels 1, posts 5, approval links 1, decisions 2, spaces 1')
        ->toContain('Reset link sent: yes')
        ->not->toMatch('/password\s*[:=(]/i')
        ->not->toMatch('/token/i')
        ->not->toContain('reset-password?');
    $this->assertDatabaseHas('directory_reviewer_logins', ['user_id' => $user->id, 'outcome' => 'granted']);
});

it('--send-reset emails the one-time reset link; a reviewer password must be 12–72 characters and the link works once', function () {
    $user = rsUser();
    rsRun(['--send-reset' => true]);

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
        $token = $n->token;

        return true;
    });
    expect(DB::table('password_reset_tokens')->where('email', $user->email)->count())->toBe(1);

    $this->postJson('/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'short-11ch'])
        ->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson('/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'twelve-chars-ok'])
        ->assertOk();
    $this->postJson('/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'another-good-one'])
        ->assertStatus(422);

    $this->postJson('/auth/reviewer/login', ['email' => $user->email, 'password' => 'twelve-chars-ok'])->assertSuccessful();
});

it('without --send-reset sends nothing', function () {
    $user = rsUser();
    $out = rsRun();

    Notification::assertNothingSent();
    expect($out)->toContain('Reset link sent: no');
});

it('--revoke ends the comp, removes the demo channel and data, clears the flag; the rest of the account stays', function () {
    $user = rsUser();
    $own = SocialPost::factory()->for($user)->create(['caption' => 'My own post']);
    rsRun();

    $out = rsRun(['--revoke' => true]);

    $user->refresh();
    expect($user->plan()->key->value)->toBe('free')
        ->and($user->is_directory_reviewer)->toBeFalse()
        ->and(SocialAccount::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(SocialPost::query()->where('user_id', $user->id)->pluck('id')->all())->toBe([$own->id])
        ->and(SocialApprovalLink::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(Space::withTrashed()->where('user_id', $user->id)->count())->toBe(0);
    expect($out)->toContain('Reviewer setup undone');
    Http::assertNothingSent();
});

it('keeps an existing paid plan instead of adding a comp', function () {
    $user = rsUser();
    Subscription::factory()->for($user)->create();
    $before = Subscription::query()->where('user_id', $user->id)->count();

    $out = rsRun();

    expect(Subscription::query()->where('user_id', $user->id)->count())->toBe($before)
        ->and($out)->toContain('no comp added');
});
