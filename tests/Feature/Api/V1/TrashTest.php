<?php

use App\Jobs\PurgeTrashedFiles;
use App\Models\File;
use App\Models\Space;
use App\Models\User;
use App\Support\PlanQuota;
use Illuminate\Support\Facades\Storage;

/** A file with bytes actually in the fake bucket. */
function storedFileFor(User $user, int $size = 1_000): File
{
    $file = File::factory()->for($user)->create(['size_bytes' => $size]);
    Storage::disk('s3')->put($file->path, 'bytes');

    return $file;
}

describe('the trash', function () {
    it('lists trashed files soonest-to-go first, and only your own', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $soon = storedFileFor($user);
        $later = storedFileFor($user);
        $theirs = storedFileFor(User::factory()->create());

        $this->actingAs($user)->deleteJson("/api/v1/files/{$later->ulid}")->assertNoContent();
        $this->actingAs($user)->deleteJson("/api/v1/files/{$soon->ulid}")->assertNoContent();
        $soon->fresh()->forceFill(['purge_at' => now()->addDay()])->save();
        $theirs->delete();

        $response = $this->actingAs($user)->getJson('/api/v1/files/trash')->assertOk();

        expect($response->json('data'))->toHaveCount(2)
            ->and($response->json('data.0.id'))->toBe($soon->ulid)
            ->and($response->json('data.0.purge_at'))->not->toBeNull();
    });

    it('restores a file and clears its purge date', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = storedFileFor($user);
        $this->actingAs($user)->deleteJson("/api/v1/files/{$file->ulid}")->assertNoContent();

        $this->actingAs($user)
            ->postJson("/api/v1/files/trash/{$file->ulid}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $file->ulid);

        /* A file restored and deleted again gets a fresh 30 days, not the
           remains of the old window. */
        expect($file->fresh()->deleted_at)->toBeNull()
            ->and($file->fresh()->purge_at)->toBeNull();
    });

    it('force-deletes one file, object and versions with it', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = storedFileFor($user);
        $version = $file->versions()->create([
            'number' => 1,
            'path' => 'v/old/'.str()->ulid().'.jpg',
            'size_bytes' => 400,
        ]);
        Storage::disk('s3')->put($version->path, 'old bytes');
        $this->actingAs($user)->deleteJson("/api/v1/files/{$file->ulid}")->assertNoContent();

        $this->actingAs($user)
            ->deleteJson("/api/v1/files/trash/{$file->ulid}")
            ->assertNoContent();

        expect(File::withTrashed()->count())->toBe(0);
        Storage::disk('s3')->assertMissing($file->path);
        Storage::disk('s3')->assertMissing($version->path);
    });

    it('empties the whole bin and leaves live files alone', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $gone = storedFileFor($user);
        $kept = storedFileFor($user);
        $this->actingAs($user)->deleteJson("/api/v1/files/{$gone->ulid}")->assertNoContent();

        $this->actingAs($user)->deleteJson('/api/v1/files/trash')->assertNoContent();

        expect(File::withTrashed()->pluck('id')->all())->toBe([$kept->id]);
        Storage::disk('s3')->assertExists($kept->path);
    });

    it('refuses to trash a file a Space still shows', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = storedFileFor($user);
        $space = Space::factory()->for($user)->create();
        $space->items()->create(['file_id' => $file->id, 'sort_order' => 0]);

        $this->actingAs($user)
            ->deleteJson("/api/v1/files/{$file->ulid}")
            ->assertStatus(409);

        expect(File::withTrashed()->whereNotNull('deleted_at')->count())->toBe(0);
    });

    it('keeps charging for trashed bytes', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = storedFileFor($user, 1_000);
        $this->actingAs($user)->deleteJson("/api/v1/files/{$file->ulid}")->assertNoContent();

        /* The object is still in the bucket, so it is still billed. Without
           this the soft delete would have handed everyone free storage. */
        expect(PlanQuota::storageUsed($user->fresh()))->toBe(1_000);

        $this->actingAs($user)->getJson('/api/v1/me/storage')
            ->assertOk()
            ->assertJsonPath('data.used', 1_000)
            ->assertJsonPath('data.reclaimable.trash', 1_000);
    });

    it('purges only what is past its date', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $due = storedFileFor($user);
        $waiting = storedFileFor($user);
        $this->actingAs($user)->deleteJson("/api/v1/files/{$due->ulid}")->assertNoContent();
        $this->actingAs($user)->deleteJson("/api/v1/files/{$waiting->ulid}")->assertNoContent();
        $due->fresh()->forceFill(['purge_at' => now()->subMinute()])->save();

        (new PurgeTrashedFiles)->handle();

        expect(File::withTrashed()->pluck('id')->all())->toBe([$waiting->id]);
        Storage::disk('s3')->assertMissing($due->path);
    });

    it('is signed-in only', function () {
        $this->getJson('/api/v1/files/trash')->assertUnauthorized();
    });
});
