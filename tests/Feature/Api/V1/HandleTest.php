<?php

use App\Models\Handle;
use App\Models\User;

describe('availability', function () {
    it('reports a free handle as available', function () {
        $this->getJson('/api/v1/handles/availability?name=rani')
            ->assertOk()
            ->assertJson(['name' => 'rani', 'available' => true]);
    });

    it('rejects names shorter than 3 characters', function () {
        $this->getJson('/api/v1/handles/availability?name=ab')
            ->assertOk()
            ->assertJson(['available' => false, 'reason' => 'too_short']);
    });

    it('reports taken and reserved handles with suggestions', function () {
        Handle::factory()->create(['name' => 'rani']);
        Handle::factory()->reserved()->create(['name' => 'admin']);

        $this->getJson('/api/v1/handles/availability?name=rani')
            ->assertOk()
            ->assertJson(['available' => false, 'reason' => 'taken'])
            ->assertJsonStructure(['suggestions']);

        $this->getJson('/api/v1/handles/availability?name=admin')
            ->assertOk()
            ->assertJson(['available' => false]);
    });

    it('normalizes input before checking', function () {
        $this->getJson('/api/v1/handles/availability?name=Ra%20Ni!')
            ->assertOk()
            ->assertJson(['name' => 'rani', 'available' => true]);
    });

    it('treats released handles past the grace window as available', function () {
        Handle::factory()->released(now()->subDays(31))->create(['name' => 'gone']);

        $this->getJson('/api/v1/handles/availability?name=gone')
            ->assertOk()
            ->assertJson(['available' => true]);
    });

    it('holds released handles within the grace window', function () {
        Handle::factory()->released(now()->subDays(5))->create(['name' => 'fresh']);

        $this->getJson('/api/v1/handles/availability?name=fresh')
            ->assertOk()
            ->assertJson(['available' => false]);
    });
});

describe('claim', function () {
    it('lets an authenticated user claim a free handle and completes onboarding', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/handles', ['name' => 'rani'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'rani');

        $user->refresh();
        expect($user->handle->name)->toBe('rani')
            ->and($user->onboarded_at)->not->toBeNull()
            ->and($user->profile)->not->toBeNull();
    });

    it('rejects guests', function () {
        $this->postJson('/api/v1/handles', ['name' => 'rani'])->assertUnauthorized();
    });

    it('rejects reserved and taken names', function () {
        Handle::factory()->reserved()->create(['name' => 'admin']);
        Handle::factory()->create(['name' => 'rani']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/handles', ['name' => 'admin'])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->postJson('/api/v1/handles', ['name' => 'rani'])
            ->assertUnprocessable();
    });

    it('rejects a second claim by the same user', function () {
        $user = User::factory()->create();
        Handle::factory()->for($user)->create(['name' => 'first']);

        $this->actingAs($user)
            ->postJson('/api/v1/handles', ['name' => 'second'])
            ->assertUnprocessable();
    });

    it('rejects invalid shapes', function (string $name) {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/handles', ['name' => $name])
            ->assertUnprocessable();
    })->with(['ab', 'UPPER', 'has space', 'emoji😀', str_repeat('a', 31)]);

    it('reassigns a released handle past its grace window', function () {
        Handle::factory()->released(now()->subDays(40))->create(['name' => 'gone']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/handles', ['name' => 'gone'])
            ->assertCreated();

        expect(Handle::query()->where('name', 'gone')->first()->user_id)
            ->toBe($user->id);
    });
});
