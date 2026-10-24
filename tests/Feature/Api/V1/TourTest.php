<?php

use App\Models\User;

it('starts a new account with the tour unseen', function () {
    $user = User::factory()->create()->refresh();

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertJsonPath('data.tour_seen_at', null);
});

it('marks the tour seen once and keeps the first date', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/me/tour')->assertNoContent();
    $first = $user->refresh()->tour_seen_at;
    expect($first)->not->toBeNull();

    $this->travel(1)->days();
    $this->actingAs($user)->postJson('/api/v1/me/tour')->assertNoContent();
    expect($user->refresh()->tour_seen_at->equalTo($first))->toBeTrue();
});

it('rejects guests', function () {
    $this->postJson('/api/v1/me/tour')->assertUnauthorized();
});
