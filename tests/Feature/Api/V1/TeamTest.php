<?php

use App\Enums\TeamMemberStatus;
use App\Models\Plan;
use App\Models\Space;
use App\Models\Subscription;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\TeamInvited;
use App\Support\TeamInvite;
use App\Support\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * An owner on Team with room for people. Seats live on the subscription —
 * what was bought — while the plan says how many are included.
 */
function teamOwner(int $seats = 3): User
{
    $user = User::factory()->create();

    $subscription = $user->subscriptions()->create([
        'plan_id' => Plan::query()->where('key', 'team')->value('id'),
        'billing_period' => 'monthly',
        'seats' => $seats,
        'status' => 'active',
    ]);

    $subscription->forceFill([
        'current_period_start' => now()->subDays(10),
        'current_period_end' => now()->addDays(20),
    ])->save();

    Workspace::forget();

    return $user;
}

/** Somebody who has accepted a seat on $owner's team. */
function seatedMember(User $owner, string $role = 'editor'): User
{
    $member = User::factory()->create();

    TeamMember::factory()->create([
        'owner_id' => $owner->id,
        'member_id' => $member->id,
        'email' => $member->email,
        'role' => $role,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    Workspace::forget();

    return $member;
}

beforeEach(fn () => Workspace::forget());

describe('inviting', function () {
    it('sends an invite and holds the seat before anybody accepts', function () {
        Notification::fake();
        $owner = teamOwner();

        $this->actingAs($owner)
            ->postJson('/api/v1/team/members', ['email' => 'Budi@Studio.test'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'invited')
            ->assertJsonPath('data.email', 'budi@studio.test');

        // Two of three: the owner holds one seat themselves, and the
        // invitation holds another before anybody has accepted it.
        expect(Workspace::seatsUsed($owner))->toBe(2);

        Notification::assertSentTo(TeamMember::query()->sole(), TeamInvited::class);
    });

    it('refuses once the seats are gone, and says how many there are', function () {
        Notification::fake();
        $owner = teamOwner(seats: 2);

        $this->actingAs($owner)
            ->postJson('/api/v1/team/members', ['email' => 'one@studio.test'])
            ->assertCreated();

        $this->actingAs($owner)
            ->postJson('/api/v1/team/members', ['email' => 'two@studio.test'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'You have 2 seats. Add another from Settings → Subscription.');
    });

    it('refuses on Free and Premium with the same sentence — the quota does the work', function () {
        Notification::fake();
        $free = User::factory()->create();

        $premium = User::factory()->create();
        $premium->subscriptions()->create([
            'plan_id' => Plan::query()->where('key', 'premium')->value('id'),
            'billing_period' => 'monthly',
            'status' => 'active',
        ]);

        foreach ([$free, $premium] as $user) {
            $this->actingAs($user)
                ->postJson('/api/v1/team/members', ['email' => 'someone@studio.test'])
                ->assertUnprocessable()
                ->assertJsonPath('errors.email.0', 'You have 1 seats. Add another from Settings → Subscription.');
        }
    });

    it('refuses your own address and a duplicate invite', function () {
        Notification::fake();
        $owner = teamOwner();

        $this->actingAs($owner)
            ->postJson('/api/v1/team/members', ['email' => $owner->email])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'That’s your own account — you already have it.');

        $this->actingAs($owner)->postJson('/api/v1/team/members', ['email' => 'budi@studio.test']);
        $this->actingAs($owner)
            ->postJson('/api/v1/team/members', ['email' => 'budi@studio.test'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'They’re already on your team.');
    });

    it('re-invites somebody who was removed, without a second row', function () {
        Notification::fake();
        $owner = teamOwner();
        $member = seatedMember($owner);

        $seat = TeamMember::query()->sole();
        $this->actingAs($owner)->deleteJson("/api/v1/team/members/{$seat->ulid}")->assertNoContent();

        $this->actingAs($owner)
            ->postJson('/api/v1/team/members', ['email' => $member->email])
            ->assertCreated();

        expect(TeamMember::query()->count())->toBe(1)
            ->and(TeamMember::query()->sole()->status)->toBe(TeamMemberStatus::Invited);
    });

    it('claims the seat when they sign in with that email', function () {
        Notification::fake();
        $owner = teamOwner();
        $this->actingAs($owner)->postJson('/api/v1/team/members', ['email' => 'budi@studio.test']);

        // The invite link is a convenience; Google is what proves who they
        // are, so the match is on the address.
        $budi = User::factory()->create(['email' => 'budi@studio.test']);
        TeamInvite::claim($budi);
        Workspace::forget();

        $seat = TeamMember::query()->sole();
        expect($seat->status)->toBe(TeamMemberStatus::Active)
            ->and($seat->member_id)->toBe($budi->id)
            ->and(Workspace::owner($budi)->id)->toBe($owner->id);
    });
});

describe('what a seat buys', function () {
    it('shows the owner’s Spaces, not the member’s own', function () {
        $owner = teamOwner();
        $member = seatedMember($owner);

        Space::factory()->for($owner)->create(['title' => 'Winter Noel']);

        $response = $this->actingAs($member)->getJson('/api/v1/spaces')->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.title'))->toBe('Winter Noel');
    });

    it('lets an editor change the owner’s Space', function () {
        $owner = teamOwner();
        $member = seatedMember($owner);
        $space = Space::factory()->for($owner)->create();

        $this->actingAs($member)
            ->patchJson("/api/v1/spaces/{$space->ulid}", ['title' => 'Renamed by Budi'])
            ->assertOk();

        expect($space->fresh()->title)->toBe('Renamed by Budi');
    });

    it('lets a viewer read and refuses to let them write', function () {
        $owner = teamOwner();
        $viewer = seatedMember($owner, 'viewer');
        $space = Space::factory()->for($owner)->create();

        $this->actingAs($viewer)->getJson("/api/v1/spaces/{$space->ulid}")->assertOk();

        $this->actingAs($viewer)
            ->patchJson("/api/v1/spaces/{$space->ulid}", ['title' => 'Nope'])
            ->assertForbidden();
    });

    it('keeps money, billing and the affiliate programme with the owner', function () {
        $owner = teamOwner();
        $member = seatedMember($owner);

        // A seat is for the work, not the till.
        $this->actingAs($member)->getJson('/api/v1/me/withdrawals')->assertForbidden();
        $this->actingAs($member)->getJson('/api/v1/me/subscription')->assertForbidden();
        $this->actingAs($member)->getJson('/api/v1/me/affiliate')->assertForbidden();
        $this->actingAs($member)->getJson('/api/v1/team/members')->assertForbidden();
    });

    it('reads the owner’s analytics on the owner’s plan', function () {
        $owner = teamOwner();
        $member = seatedMember($owner);

        // Team carries full_analytics; the member is on Free themselves.
        expect($this->actingAs($member)->getJson('/api/v1/me/analytics')->json('data.full'))
            ->toBeTrue();
    });

    it('ends access the moment they are removed', function () {
        $owner = teamOwner();
        $member = seatedMember($owner);
        Space::factory()->for($owner)->create();

        $seat = TeamMember::query()->sole();
        $this->actingAs($owner)->deleteJson("/api/v1/team/members/{$seat->ulid}")->assertNoContent();
        Workspace::forget();

        expect($this->actingAs($member)->getJson('/api/v1/spaces')->json('data'))->toHaveCount(0)
            // The row stays: who was on the team last quarter is answerable.
            ->and(TeamMember::query()->sole()->status)->toBe(TeamMemberStatus::Removed);
    });

    it('drops the member back to their own account when the plan lapses', function () {
        $owner = teamOwner();
        $member = seatedMember($owner);
        Space::factory()->for($owner)->create();

        // The Team subscription expires — nothing is deleted anywhere.
        Subscription::query()->update(['status' => 'expired']);
        Workspace::forget();

        expect(Workspace::owner($member)->id)->toBe($member->id)
            ->and($this->actingAs($member)->getJson('/api/v1/spaces')->json('data'))->toHaveCount(0)
            ->and(Space::query()->count())->toBe(1);
    });
});

describe('seats', function () {
    it('bills the days left when seats are added, and waits for the money', function () {
        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'snap-seats-1',
            ]),
        ]);
        $owner = teamOwner(seats: 3);

        $response = $this->actingAs($owner)
            ->postJson('/api/v1/me/subscription/seats', ['seats' => 4])
            ->assertCreated();

        // 20 of 30 days left on one extra seat at Rp44.000.
        expect($response->json('data.amount'))->toBe(29_334)
            ->and($response->json('data.seats'))->toBe(3)
            ->and($response->json('data.seats_pending'))->toBe(4);

        // Not yet: a seat nobody has paid for cannot be filled.
        expect(Workspace::seats($owner->fresh()))->toBe(3);
    });

    it('defers a reduction to the renewal rather than refunding', function () {
        $owner = teamOwner(seats: 3);

        $this->actingAs($owner)
            ->postJson('/api/v1/me/subscription/seats', ['seats' => 2])
            ->assertOk()
            ->assertJsonPath('data.seats', 3)
            ->assertJsonPath('data.seats_at_renewal', 2)
            ->assertJsonPath('data.invoice', null);

        // The seats are paid for until the period ends, so they still work.
        expect(Workspace::seats($owner->fresh()))->toBe(3);
    });

    it('will not sell fewer seats than there are people', function () {
        Notification::fake();
        $owner = teamOwner(seats: 3);
        seatedMember($owner);
        seatedMember($owner);

        // Three people on those three seats: the owner plus two.
        $this->actingAs($owner)
            ->postJson('/api/v1/me/subscription/seats', ['seats' => 2])
            ->assertUnprocessable()
            ->assertJsonPath('errors.seats.0', 'Remove somebody first — you have 3 people on your team.');
    });

    it('refuses extra seats on a plan that has no seats', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/me/subscription/checkout', [
                'plan' => 'premium',
                'period' => 'monthly',
                'seats' => 3,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.seats.0', 'Extra people are part of Team / Agency.');

        $this->actingAs($user)
            ->postJson('/api/v1/me/subscription/seats', ['seats' => 3])
            ->assertUnprocessable()
            ->assertJsonPath('errors.seats.0', 'Seats are part of the Team plan.');
    });
});
