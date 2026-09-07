<?php

use App\Models\File;
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
        $fileA = File::factory()->for($user)->create();
        $fileB = File::factory()->for($user)->create();

        // First save: two files.
        $response = $this->actingAs($user)->patchJson("/api/v1/spaces/{$space->ulid}", [
            'items' => [
                ['file_id' => $fileA->ulid, 'section' => 'Hero', 'sort_order' => 0, 'caption' => 'First'],
                ['file_id' => $fileB->ulid, 'section' => 'Hero', 'sort_order' => 1],
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
                ['id' => $itemIds[0], 'file_id' => $fileA->ulid, 'section' => 'Hero', 'sort_order' => 0, 'caption' => 'First'],
            ],
        ])->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.design.sections.0.blocks.0.t', 'text')
            ->assertJsonPath('data.design.texts.t1.text', 'Five looks.');

        expect($space->items()->count())->toBe(1);
    });

    it('rejects items referencing another users file', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->create();
        $foreign = File::factory()->create();

        $this->actingAs($user)->patchJson("/api/v1/spaces/{$space->ulid}", [
            'items' => [['file_id' => $foreign->ulid]],
        ])->assertUnprocessable();
    });

    it('rejects items referencing a file that is not ready yet', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->create();
        $pending = File::factory()->for($user)->pending()->create();

        $this->actingAs($user)->patchJson("/api/v1/spaces/{$space->ulid}", [
            'items' => [['file_id' => $pending->ulid]],
        ])->assertUnprocessable()
            ->assertJsonPath('errors.items.0', 'One or more files do not exist in your library.');
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

    it('rejects a slug already used by another of the users spaces', function () {
        $user = User::factory()->create();
        Space::factory()->for($user)->create(['slug' => 'taken']);
        $space = Space::factory()->for($user)->create();

        $this->actingAs($user)->patchJson("/api/v1/spaces/{$space->ulid}", ['slug' => 'taken'])
            ->assertUnprocessable();
    });

    it('refuses revoke_access rather than accepting it and doing nothing', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->create();

        // The old behaviour: 204, and the caller believes Drive sharing was
        // withdrawn. Nothing had been withdrawn — nothing was ever granted.
        $this->actingAs($user)
            ->deleteJson("/api/v1/spaces/{$space->ulid}", ['revoke_access' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('revoke_access');

        expect($space->fresh())->not->toBeNull();
    });

    it('still deletes when revoke_access is absent or false', function () {
        $user = User::factory()->create();
        $a = Space::factory()->for($user)->create();
        $b = Space::factory()->for($user)->create();

        $this->actingAs($user)->deleteJson("/api/v1/spaces/{$a->ulid}")->assertNoContent();
        $this->actingAs($user)
            ->deleteJson("/api/v1/spaces/{$b->ulid}", ['revoke_access' => false])
            ->assertNoContent();

        // Soft deleted: "the link dies; history stays", so the row survives
        // and only the trashed scope can still see it.
        expect($a->fresh()->trashed())->toBeTrue()
            ->and($b->fresh()->trashed())->toBeTrue();
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
        $file = File::factory()->for($user)->create();
        $space = Space::factory()->for($user)->published()->passworded()->create(['title' => 'Noel', 'slug' => 'noel']);
        $item = $space->items()->create(['file_id' => $file->id, 'sort_order' => 0]);
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

describe('the default layout for a new Space', function () {
    it('takes the account default when the caller names none', function () {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->patchJson('/api/v1/me/profile', ['default_view_mode' => 'grid'])
            ->assertOk()
            ->assertJsonPath('data.default_view_mode', 'grid');

        $this->actingAs($user)
            ->postJson('/api/v1/spaces', ['title' => 'Winter Noel', 'purpose' => 'portfolio'])
            ->assertCreated()
            ->assertJsonPath('data.view_mode', 'grid');
    });

    it('still lets the modal ask, and the answer wins', function () {
        $user = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/v1/me/profile', ['default_view_mode' => 'grid']);

        // The setting is for people who answer the same way every time,
        // not a replacement for the question.
        $this->actingAs($user)
            ->postJson('/api/v1/spaces', [
                'title' => 'Board one',
                'purpose' => 'portfolio',
                'view_mode' => 'board',
            ])
            ->assertCreated()
            ->assertJsonPath('data.view_mode', 'board');
    });

    it('falls back to editorial for an account with no profile yet', function () {
        // A Space can be made before the profile screen is ever opened.
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/spaces', ['title' => 'First', 'purpose' => 'portfolio'])
            ->assertCreated()
            ->assertJsonPath('data.view_mode', 'editorial');
    });
});

describe('adding files from the Files screen', function () {
    it('appends without touching what is already there', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->create();
        $kept = File::factory()->for($user)->create();
        $space->items()->create(['file_id' => $kept->id, 'sort_order' => 0]);
        $added = File::factory()->for($user)->create();

        /* The reason this is not PATCH with an items array: that one
           reconciles, so a caller who did not know about $kept would
           delete it. */
        $this->actingAs($user)
            ->postJson("/api/v1/spaces/{$space->ulid}/items", [
                'file_ids' => [$added->ulid],
            ])
            ->assertOk()
            ->assertJsonPath('data.added', 1)
            ->assertJsonPath('data.total', 2);

        expect($space->items()->pluck('file_id')->all())
            ->toContain($kept->id)
            ->toContain($added->id);
    });

    it('skips a file the Space already holds rather than duplicating it', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->create();
        $file = File::factory()->for($user)->create();
        $space->items()->create(['file_id' => $file->id, 'sort_order' => 0]);

        // The caller asked for it to be in the Space, and it is.
        $this->actingAs($user)
            ->postJson("/api/v1/spaces/{$space->ulid}/items", [
                'file_ids' => [$file->ulid, $file->ulid],
            ])
            ->assertOk()
            ->assertJsonPath('data.added', 0)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.total', 1);
    });

    it('rejects a file that is not yours, and adds nothing', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->create();
        $mine = File::factory()->for($user)->create();
        $theirs = File::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/spaces/{$space->ulid}/items", [
                'file_ids' => [$mine->ulid, $theirs->ulid],
            ])
            ->assertUnprocessable();

        expect($space->items()->count())->toBe(0);
    });

    it('is a 404 for somebody elses Space', function () {
        $space = Space::factory()->for(User::factory()->create())->create();
        $stranger = User::factory()->create();
        $file = File::factory()->for($stranger)->create();

        $this->actingAs($stranger)
            ->postJson("/api/v1/spaces/{$space->ulid}/items", [
                'file_ids' => [$file->ulid],
            ])
            ->assertNotFound();
    });
});
