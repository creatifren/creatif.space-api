<?php

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

    expect(File::query()->count())->toBe(0);
});
