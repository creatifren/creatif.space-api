<?php

use App\Enums\ApprovalCancelReason;
use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\Client;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\Space;
use App\Models\SpaceItem;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/** A ready file with real bytes behind it. */
function storedFile(User $user, string $body = 'v1-bytes'): File
{
    $file = File::factory()->for($user)->create([
        'size_bytes' => strlen($body),
    ]);
    Storage::disk('s3')->put($file->path, $body);

    return $file;
}

describe('listing', function () {
    it('is empty for a file that was never replaced', function () {
        $user = User::factory()->create();
        $file = File::factory()->for($user)->create();

        $this->actingAs($user)
            ->getJson("/api/v1/files/{$file->ulid}/versions")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('refuses somebody else’s file', function () {
        $file = File::factory()->for(User::factory())->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/files/{$file->ulid}/versions")
            ->assertNotFound();
    });
});

describe('replacing the bytes', function () {
    it('keeps the file’s own path so existing links still resolve', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = storedFile($user);
        $originalPath = $file->path;

        $upload = $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/versions/presign", [
                'size_bytes' => 8,
            ])
            ->assertCreated()
            ->json('data.upload_id');

        // The browser's PUT.
        Storage::disk('s3')->put(
            FileVersion::keyFor($file, $upload, $file->name),
            'v2-bytes',
        );

        $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/versions/complete", [
                'upload_id' => $upload,
            ])
            ->assertOk();

        expect($file->refresh()->path)->toBe($originalPath)
            ->and(Storage::disk('s3')->get($originalPath))->toBe('v2-bytes');
    });

    it('files the old bytes away rather than overwriting them', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = storedFile($user);

        $upload = $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/versions/presign", ['size_bytes' => 8])
            ->json('data.upload_id');
        Storage::disk('s3')->put(FileVersion::keyFor($file, $upload, $file->name), 'v2-bytes');
        $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/versions/complete", [
                'upload_id' => $upload,
                'note' => 'Reshot the cover',
            ])
            ->assertOk();

        $version = $file->versions()->sole();
        expect($version->number)->toBe(1)
            ->and($version->note)->toBe('Reshot the cover')
            ->and(Storage::disk('s3')->get($version->path))->toBe('v1-bytes');
    });

    it('charges the history against the storage quota', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = storedFile($user); // 8 bytes

        $upload = $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/versions/presign", ['size_bytes' => 8])
            ->json('data.upload_id');
        Storage::disk('s3')->put(FileVersion::keyFor($file, $upload, $file->name), 'v2-bytes');
        $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/versions/complete", ['upload_id' => $upload]);

        // Both sets of bytes are really in the bucket, so both are billed.
        expect(\App\Support\PlanQuota::storageUsed($user))->toBe(16);
    });

    it('refuses a file whose own upload never landed', function () {
        $user = User::factory()->create();
        $file = File::factory()->for($user)->pending()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/versions/presign", ['size_bytes' => 10])
            ->assertStatus(409);
    });

    it('says so when the PUT never arrived', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = storedFile($user);

        $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/versions/complete", [
                'upload_id' => (string) str()->ulid(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'The upload never arrived — try again.');
    });
});

describe('approvals', function () {
    it('sends a client’s decision back to pending when the bytes change', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = storedFile($user);

        $space = Space::factory()->for($user)->create();
        $item = SpaceItem::factory()->for($space)->for($file)->create();
        $approval = Approval::factory()
            ->for($item, 'spaceItem')
            ->for(Client::factory(), 'client')
            ->create(['status' => ApprovalStatus::Approved, 'approved_at' => now()]);

        $upload = $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/versions/presign", ['size_bytes' => 8])
            ->json('data.upload_id');
        Storage::disk('s3')->put(FileVersion::keyFor($file, $upload, $file->name), 'v2-bytes');

        $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/versions/complete", ['upload_id' => $upload])
            ->assertOk()
            ->assertJsonPath('data.approvals_voided', 1);

        $approval->refresh();
        expect($approval->status)->toBe(ApprovalStatus::Pending)
            ->and($approval->cancelled_reason)->toBe(ApprovalCancelReason::FileReplaced)
            ->and($approval->approved_at)->toBeNull();
    });
});

describe('restoring', function () {
    it('puts the old bytes back and keeps the replaced ones', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = storedFile($user);

        $upload = $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/versions/presign", ['size_bytes' => 8])
            ->json('data.upload_id');
        Storage::disk('s3')->put(FileVersion::keyFor($file, $upload, $file->name), 'v2-bytes');
        $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/versions/complete", ['upload_id' => $upload]);

        $v1 = $file->versions()->sole();

        $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/versions/{$v1->ulid}/restore")
            ->assertOk()
            ->assertJsonPath('data.restored_from', 1);

        // v1 is serving again, and v2 is now the history — restoring is
        // itself undoable.
        expect(Storage::disk('s3')->get($file->refresh()->path))->toBe('v1-bytes')
            ->and($file->versions()->count())->toBe(2)
            ->and(Storage::disk('s3')->get($file->versions()->first()->path))->toBe('v2-bytes');
    });

    it('refuses a version belonging to another file', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $mine = storedFile($user);
        $theirs = storedFile($user, 'other');
        $stray = $theirs->versions()->create([
            'number' => 1,
            'path' => 'v/stray/'.str()->ulid().'.jpg',
            'size_bytes' => 5,
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/files/{$mine->ulid}/versions/{$stray->ulid}/restore")
            ->assertNotFound();
    });
});

describe('deleting a version', function () {
    it('frees the bytes and leaves the file serving', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = storedFile($user);

        $upload = $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/versions/presign", ['size_bytes' => 8])
            ->json('data.upload_id');
        Storage::disk('s3')->put(FileVersion::keyFor($file, $upload, $file->name), 'v2-bytes');
        $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/versions/complete", ['upload_id' => $upload]);

        $v1 = $file->versions()->sole();
        $oldPath = $v1->path;

        $this->actingAs($user)
            ->deleteJson("/api/v1/files/{$file->ulid}/versions/{$v1->ulid}")
            ->assertNoContent();

        Storage::disk('s3')->assertMissing($oldPath);
        expect($file->versions()->count())->toBe(0)
            ->and(Storage::disk('s3')->get($file->refresh()->path))->toBe('v2-bytes')
            ->and(\App\Support\PlanQuota::storageUsed($user))->toBe(8);
    });
});
