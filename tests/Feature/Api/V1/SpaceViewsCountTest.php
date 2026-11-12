<?php

use App\Enums\SpaceEventType;
use App\Models\Space;
use App\Models\SpaceDailyStat;
use App\Models\SpaceEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

// The views count on each Space card (GET /api/v1/spaces). Spaces is behind
// Pro on Free (PlansV2Test); these tests are about the count, so Free has it.
beforeEach(function () {
    planWith('free', ['spaces' => true]);
    Carbon::setTestNow('2026-11-12 10:00:00');
});

afterEach(fn () => Carbon::setTestNow());

it('adds the closed days from the daily stats to today\'s raw views, never twice', function () {
    $user = User::factory()->create();
    $space = Space::factory()->for($user)->create();

    SpaceDailyStat::factory()->for($space)->create(['date' => '2026-11-10', 'views' => 7]);
    SpaceDailyStat::factory()->for($space)->create(['date' => '2026-11-11', 'views' => 5]);
    // Today's partial row (the job rewrites it tomorrow): not counted, the raw events are.
    SpaceDailyStat::factory()->for($space)->create(['date' => '2026-11-12', 'views' => 2]);

    SpaceEvent::factory()->for($space)->count(2)->on(now()->subHour())->create();
    SpaceEvent::factory()->for($space)->on(now()->subDay())->create(); // yesterday: already in the stats
    SpaceEvent::factory()->for($space)->crawler()->on(now()->subMinutes(5))->create(); // crawlers never count
    SpaceEvent::factory()->for($space)->on(now()->subMinutes(5))->create(['type' => SpaceEventType::Download]); // not a view

    $this->actingAs($user)
        ->getJson('/api/v1/spaces')
        ->assertOk()
        ->assertJsonPath('data.0.views_count', 7 + 5 + 2);
});

it('is 0 for a Space nobody has opened, and counts per Space', function () {
    $user = User::factory()->create();
    $quiet = Space::factory()->for($user)->create(['updated_at' => now()->subDay()]);
    $busy = Space::factory()->for($user)->create(['updated_at' => now()]);
    SpaceEvent::factory()->for($busy)->count(3)->create();

    $rows = collect($this->actingAs($user)->getJson('/api/v1/spaces')->assertOk()->json('data'))->keyBy('id');

    expect($rows[$busy->ulid]['views_count'])->toBe(3)
        ->and($rows[$quiet->ulid]['views_count'])->toBe(0);
});

it('carries no visitor data on the card', function () {
    $user = User::factory()->create();
    $space = Space::factory()->for($user)->create();
    SpaceEvent::factory()->for($space)->from('instagram.com')->create();

    $row = $this->actingAs($user)->getJson('/api/v1/spaces')->assertOk()->json('data.0');

    expect($row)->not->toHaveKeys(['visitor_hash', 'referrer_host', 'events', 'daily_stats']);
});

it('never counts another account\'s Spaces', function () {
    $owner = User::factory()->create();
    Space::factory()->for($owner)->create();
    $other = Space::factory()->create();
    SpaceEvent::factory()->for($other)->count(4)->create();

    $this->actingAs($owner)
        ->getJson('/api/v1/spaces')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.views_count', 0);
});
