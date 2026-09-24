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

it('says which ways in the account has, without the secrets', function () {
    $user = User::factory()->create(['google_id' => null, 'password' => 'correct-horse']);

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertJsonPath('data.has_google', false)
        ->assertJsonPath('data.has_password', true)
        ->assertJsonMissingPath('data.password');
});

it('rejects guests', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});
