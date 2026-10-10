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

describe('change', function () {
    it('moves the user to a new name and holds the old one in grace', function () {
        $user = User::factory()->create();
        Handle::factory()->for($user)->create(['name' => 'first', 'updated_at' => now()->subDays(8)]);

        $this->actingAs($user)
            ->patchJson('/api/v1/me/handle', ['name' => 'second'])
            ->assertOk()
            ->assertJsonPath('data.name', 'second');

        $old = Handle::query()->where('name', 'first')->first();
        expect($user->fresh()->handle->name)->toBe('second')
            ->and($old->user_id)->toBeNull()
            ->and($old->previous_user_id)->toBe($user->id)
            ->and(Handle::isAvailable('first'))->toBeFalse();
    });

    it('refuses a second change inside seven days', function () {
        $user = User::factory()->create();
        Handle::factory()->for($user)->create(['name' => 'first', 'updated_at' => now()->subDays(6)]);

        $this->actingAs($user)
            ->patchJson('/api/v1/me/handle', ['name' => 'second'])
            ->assertUnprocessable();

        expect($user->fresh()->handle->name)->toBe('first');
    });

    it('lets the previous owner take back a name still in grace, but nobody else', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Handle::factory()->for($user)->create(['name' => 'second', 'updated_at' => now()->subDays(8)]);
        Handle::factory()->released(now()->subDays(8))->create(['name' => 'first', 'previous_user_id' => $user->id]);
        Handle::factory()->for($other)->create(['name' => 'third', 'updated_at' => now()->subDays(8)]);

        $this->actingAs($other)
            ->patchJson('/api/v1/me/handle', ['name' => 'first'])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->patchJson('/api/v1/me/handle', ['name' => 'first'])
            ->assertOk();

        expect($user->fresh()->handle->name)->toBe('first');
    });

    it('rejects taken names, the same name, and users without a handle', function () {
        $user = User::factory()->create();
        Handle::factory()->for($user)->create(['name' => 'first', 'updated_at' => now()->subDays(8)]);
        Handle::factory()->create(['name' => 'taken']);

        $this->actingAs($user)->patchJson('/api/v1/me/handle', ['name' => 'taken'])->assertUnprocessable();
        $this->actingAs($user)->patchJson('/api/v1/me/handle', ['name' => 'first'])->assertUnprocessable();
        $this->actingAs(User::factory()->create())
            ->patchJson('/api/v1/me/handle', ['name' => 'fresh'])
            ->assertUnprocessable();
    });
});
