<?php

use App\Models\DriveAccount;
use App\Models\DriveFile;
use App\Models\Space;
use App\Models\User;

describe('create', function () {
    it('creates a draft private space with a slugified address', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/spaces', [
                'title' => 'Winter Noel — Product Shoot',
                'purpose' => 'portfolio',
                'view_mode' => 'editorial',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.view_mode', 'editorial')
            ->assertJsonPath('data.slug', 'winter-noel-product-shoot')
            ->assertJsonPath('data.approval_enabled', false);
    });

    it('sets the approval flag for approval purpose and dedups slugs', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/spaces', [
            'title' => 'Wedding', 'purpose' => 'approval', 'view_mode' => 'grid',
        ])->assertCreated()->assertJsonPath('data.approval_enabled', true);

        $this->actingAs($user)->postJson('/api/v1/spaces', [
            'title' => 'Wedding', 'purpose' => 'portfolio', 'view_mode' => 'grid',
        ])->assertCreated()->assertJsonPath('data.slug', 'wedding-2');
    });

    it('rejects guests', function () {
        $this->postJson('/api/v1/spaces', [])->assertUnauthorized();
        $this->getJson('/api/v1/spaces')->assertUnauthorized();
    });

    it('rejects an invalid view mode', function () {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/spaces', ['title' => 'X', 'purpose' => 'portfolio', 'view_mode' => 'mosaic'])
            ->assertUnprocessable();
    });

    it('enforces the 10-space total quota, freed by delete', function () {
        $user = User::factory()->create();
        Space::factory()->count(10)->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/v1/spaces', ['title' => 'One more', 'purpose' => 'portfolio', 'view_mode' => 'grid'])
            ->assertUnprocessable();

        $user->spaces()->first()->delete();

        $this->actingAs($user)
            ->postJson('/api/v1/spaces', ['title' => 'One more', 'purpose' => 'portfolio', 'view_mode' => 'grid'])
            ->assertCreated();
    });
});

describe('list', function () {
    it('filters by tab, excluding archived everywhere else', function () {
        $user = User::factory()->create();
        Space::factory()->for($user)->create(['visibility' => 'private']);
        Space::factory()->for($user)->published()->create(['visibility' => 'public']);
        Space::factory()->for($user)->create(['approval_enabled' => true]);
        Space::factory()->for($user)->archived()->create();

        $this->actingAs($user)->getJson('/api/v1/spaces')
            ->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($user)->getJson('/api/v1/spaces?tab=private')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($user)->getJson('/api/v1/spaces?tab=public')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($user)->getJson('/api/v1/spaces?tab=approval')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($user)->getJson('/api/v1/spaces?tab=archived')
            ->assertOk()->assertJsonCount(1, 'data');
    });

    it('reports quota meta and hides other users spaces', function () {
        $user = User::factory()->create();
        Space::factory()->for($user)->published()->create();
        Space::factory()->for($user)->create();
        Space::factory()->create(); // someone else's

        $this->actingAs($user)->getJson('/api/v1/spaces')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.quota.active_used', 1)
            ->assertJsonPath('meta.quota.active_limit', 3)
            ->assertJsonPath('meta.quota.total_used', 2)
            ->assertJsonPath('meta.quota.total_limit', 10);
    });
});

describe('update', function () {
    it('round-trips design with interleaved text and item refs plus items sync', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->create();
        $account = DriveAccount::factory()->for($user)->create();
        $fileA = DriveFile::factory()->for($account, 'account')->create();
        $fileB = DriveFile::factory()->for($account, 'account')->create();

        // First save: two files.
        $response = $this->actingAs($user)->patchJson("/api/v1/spaces/{$space->ulid}", [
            'items' => [
                ['drive_file_id' => $fileA->ulid, 'section' => 'Hero', 'sort_order' => 0, 'caption' => 'First'],
                ['drive_file_id' => $fileB->ulid, 'section' => 'Hero', 'sort_order' => 1],
            ],
        ])->assertOk();

        $itemIds = collect($response->json('data.items'))->pluck('id');
        expect($itemIds)->toHaveCount(2);

        // Second save: design referencing the items + a text block, drop fileB.
        $design = [
            'fit' => 'cover',
            'align' => 'Center',
            'labels' => ['name' => true, 'tags' => false],
            'sections' => [[
                'key' => 's1', 'num' => '01', 'title' => 'Hero',
                'blocks' => [
                    ['t' => 'text', 'key' => 't1'],
                    ['t' => 'item', 'id' => $itemIds[0]],
                ],
            ]],
            'texts' => ['t1' => ['preset' => 'Title', 'scale' => 'M', 'color' => 'Ink', 'bg' => 'None', 'align' => 'Center', 'size' => 'Full width', 'text' => 'Five looks.']],
            'items' => [$itemIds[0] => ['size' => 'Large', 'alt' => 'A cup', 'hidden' => false, 'plan_type' => 'Feed']],
        ];

        $this->actingAs($user)->patchJson("/api/v1/spaces/{$space->ulid}", [
            'design' => $design,
            'items' => [
                ['id' => $itemIds[0], 'drive_file_id' => $fileA->ulid, 'section' => 'Hero', 'sort_order' => 0, 'caption' => 'First'],
            ],
        ])->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.design.sections.0.blocks.0.t', 'text')
            ->assertJsonPath('data.design.texts.t1.text', 'Five looks.');

        expect($space->items()->count())->toBe(1);
    });

    it('rejects items referencing another users drive file', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->create();
        $foreign = DriveFile::factory()->create();

        $this->actingAs($user)->patchJson("/api/v1/spaces/{$space->ulid}", [
            'items' => [['drive_file_id' => $foreign->ulid]],
        ])->assertUnprocessable();
    });

    it('stores password write-only and clears it with null', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->create();

        $this->actingAs($user)->patchJson("/api/v1/spaces/{$space->ulid}", ['password' => 'winter24'])
            ->assertOk()
            ->assertJsonPath('data.has_password', true)
            ->assertJsonMissingPath('data.password_hash');

        $this->actingAs($user)->patchJson("/api/v1/spaces/{$space->ulid}", ['password' => null])
            ->assertOk()
            ->assertJsonPath('data.has_password', false);
    });

    it('turns approval off when selling turns on', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->create(['approval_enabled' => true]);

        $this->actingAs($user)->patchJson("/api/v1/spaces/{$space->ulid}", ['selling_enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.selling_enabled', true)
            ->assertJsonPath('data.approval_enabled', false);
    });

    it('rejects a slug already used by another of the users spaces', function () {
        $user = User::factory()->create();
        Space::factory()->for($user)->create(['slug' => 'taken']);
        $space = Space::factory()->for($user)->create();

        $this->actingAs($user)->patchJson("/api/v1/spaces/{$space->ulid}", ['slug' => 'taken'])
            ->assertUnprocessable();
    });

    it('404s for foreign spaces on every endpoint', function () {
        $foreign = Space::factory()->create();
        $me = User::factory()->create();

        $this->actingAs($me)->getJson("/api/v1/spaces/{$foreign->ulid}")->assertNotFound();
        $this->actingAs($me)->patchJson("/api/v1/spaces/{$foreign->ulid}", [])->assertNotFound();
        $this->actingAs($me)->postJson("/api/v1/spaces/{$foreign->ulid}/publish")->assertNotFound();
        $this->actingAs($me)->deleteJson("/api/v1/spaces/{$foreign->ulid}")->assertNotFound();
    });
});

describe('duplicate', function () {
    it('copies items and remaps design refs with a fresh slug', function () {
        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create();
        $file = DriveFile::factory()->for($account, 'account')->create();
        $space = Space::factory()->for($user)->published()->passworded()->create(['title' => 'Noel', 'slug' => 'noel']);
        $item = $space->items()->create(['drive_file_id' => $file->id, 'sort_order' => 0]);
        $space->forceFill(['design' => array_merge(Space::emptyDesign(), [
            'sections' => [['key' => 's1', 'blocks' => [['t' => 'item', 'id' => $item->ulid]]]],
            'items' => [$item->ulid => ['size' => 'Large']],
        ])])->save();

        $response = $this->actingAs($user)->postJson("/api/v1/spaces/{$space->ulid}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.title', 'Noel (copy)')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.has_password', false)
            ->assertJsonCount(1, 'data.items');

        $newItemId = $response->json('data.items.0.id');
        expect($newItemId)->not->toBe($item->ulid)
            ->and($response->json('data.design.sections.0.blocks.0.id'))->toBe($newItemId)
            ->and($response->json("data.design.items.{$newItemId}.size"))->toBe('Large');
    });
});
