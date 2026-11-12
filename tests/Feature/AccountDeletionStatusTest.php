<?php

use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\AccountDeletionCancelled;
use App\Support\AccountDeletion;
use App\Support\OpenApi;
use App\Support\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * GET /me/deletion (status) and POST /me/deletion/cancel during the 30-day
 * grace period — what the app's "Your account will be deleted on …, keep
 * it?" screen is built on. Both documented in openapi.json.
 */

beforeEach(function () {
    Workspace::forget();
    Carbon::setTestNow(Carbon::parse('2026-11-12 09:00:00', 'UTC'));
    Notification::fake();
    Http::fake();
});

afterEach(fn () => Carbon::setTestNow());

function dsPending(): User
{
    $user = User::factory()->create();
    AccountDeletion::request($user);

    return $user;
}

it('reads active for an account nobody asked to delete', function () {
    $this->actingAs(User::factory()->create())->getJson('/api/v1/me/deletion')
        ->assertOk()
        ->assertExactJson(['data' => [
            'status' => 'active', 'requested_at' => null, 'purge_at' => null,
            'grace_days' => 30, 'days_left' => null, 'can_cancel' => false,
        ]]);
});

it('reads pending with the dates and days left, and is reachable while everything else is 423', function () {
    $user = dsPending();

    $this->actingAs($user)->getJson('/api/v1/me/deletion')
        ->assertOk()
        ->assertExactJson(['data' => [
            'status' => 'pending_deletion',
            'requested_at' => '2026-11-12T09:00:00+00:00',
            'purge_at' => '2026-12-12T09:00:00+00:00',
            'grace_days' => 30, 'days_left' => 30, 'can_cancel' => true,
        ]]);
    $this->actingAs($user)->getJson('/api/v1/credits')->assertStatus(423);

    $this->travelTo(Carbon::parse('2026-12-11 21:00:00', 'UTC'));
    $this->actingAs($user)->getJson('/api/v1/me/deletion')->assertJsonPath('data.days_left', 1);
});

it('cancels during the grace period, then reads active; a second cancel is 409 not_pending', function () {
    $user = dsPending();
    $this->travel(20)->days();

    $this->actingAs($user)->postJson('/api/v1/me/deletion/cancel')
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.purge_at', null)
        ->assertJsonPath('data.can_cancel', false);

    expect($user->fresh()->status)->toBe(UserStatus::Active)
        ->and($user->fresh()->deletion_requested_at)->toBeNull();
    Notification::assertSentTo($user, AccountDeletionCancelled::class);

    $this->actingAs($user->fresh())->getJson('/api/v1/me/deletion')->assertJsonPath('data.status', 'active');
    $this->actingAs($user->fresh())->getJson('/api/v1/credits')->assertOk();
    $this->actingAs($user->fresh())->postJson('/api/v1/me/deletion/cancel')
        ->assertStatus(409)->assertJsonPath('reason', 'not_pending');
});

it('refuses to cancel once purge_at has passed (the purge has not run yet)', function () {
    $user = dsPending();
    $this->travel(31)->days();

    $this->actingAs($user)->getJson('/api/v1/me/deletion')
        ->assertJsonPath('data.days_left', 0)->assertJsonPath('data.can_cancel', false);
    $this->actingAs($user)->postJson('/api/v1/me/deletion/cancel')
        ->assertStatus(409)->assertJsonPath('reason', 'grace_ended');
    expect($user->fresh()->status)->toBe(UserStatus::PendingDeletion);
});

it('is the session only: an API key never reaches it', function () {
    $user = User::factory()->create();
    $key = $this->actingAs($user, 'web')->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'scopes' => ['read']])->json('data.key');
    app('auth')->forgetGuards();

    bearer($this, $key)->getJson('/api/v1/me/deletion')->assertUnauthorized();
    bearer($this, $key)->postJson('/api/v1/me/deletion/cancel')->assertUnauthorized();
});

it('documents both in openapi.json, with the response fields', function () {
    $doc = OpenApi::document();

    expect($doc['paths']['/me/deletion']['get']['description'])->toContain('days_left')->toContain('can_cancel')
        ->and($doc['paths']['/me/deletion/cancel']['post']['description'])->toContain('grace_ended')
        ->and(array_values(array_diff(array_keys(OpenApi::descriptions()), array_map(fn ($r) => $r->getName(), OpenApi::routes()))))->toBe([]);
});
