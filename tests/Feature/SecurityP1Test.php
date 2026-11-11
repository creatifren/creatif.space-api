<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPlanLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\TeamMember;
use App\Models\User;
use App\Support\Credits;
use App\Support\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;

beforeEach(function () {
    Workspace::forget();
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
        'app.frontend_url' => 'https://app.test',
    ]);
});

/** A held post with one destination, owned by $user. */
function p1HeldPost(User $user, string $approval = 'awaiting'): SocialPost
{
    $post = SocialPost::factory()->for($user)->create(['provider_post_id' => null, 'approval' => $approval]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($user)->create()->id, 'status' => 'pending']);

    return $post;
}

function p1Link(SocialPost ...$posts): SocialApprovalLink
{
    $link = SocialApprovalLink::create(['user_id' => $posts[0]->user_id, 'title' => 'Nov', 'client_name' => 'Andi']);
    $link->posts()->attach(collect($posts)->pluck('id'));

    return $link;
}

/** The migration's own backfill, as it ran on rows that existed before it. */
function p1Backfill(string $table): void
{
    $migration = require base_path('database/migrations/2026_11_12_100000_add_token_hash_to_link_tables.php');
    $migration->backfill($table);
}

describe('link tokens are looked up by their hash', function () {
    it('stores sha256(token) for a new approval link and opens by the URL token', function () {
        $link = p1Link(p1HeldPost(User::factory()->create(), 'client'));

        expect($link->fresh()->token_hash)->toBe(hash('sha256', $link->token))
            ->and($link->url())->toEndWith('/a/'.$link->token);
        $this->getJson("/api/v1/a/{$link->token}")->assertOk();
    });

    it('opens a link made before the migration once it is backfilled', function () {
        $link = p1Link(p1HeldPost(User::factory()->create(), 'client'));
        $plan = SocialPlanLink::query()->create([
            'user_id' => $link->user_id, 'title' => 'Plan', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'account_ids' => [],
        ]);
        // As the rows were before: a token, no hash.
        DB::table('social_approval_links')->where('id', $link->id)->update(['token_hash' => null]);
        DB::table('social_plan_links')->where('id', $plan->id)->update(['token_hash' => null]);
        $this->getJson("/api/v1/a/{$link->token}")->assertNotFound();

        p1Backfill('social_approval_links');
        p1Backfill('social_plan_links');

        $this->getJson("/api/v1/a/{$link->token}")->assertOk();
        $this->getJson("/api/v1/plan/{$plan->token}")->assertOk();
    });

    it('never reads the plaintext column: only the hash decides', function () {
        $link = p1Link(p1HeldPost(User::factory()->create(), 'client'));
        $plan = SocialPlanLink::query()->create([
            'user_id' => $link->user_id, 'title' => 'Plan', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'account_ids' => [],
        ]);
        $approvalToken = $link->token;
        $planToken = $plan->token;

        // Change the stored plaintext: the URL still opens, by its hash.
        DB::table('social_approval_links')->where('id', $link->id)->update(['token' => 'changed-in-db']);
        DB::table('social_plan_links')->where('id', $plan->id)->update(['token' => 'changed-in-db']);

        $this->getJson("/api/v1/a/{$approvalToken}")->assertOk();
        $this->getJson("/api/v1/plan/{$planToken}")->assertOk();
        $this->getJson('/api/v1/a/changed-in-db')->assertNotFound();
        $this->getJson('/api/v1/plan/changed-in-db')->assertNotFound();

        // And a stolen hash is not a token.
        $this->getJson('/api/v1/a/'.$link->fresh()->token_hash)->assertNotFound();
        $this->getJson('/api/v1/plan/'.$plan->fresh()->token_hash)->assertNotFound();
    });

    it('decides and comments through the hashed lookup too', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_x', 'status' => 'scheduled'])]);
        $owner = User::factory()->create();
        Subscription::factory()->for($owner)->create();
        Credits::grant($owner, 30_000, 'Test');
        $post = p1HeldPost($owner, 'client');
        $link = p1Link($post);

        asApprover($this)->postJson("/api/v1/a/{$link->token}/posts/{$post->ulid}/comments", ['body' => 'Looks good', 'name' => 'Andi'])
            ->assertCreated();
    });
});

/** Socialite answers the Google callback as this Google account. */
function p1Google(string $id, string $email): void
{
    $google = (new GoogleUser)->map(['id' => $id, 'name' => 'Someone', 'email' => $email, 'avatar' => null]);
    $driver = Mockery::mock(GoogleProvider::class);
    $driver->shouldReceive('user')->andReturn($google);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
}

describe('Google login never links a staff account by email', function () {
    it('refuses a staff address with no google_id, and links nothing', function () {
        $staff = User::factory()->create(['email' => 'admin@creatif.space', 'is_staff' => true, 'google_id' => null]);
        p1Google('g-attacker', 'admin@creatif.space');

        $this->get('/auth/google/callback?code=abc')
            ->assertRedirect('https://app.test/login?error=google_not_linked');

        $this->assertGuest();
        expect($staff->fresh()->google_id)->toBeNull();
    });

    it('refuses a staff address whose google_id is a different one', function () {
        User::factory()->create(['email' => 'admin@creatif.space', 'is_staff' => true, 'google_id' => 'g-real']);
        p1Google('g-other', 'admin@creatif.space');

        $this->get('/auth/google/callback?code=abc')
            ->assertRedirect('https://app.test/login?error=google_not_linked');
        $this->assertGuest();
    });

    it('signs staff in when the google_id matches', function () {
        $staff = User::factory()->create(['email' => 'admin@creatif.space', 'is_staff' => true, 'google_id' => 'g-real']);
        p1Google('g-real', 'admin@creatif.space');

        $this->get('/auth/google/callback?code=abc')->assertRedirect();
        $this->assertAuthenticatedAs($staff);
    });

    it('keeps linking an ordinary account by email, as before', function () {
        $user = User::factory()->create(['email' => 'rani@example.com', 'google_id' => null]);
        p1Google('g-rani', 'rani@example.com');

        $this->get('/auth/google/callback?code=abc')->assertRedirect();
        $this->assertAuthenticatedAs($user);
        expect($user->fresh()->google_id)->toBe('g-rani');
    });

    it('prefers the account that owns the google_id over one that only shares the email', function () {
        $byId = User::factory()->create(['email' => 'old@example.com', 'google_id' => 'g-1']);
        User::factory()->create(['email' => 'new@example.com', 'google_id' => null]);
        p1Google('g-1', 'new@example.com');

        $this->get('/auth/google/callback?code=abc')->assertRedirect();
        $this->assertAuthenticatedAs($byId);
    });
});

/** An owner on Team, and a member of theirs with $role. */
function p1Team(string $role = 'editor'): array
{
    $owner = User::factory()->create();
    $subscription = $owner->subscriptions()->create([
        'plan_id' => Plan::query()->where('key', 'team')->value('id'),
        'billing_period' => 'monthly', 'seats' => 3, 'status' => 'active',
    ]);
    $subscription->forceFill(['current_period_start' => now()->subDays(10), 'current_period_end' => now()->addDays(20)])->save();
    Credits::grant($owner, 30_000, 'Test');

    $member = User::factory()->create();
    TeamMember::factory()->create([
        'owner_id' => $owner->id, 'member_id' => $member->id, 'email' => $member->email,
        'role' => $role, 'status' => 'active', 'joined_at' => now(),
    ]);
    Workspace::forget();

    return [$owner, $member];
}

describe('team members act on the owner\'s approvals and feed', function () {
    it('lets an editor see, share, approve and comment on the owner\'s posts and links', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['id' => 'post_x', 'status' => 'scheduled'])]);
        [$owner, $member] = p1Team('editor');
        $held = p1HeldPost($owner, 'awaiting');
        $toShare = p1HeldPost($owner, 'awaiting');
        $link = p1Link(p1HeldPost($owner, 'client'));

        $this->actingAs($member)->getJson('/api/v1/social-approval-links')->assertOk()->assertJsonPath('data.0.id', $link->ulid);
        $this->actingAs($member)->getJson("/api/v1/social-approval-links/{$link->ulid}")->assertOk();
        $this->actingAs($member)->getJson("/api/v1/social-posts/{$held->ulid}/comments")->assertOk();
        $this->actingAs($member)->postJson("/api/v1/social-posts/{$held->ulid}/comments", ['body' => 'Tweak the opener'])->assertCreated();

        $made = $this->actingAs($member)->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [$toShare->ulid]])
            ->assertCreated()->json('data.id');
        expect(SocialApprovalLink::query()->where('ulid', $made)->value('user_id'))->toBe($owner->id);

        $this->actingAs($member)->postJson("/api/v1/social-posts/{$held->ulid}/approve")->assertOk();
    });

    it('lets a viewer read but not act', function () {
        [$owner, $viewer] = p1Team('viewer');
        $held = p1HeldPost($owner, 'awaiting');
        $link = p1Link(p1HeldPost($owner, 'client'));

        $this->actingAs($viewer)->getJson("/api/v1/social-approval-links/{$link->ulid}")->assertOk();
        $this->actingAs($viewer)->getJson("/api/v1/social-posts/{$held->ulid}/comments")->assertOk();

        $this->actingAs($viewer)->postJson("/api/v1/social-posts/{$held->ulid}/approve")->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/v1/social-approval-links/{$link->ulid}")->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/v1/social-posts/{$held->ulid}/comments", ['body' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [$held->ulid]])->assertForbidden();
    });

    it('still refuses someone outside the workspace', function () {
        [$owner] = p1Team('editor');
        $outsider = User::factory()->create();
        $held = p1HeldPost($owner, 'awaiting');
        $link = p1Link(p1HeldPost($owner, 'client'));
        $account = SocialAccount::factory()->for($owner)->create();

        $this->actingAs($outsider)->getJson("/api/v1/social-approval-links/{$link->ulid}")->assertForbidden();
        $this->actingAs($outsider)->postJson("/api/v1/social-posts/{$held->ulid}/approve")->assertForbidden();
        $this->actingAs($outsider)->getJson('/api/v1/social-approval-links')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($outsider)->getJson("/api/v1/social-accounts/{$account->ulid}/feed")->assertForbidden();
    });

    it('lets a member read the owner\'s account feed', function () {
        // The provider has no feed for it: the gate passed, the provider answered.
        Http::fake(['*' => Http::response(['message' => 'not found'], 404)]);
        [$owner, $member] = p1Team('viewer');
        $account = SocialAccount::factory()->for($owner)->create();

        $status = $this->actingAs($member)->getJson("/api/v1/social-accounts/{$account->ulid}/feed")->status();
        expect($status)->not->toBe(403);
    });
});
