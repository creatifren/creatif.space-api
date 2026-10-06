<?php

use App\Models\SocialPost;
use App\Models\User;

it('starts empty and keeps the sections it was not sent', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/me/social-settings')
        ->assertOk()
        ->assertJsonPath('data.timetable', [])
        ->assertJsonPath('data.views', []);

    $this->actingAs($user)->patchJson('/api/v1/me/social-settings', [
        'timetable' => [['day' => 0, 'time' => '19:00'], ['day' => 4, 'time' => '19:00']],
    ])->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/me/social-settings', [
        'labels' => [['name' => 'Promo', 'color' => 'clay']],
    ])->assertOk()
        ->assertJsonCount(2, 'data.timetable')
        ->assertJsonPath('data.labels.0.name', 'Promo');
});

it('refuses a time that is not HH:MM and a day outside the week', function () {
    $this->actingAs(User::factory()->create())
        ->patchJson('/api/v1/me/social-settings', ['timetable' => [['day' => 7, 'time' => '7pm']]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['timetable.0.day', 'timetable.0.time']);
});

it('carries a renamed label onto the posts that wear it', function () {
    $user = User::factory()->create();
    $post = SocialPost::factory()->for($user)->create(['labels' => ['Promo', 'Launch']]);
    $other = SocialPost::factory()->create(['labels' => ['Promo']]); // someone else's

    $this->actingAs($user)->patchJson('/api/v1/me/social-settings', [
        'labels' => [['name' => 'Sale', 'color' => 'clay'], ['name' => 'Launch', 'color' => 'sea']],
        'renamed' => ['Promo' => 'Sale'],
    ])->assertOk()->assertJsonMissingPath('data.renamed');

    expect($post->fresh()->labels)->toBe(['Sale', 'Launch'])
        ->and($other->fresh()->labels)->toBe(['Promo']);
});

it('labels any post, even a published one, without touching the provider', function () {
    $user = User::factory()->create();
    $post = SocialPost::factory()->for($user)->published()->create();

    $this->actingAs($user)
        ->patchJson("/api/v1/social-posts/{$post->ulid}", ['labels' => ['Promo']])
        ->assertOk()
        ->assertJsonPath('data.labels', ['Promo']);
});
