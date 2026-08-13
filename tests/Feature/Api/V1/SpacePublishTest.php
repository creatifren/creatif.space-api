<?php

use App\Models\Space;
use App\Models\User;

it('publishes a draft and sets published_at', function () {
    $user = User::factory()->create();
    $space = Space::factory()->for($user)->create();

    $this->actingAs($user)->postJson("/api/v1/spaces/{$space->ulid}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    expect($space->refresh()->published_at)->not->toBeNull();
});

it('enforces the 3-active quota on publish, freed by archive', function () {
    $user = User::factory()->create();
    Space::factory()->for($user)->published()->count(3)->create();
    $draft = Space::factory()->for($user)->create();

    $this->actingAs($user)->postJson("/api/v1/spaces/{$draft->ulid}/publish")
        ->assertUnprocessable();

    $this->actingAs($user)->postJson('/api/v1/spaces/'.$user->spaces()->where('status', 'published')->first()->ulid.'/archive')
        ->assertOk();

    $this->actingAs($user)->postJson("/api/v1/spaces/{$draft->ulid}/publish")
        ->assertOk();
});

it('is idempotent for an already published space at the cap', function () {
    $user = User::factory()->create();
    Space::factory()->for($user)->published()->count(2)->create();
    $published = Space::factory()->for($user)->published()->create();

    $this->actingAs($user)->postJson("/api/v1/spaces/{$published->ulid}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');
});

it('unpublishes back to draft', function () {
    $user = User::factory()->create();
    $space = Space::factory()->for($user)->published()->create();

    $this->actingAs($user)->postJson("/api/v1/spaces/{$space->ulid}/unpublish")
        ->assertOk()
        ->assertJsonPath('data.status', 'draft');
});

it('reactivates a previously published space to published with the quota gate', function () {
    $user = User::factory()->create();
    $archived = Space::factory()->for($user)->archived()->create(['published_at' => now()->subWeek()]);
    Space::factory()->for($user)->published()->count(3)->create();

    $this->actingAs($user)->postJson("/api/v1/spaces/{$archived->ulid}/reactivate")
        ->assertUnprocessable();

    $user->spaces()->where('status', 'published')->first()
        ->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

    $this->actingAs($user)->postJson("/api/v1/spaces/{$archived->ulid}/reactivate")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');
});

it('reactivates a never-published space to draft without the gate', function () {
    $user = User::factory()->create();
    Space::factory()->for($user)->published()->count(3)->create();
    $archived = Space::factory()->for($user)->archived()->create(['published_at' => null]);

    $this->actingAs($user)->postJson("/api/v1/spaces/{$archived->ulid}/reactivate")
        ->assertOk()
        ->assertJsonPath('data.status', 'draft');
});

it('soft deletes and frees the total slot', function () {
    $user = User::factory()->create();
    Space::factory()->for($user)->count(10)->create();

    $this->actingAs($user)
        ->deleteJson('/api/v1/spaces/'.$user->spaces()->first()->ulid, ['revoke_access' => true])
        ->assertNoContent();

    expect($user->spaces()->count())->toBe(9)
        ->and(Space::withTrashed()->where('user_id', $user->id)->count())->toBe(10);

    $this->actingAs($user)
        ->postJson('/api/v1/spaces', ['title' => 'New', 'purpose' => 'portfolio', 'view_mode' => 'grid'])
        ->assertCreated();
});
