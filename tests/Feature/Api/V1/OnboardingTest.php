<?php

use App\Enums\SocialPostTargetStatus;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\TeamMember;
use App\Models\User;
use App\Support\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * GET /me/onboarding — Today's "Get started" checklist from the data:
 * connect → draft → send → publish, each {done, at}, plus the newest draft.
 */

beforeEach(fn () => Workspace::forget());

function onbUser(): User
{
    return User::factory()->create()->fresh();
}

it('says nothing is done for a new account', function () {
    $this->actingAs(onbUser())->getJson('/api/v1/me/onboarding')
        ->assertOk()
        ->assertExactJson(['data' => [
            'connect' => ['done' => false, 'at' => null],
            'draft' => ['done' => false, 'at' => null],
            'send' => ['done' => false, 'at' => null],
            'publish' => ['done' => false, 'at' => null],
            'draft_post_id' => null,
        ]]);
});

it('marks each step done with when it first happened', function () {
    $user = onbUser();
    $this->travelTo(Carbon::parse('2026-11-10 09:00:00'));
    $ig = SocialAccount::factory()->for($user)->create();
    $this->travelTo(Carbon::parse('2026-11-10 10:00:00'));
    SocialAccount::factory()->for($user)->create();
    $this->travelTo(Carbon::parse('2026-11-10 11:00:00'));
    $first = SocialPost::factory()->for($user)->create(['status' => 'draft']);
    $this->travelTo(Carbon::parse('2026-11-10 12:00:00'));
    SocialApprovalLink::create(['user_id' => $user->id]);
    $this->travelTo(Carbon::parse('2026-11-10 13:00:00'));
    SocialPostTarget::query()->create([
        'social_post_id' => $first->id, 'social_account_id' => $ig->id, 'status' => SocialPostTargetStatus::Published,
    ]);
    $this->travelTo(Carbon::parse('2026-11-10 14:00:00'));
    $newest = SocialPost::factory()->for($user)->create(['status' => 'draft']);

    $data = $this->actingAs($user)->getJson('/api/v1/me/onboarding')->assertOk()->json('data');

    expect($data['connect'])->toBe(['done' => true, 'at' => Carbon::parse('2026-11-10 09:00:00')->toIso8601String()])
        ->and($data['draft'])->toBe(['done' => true, 'at' => Carbon::parse('2026-11-10 11:00:00')->toIso8601String()])
        ->and($data['send'])->toBe(['done' => true, 'at' => Carbon::parse('2026-11-10 12:00:00')->toIso8601String()])
        ->and($data['publish'])->toBe(['done' => true, 'at' => Carbon::parse('2026-11-10 13:00:00')->toIso8601String()])
        ->and($data['draft_post_id'])->toBe($newest->ulid);
});

it('does not count a destination that has not gone out, or a post that is not a draft as the draft to open', function () {
    $user = onbUser();
    $account = SocialAccount::factory()->for($user)->create();
    $post = SocialPost::factory()->for($user)->create(['status' => 'scheduled', 'scheduled_at' => now()->addDay()]);
    SocialPostTarget::query()->create(['social_post_id' => $post->id, 'social_account_id' => $account->id, 'status' => SocialPostTargetStatus::Pending]);

    $data = $this->actingAs($user)->getJson('/api/v1/me/onboarding')->json('data');

    expect($data['draft']['done'])->toBeTrue()
        ->and($data['publish'])->toBe(['done' => false, 'at' => null])
        ->and($data['draft_post_id'])->toBeNull();
});

it('counts the whole workspace, and nobody else', function () {
    $owner = onbUser();
    $member = onbUser();
    TeamMember::factory()->active($member)->create(['owner_id' => $owner->id]);
    SocialAccount::factory()->for($owner)->create();
    SocialPost::factory()->for($member)->create(['status' => 'draft']);
    $stranger = onbUser();
    SocialApprovalLink::create(['user_id' => $stranger->id]);

    $forMember = $this->actingAs($member)->getJson('/api/v1/me/onboarding')->json('data');
    expect($forMember['connect']['done'])->toBeTrue()
        ->and($forMember['draft']['done'])->toBeTrue()
        ->and($forMember['send']['done'])->toBeFalse();

    Workspace::forget();
    $forStranger = $this->actingAs($stranger)->getJson('/api/v1/me/onboarding')->json('data');
    expect($forStranger['send']['done'])->toBeTrue()
        ->and($forStranger['connect']['done'])->toBeFalse()
        ->and($forStranger['draft_post_id'])->toBeNull();
});

it('stays cheap: the same few queries for 1 post or 20', function () {
    $count = function (User $user): int {
        Workspace::forget();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->getJson('/api/v1/me/onboarding')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $seed = function (User $user, int $posts): void {
        $account = SocialAccount::factory()->for($user)->create();
        foreach (range(1, $posts) as $i) {
            $post = SocialPost::factory()->for($user)->create(['status' => 'draft']);
            SocialPostTarget::query()->create(['social_post_id' => $post->id, 'social_account_id' => $account->id, 'status' => SocialPostTargetStatus::Published]);
        }
    };

    $small = onbUser();
    $seed($small, 1);
    $large = onbUser();
    $seed($large, 20);

    $few = $count($small);
    expect($count($large))->toBe($few)
        ->and($few)->toBeLessThanOrEqual(15);
});

it('needs a signed-in user', function () {
    $this->getJson('/api/v1/me/onboarding')->assertUnauthorized();
});
