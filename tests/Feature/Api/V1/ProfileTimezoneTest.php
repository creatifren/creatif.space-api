<?php

use App\Models\User;
use App\Support\WeeklyDigest;

/*
 * Settings → Profile: the user's time zone (IANA), saved with the existing
 * PATCH /me/profile and read back on /me/profile and /me. It is the same
 * users.timezone the weekly digest uses; null means the app's zone.
 * An account field: open on every plan (no `spaces` gate).
 */

beforeEach(fn () => planWith('free'));

it('saves an IANA zone and reads it back on /me/profile and /me', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/v1/me/profile', ['timezone' => 'Asia/Jakarta'])
        ->assertOk()
        ->assertJsonPath('data.timezone', 'Asia/Jakarta');

    // Read back as the stored row (the in-memory $user is the one the test made).
    $fresh = $user->fresh();
    expect($fresh->timezone)->toBe('Asia/Jakarta');
    $this->actingAs($fresh)->getJson('/api/v1/me/profile')->assertJsonPath('data.timezone', 'Asia/Jakarta');
    $this->actingAs($fresh)->getJson('/api/v1/me')->assertJsonPath('data.timezone', 'Asia/Jakarta');
});

it('reads null until one is set, and null clears it again', function () {
    $user = User::factory()->create(['timezone' => null]);
    $this->actingAs($user)->getJson('/api/v1/me/profile')->assertOk()->assertJsonPath('data.timezone', null);

    $user->forceFill(['timezone' => 'America/New_York'])->save();
    $this->actingAs($user->fresh())->patchJson('/api/v1/me/profile', ['timezone' => null])->assertOk()->assertJsonPath('data.timezone', null);
    expect($user->fresh()->timezone)->toBeNull();
});

it('refuses what is not an IANA zone, and changes nothing', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);

    foreach (['Mars/Olympus', 'GMT+7 Jakarta', str_repeat('A', 80)] as $bad) {
        $this->actingAs($user)->patchJson('/api/v1/me/profile', ['timezone' => $bad])
            ->assertStatus(422)
            ->assertJsonValidationErrors('timezone');
    }
    expect($user->fresh()->timezone)->toBe('Asia/Jakarta');
});

it('is an account field: Free can set it without the spaces feature', function () {
    planWith('free', ['spaces' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/v1/me/profile', ['timezone' => 'Asia/Makassar', 'name' => 'Rizal'])
        ->assertOk()
        ->assertJsonPath('data.timezone', 'Asia/Makassar');
});

it('is the zone the weekly digest uses', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->patchJson('/api/v1/me/profile', ['timezone' => 'Asia/Jayapura'])->assertOk();

    expect(WeeklyDigest::timezone($user->fresh()))->toBe('Asia/Jayapura');
});
