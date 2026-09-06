<?php

use App\Enums\ActivityAction;
use App\Models\Activity;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;

it('creates a folder, files land in it, and the listing scopes to it', function () {
    $user = User::factory()->create();

    $folderId = $this->actingAs($user)
        ->postJson('/api/v1/folders', ['name' => '  Shoots  '])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Shoots')
        ->assertJsonPath('data.files_count', 0)
        ->json('data.id');

    $this->actingAs($user)
        ->postJson('/api/v1/files/presign', [
            'name' => 'noel-01.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1_000,
            'folder_id' => $folderId,
        ])
        ->assertCreated();

    expect(File::query()->sole()->folder_id)->toBe(Folder::query()->sole()->id);

    // Inside the folder: the file, no sub-folders, one crumb.
    $this->actingAs($user)
        ->getJson("/api/v1/files?folder_id={$folderId}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(0, 'meta.folders')
        ->assertJsonPath('meta.breadcrumb.0.name', 'Shoots');

    // Root: no files, the folder card carries the count.
    $this->actingAs($user)
        ->getJson('/api/v1/files?folder_id=')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.folders.0.id', $folderId)
        ->assertJsonPath('meta.folders.0.files_count', 1)
        ->assertJsonPath('meta.breadcrumb', []);

    // No key: global, as every other caller expects.
    $this->actingAs($user)
        ->getJson('/api/v1/files')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('meta.folders');
});

it('nests and walks the breadcrumb up', function () {
    $user = User::factory()->create();
    $parent = Folder::create(['user_id' => $user->id, 'name' => 'Clients']);

    $childId = $this->actingAs($user)
        ->postJson('/api/v1/folders', ['name' => 'Noel', 'parent_id' => $parent->ulid])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($user)
        ->getJson("/api/v1/files?folder_id={$childId}")
        ->assertOk()
        ->assertJsonPath('meta.breadcrumb.0.name', 'Clients')
        ->assertJsonPath('meta.breadcrumb.1.name', 'Noel');
});

it('renames a folder', function () {
    $folder = Folder::create(['user_id' => User::factory()->create()->id, 'name' => 'Shoots']);

    $this->actingAs($folder->user)
        ->patchJson("/api/v1/folders/{$folder->ulid}", ['name' => '  Winter Noel  '])
        ->assertOk()
        ->assertJsonPath('data.name', 'Winter Noel');
});

it('moves a folder, and back to the root', function () {
    $user = User::factory()->create();
    $parent = Folder::create(['user_id' => $user->id, 'name' => 'Clients']);
    $child = Folder::create(['user_id' => $user->id, 'name' => 'Noel']);

    $this->actingAs($user)
        ->patchJson("/api/v1/folders/{$child->ulid}", ['parent_id' => $parent->ulid])
        ->assertOk();

    $this->actingAs($user)
        ->getJson("/api/v1/files?folder_id={$child->ulid}")
        ->assertJsonPath('meta.breadcrumb.0.name', 'Clients')
        ->assertJsonPath('meta.breadcrumb.1.name', 'Noel');

    // Explicit null is the root; absent would have left it where it was.
    $this->actingAs($user)
        ->patchJson("/api/v1/folders/{$child->ulid}", ['parent_id' => null])
        ->assertOk();

    expect($child->fresh()->parent_id)->toBeNull();
});

it('refuses to move a folder inside itself or its own child', function () {
    $user = User::factory()->create();
    $parent = Folder::create(['user_id' => $user->id, 'name' => 'Clients']);
    $child = Folder::create(['user_id' => $user->id, 'parent_id' => $parent->id, 'name' => 'Noel']);

    // Either would orphan the subtree: the rows survive, nothing reaches them.
    $this->actingAs($user)
        ->patchJson("/api/v1/folders/{$parent->ulid}", ['parent_id' => $child->ulid])
        ->assertUnprocessable();

    $this->actingAs($user)
        ->patchJson("/api/v1/folders/{$parent->ulid}", ['parent_id' => $parent->ulid])
        ->assertUnprocessable();

    expect($parent->fresh()->parent_id)->toBeNull();
});

it('deletes the folder and drops its files to the root, keeping them', function () {
    $user = User::factory()->create();
    $folder = Folder::create(['user_id' => $user->id, 'name' => 'Shoots']);
    $file = File::factory()->for($user)->create(['folder_id' => $folder->id]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/folders/{$folder->ulid}")
        ->assertNoContent();

    // A folder is how the library is seen. Removing the view must never
    // remove the work.
    expect(Folder::query()->count())->toBe(0)
        ->and($file->fresh()->folder_id)->toBeNull()
        ->and($file->fresh()->exists)->toBeTrue();

    $this->actingAs($user)
        ->getJson('/api/v1/files?folder_id=')
        ->assertJsonCount(1, 'data');
});

describe('moving files', function () {
    it('moves files in and back out to the root', function () {
        $user = User::factory()->create();
        $folder = Folder::create(['user_id' => $user->id, 'name' => 'Shoots']);
        $files = File::factory()->count(2)->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/v1/files/move', [
                'file_ids' => $files->pluck('ulid')->all(),
                'folder_id' => $folder->ulid,
            ])
            ->assertNoContent();

        $this->actingAs($user)
            ->getJson("/api/v1/files?folder_id={$folder->ulid}")
            ->assertJsonCount(2, 'data');

        $this->actingAs($user)
            ->postJson('/api/v1/files/move', [
                'file_ids' => [$files->first()->ulid],
                'folder_id' => null,
            ])
            ->assertNoContent();

        expect($files->first()->fresh()->folder_id)->toBeNull();
    });

    it('leaves the bytes alone — only the view changes', function () {
        $user = User::factory()->create();
        $folder = Folder::create(['user_id' => $user->id, 'name' => 'Shoots']);
        $file = File::factory()->for($user)->create();
        $path = $file->path;

        $this->actingAs($user)
            ->postJson('/api/v1/files/move', [
                'file_ids' => [$file->ulid],
                'folder_id' => $folder->ulid,
            ])
            ->assertNoContent();

        // The object key is fixed at upload; a folder is presentation.
        expect($file->fresh()->path)->toBe($path);
    });

    it('moves none of them when one is not mine', function () {
        $user = User::factory()->create();
        $mine = File::factory()->for($user)->create();
        $theirs = File::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/files/move', [
                'file_ids' => [$mine->ulid, $theirs->ulid],
                'folder_id' => null,
            ])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->postJson('/api/v1/files/move', [
                'file_ids' => [$mine->ulid],
                'folder_id' => Folder::create([
                    'user_id' => User::factory()->create()->id,
                    'name' => 'Theirs',
                ])->ulid,
            ])
            ->assertNotFound();

        expect($mine->fresh()->folder_id)->toBeNull();
    });

    it('writes one ledger line, whether it moved one file or many', function () {
        $user = User::factory()->create();
        $folder = Folder::create(['user_id' => $user->id, 'name' => 'Shoots']);
        $files = File::factory()->count(3)->for($user)->create();

        $this->actingAs($user)->postJson('/api/v1/files/move', [
            'file_ids' => $files->pluck('ulid')->all(),
            'folder_id' => $folder->ulid,
        ]);

        $line = Activity::query()->where('action', ActivityAction::Move)->sole();
        expect($line->summary)->toBe('Moved 3 files to Shoots');

        $this->actingAs($user)->postJson('/api/v1/files/move', [
            'file_ids' => [$files->first()->ulid],
            'folder_id' => null,
        ]);

        expect(Activity::query()->where('action', ActivityAction::Move)->latest('id')->first()->summary)
            ->toBe("Moved {$files->first()->name} to the main folder");
    });
});

it("refuses another user's folder", function () {
    $foreign = Folder::create(['user_id' => User::factory()->create()->id, 'name' => 'Theirs']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/files/presign', [
            'name' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1_000,
            'folder_id' => $foreign->ulid,
        ])
        ->assertNotFound();

    $this->actingAs($user)
        ->getJson("/api/v1/files?folder_id={$foreign->ulid}")
        ->assertNotFound();

    // Renaming and deleting are 404 too — not 403, which would confirm it
    // exists.
    $this->actingAs($user)
        ->patchJson("/api/v1/folders/{$foreign->ulid}", ['name' => 'Mine now'])
        ->assertNotFound();

    $this->actingAs($user)
        ->deleteJson("/api/v1/folders/{$foreign->ulid}")
        ->assertNotFound();

    expect(File::query()->count())->toBe(0)
        ->and($foreign->fresh()->name)->toBe('Theirs');
});
