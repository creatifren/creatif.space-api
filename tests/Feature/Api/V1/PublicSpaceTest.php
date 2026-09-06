<?php

use App\Enums\UserStatus;
use App\Models\File;
use App\Models\Handle;
use App\Models\Space;
use App\Models\User;

function publishedSpaceFor(User $user, array $attributes = []): Space
{
    Handle::factory()->for($user)->create(['name' => 'rani']);

    return Space::factory()->for($user)->published()->create(
        array_merge(['slug' => 'winter-noel'], $attributes),
    );
}

describe('public space', function () {
    it('serves a published space with sections, dropping hidden and empty blocks', function () {
        $user = User::factory()->create();
        $space = publishedSpaceFor($user);
        $shown = File::factory()->for($user)->create(['name' => 'shown.jpg']);
        $hidden = File::factory()->for($user)->create(['name' => 'hidden.jpg']);

        $itemShown = $space->items()->create(['file_id' => $shown->id, 'sort_order' => 0, 'caption' => 'The cup']);
        $itemHidden = $space->items()->create(['file_id' => $hidden->id, 'sort_order' => 1]);

        $space->forceFill(['design' => array_merge(Space::emptyDesign(), [
            'sections' => [[
                'key' => 's1', 'num' => '01', 'title' => 'Hero',
                'blocks' => [
                    ['t' => 'text', 'key' => 'written'],
                    ['t' => 'text', 'key' => 'empty'],
                    ['t' => 'item', 'id' => $itemShown->ulid],
                    ['t' => 'item', 'id' => $itemHidden->ulid],
                ],
            ]],
            'texts' => [
                'written' => ['preset' => 'Title', 'scale' => 'M', 'color' => 'Ink', 'bg' => 'None', 'align' => 'Center', 'size' => 'Full width', 'text' => 'Hello'],
                'empty' => ['preset' => 'Body', 'scale' => 'M', 'color' => 'Ink', 'bg' => 'None', 'align' => 'Left', 'size' => 'Medium', 'text' => '   '],
            ],
            'items' => [
                $itemShown->ulid => ['size' => 'Large'],
                $itemHidden->ulid => ['hidden' => true],
            ],
        ])])->save();

        $response = $this->getJson('/api/v1/profiles/rani/spaces/winter-noel')->assertOk();

        $blocks = $response->json('data.sections.0.blocks');
        expect($blocks)->toHaveCount(2) // written text + shown; hidden never ships
            ->and($blocks[0]['type'])->toBe('text')
            ->and($blocks[1]['photo']['name'])->toBe('shown.jpg')
            ->and($blocks[1]['photo']['size'])->toBe('Large')
            ->and($blocks[1]['photo']['caption'])->toBe('The cup')
            ->and($blocks[1]['photo']['src'])->toBeString()->toContain($shown->path);

        expect($response->json('data.owner.handle'))->toBe('rani')
            ->and($response->json('data.layout'))->toBe('grid');
    });

    it('carries the owner profile appearance, and an empty object without one', function () {
        $user = User::factory()->create();
        publishedSpaceFor($user);
        $user->profile()->create(['appearance' => ['tone' => 'ink', 'accent' => 'clay']]);

        $this->getJson('/api/v1/profiles/rani/spaces/winter-noel')
            ->assertOk()
            ->assertJsonPath('data.appearance.tone', 'ink')
            ->assertJsonPath('data.appearance.accent', 'clay');

        $other = User::factory()->create();
        Handle::factory()->for($other)->create(['name' => 'budi']);
        Space::factory()->for($other)->published()->create(['slug' => 'no-profile']);

        $this->getJson('/api/v1/profiles/budi/spaces/no-profile')
            ->assertOk()
            ->assertJsonPath('data.appearance', []);
    });

    it('404s for drafts, archived, deleted, unknown, and suspended owners', function () {
        $user = User::factory()->create();
        Handle::factory()->for($user)->create(['name' => 'rani']);
        Space::factory()->for($user)->create(['slug' => 'draft-space']);
        Space::factory()->for($user)->archived()->create(['slug' => 'archived-space']);
        $deleted = Space::factory()->for($user)->published()->create(['slug' => 'deleted-space']);
        $deleted->delete();

        $this->getJson('/api/v1/profiles/rani/spaces/draft-space')->assertNotFound();
        $this->getJson('/api/v1/profiles/rani/spaces/archived-space')->assertNotFound();
        $this->getJson('/api/v1/profiles/rani/spaces/deleted-space')->assertNotFound();
        $this->getJson('/api/v1/profiles/rani/spaces/nope')->assertNotFound();
        $this->getJson('/api/v1/profiles/nobody/spaces/anything')->assertNotFound();

        $suspended = User::factory()->create(['status' => UserStatus::Suspended]);
        Handle::factory()->for($suspended)->create(['name' => 'gone']);
        Space::factory()->for($suspended)->published()->create(['slug' => 'was-live']);
        $this->getJson('/api/v1/profiles/gone/spaces/was-live')->assertNotFound();
    });

    it('serves private-but-published spaces (link-only, not blocked)', function () {
        publishedSpaceFor(User::factory()->create(), ['visibility' => 'private']);

        $this->getJson('/api/v1/profiles/rani/spaces/winter-noel')->assertOk();
    });
});

describe('password', function () {
    it('locks with 423 leaking nothing but the owner name', function () {
        publishedSpaceFor(User::factory()->create(['name' => 'Rani P.']), [
            'password_hash' => bcrypt('winter24'),
            'title' => 'Secret Shoot',
        ]);

        $this->getJson('/api/v1/profiles/rani/spaces/winter-noel')
            ->assertStatus(423)
            ->assertJsonPath('locked', true)
            ->assertJsonPath('owner_name', 'Rani P.')
            ->assertJsonMissingPath('data.title');
    });

    it('unlocks with the right password and honors the token', function () {
        publishedSpaceFor(User::factory()->create(), ['password_hash' => bcrypt('winter24')]);

        $this->postJson('/api/v1/profiles/rani/spaces/winter-noel/unlock', ['password' => 'wrong'])
            ->assertUnprocessable();

        $response = $this->postJson('/api/v1/profiles/rani/spaces/winter-noel/unlock', ['password' => 'winter24'])
            ->assertOk()
            ->assertJsonStructure(['token', 'data' => ['title']]);

        $token = $response->json('token');

        $this->getJson("/api/v1/profiles/rani/spaces/winter-noel?st={$token}")->assertOk();
        $this->getJson('/api/v1/profiles/rani/spaces/winter-noel?st=garbage')->assertStatus(423);
    });
});

describe('expiry', function () {
    it('serves 410 for expired spaces, before the password gate', function () {
        publishedSpaceFor(User::factory()->create(), [
            'expires_at' => now()->subDay(),
            'password_hash' => bcrypt('x1234'),
        ]);

        $this->getJson('/api/v1/profiles/rani/spaces/winter-noel')
            ->assertStatus(410)
            ->assertJsonPath('expired', true);

        $this->postJson('/api/v1/profiles/rani/spaces/winter-noel/unlock', ['password' => 'x1234'])
            ->assertStatus(410);
    });

    it('serves normally with a future expiry', function () {
        publishedSpaceFor(User::factory()->create(), ['expires_at' => now()->addWeek()]);

        $this->getJson('/api/v1/profiles/rani/spaces/winter-noel')->assertOk();
    });
});
