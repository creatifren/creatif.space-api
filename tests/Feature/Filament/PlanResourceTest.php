<?php

use App\Enums\PlanKey;
use App\Enums\UserStatus;
use App\Models\Plan;
use App\Models\User;

/**
 * The plans list once died with "Object of class App\Enums\PlanKey could not
 * be converted to string": `key` is both the route key and an enum-cast
 * column, so `getRouteKey()` handed a PlanKey object to the URL generator
 * while building each row's Edit link. One row was enough to take the page
 * down. These render the real page rather than asserting on the override, so
 * the test still fails if the cast, the route key, or Filament's link
 * building changes underneath it.
 */

/**
 * Staff, since canAccessPanel() admits nobody else.
 *
 * Re-read rather than returned straight from the factory: the factory never
 * sets `password` (creators sign in with Google), and under
 * Model::shouldBeStrict the session guard's read of that attribute throws on
 * an instance where it was never loaded.
 */
function admin(): User
{
    $staff = User::factory()->create([
        'is_staff' => true,
        'status' => UserStatus::Active,
    ]);

    return User::findOrFail($staff->id);
}

it('renders the plans list without stringifying the enum route key', function () {
    $this->actingAs(admin())
        ->get('/admin/plans')
        ->assertOk()
        ->assertSee('Premium');
});

it('links each row to an edit page keyed by the enum value', function () {
    $this->actingAs(admin())
        ->get('/admin/plans')
        ->assertOk()
        ->assertSee('/admin/plans/premium/edit');
});

it('opens a plan edit page by its string key', function () {
    $this->actingAs(admin())
        ->get('/admin/plans/premium/edit')
        ->assertOk();
});

it('returns the backing string from getRouteKey, not the enum case', function () {
    $plan = Plan::where('key', 'premium')->firstOrFail();

    expect($plan->getRouteKey())->toBe('premium')->toBeString()
        // The attribute itself must stay an enum — the fix is at the route
        // boundary, not a walk-back of the cast.
        ->and($plan->key)->toBeInstanceOf(PlanKey::class);
});
