<?php

use App\Models\User;

it('returns the authenticated user with their public id', function () {
    $user = User::factory()->create()->refresh();

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->ulid)
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonMissingPath('data.google_id');
});

it('rejects guests', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});
