<?php

use App\Models\File;
use App\Models\Space;
use App\Models\SpaceItem;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

describe('presign', function () {
    it('reserves quota with a pending row and hands back a PUT url', function () {
        // No Storage::fake here: presigning is offline math on the real s3
        // driver, and the fake's local adapter cannot sign anything.
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/files/presign', [
                'name' => 'noel-01.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 2_048_000,
            ])
            ->assertCreated();

        expect($response->json('data.upload_url'))->toBeString()->not->toBeEmpty()
            ->and($response->json('data.expires_at'))->not->toBeNull();

        $file = File::query()->sole();
        expect($file->status)->toBe(File::STATUS_PENDING)
            ->and($file->ulid)->toBe($response->json('data.id'))
            ->and($file->user_id)->toBe($user->id)
            ->and($file->source)->toBe('upload')
            ->and($file->size_bytes)->toBe(2_048_000);
    });

    it('refuses a mime type that is not on the allowlist', function () {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/files/presign', [
                'name' => 'payload.php',
                'mime_type' => 'application/x-php',
                'size_bytes' => 1_000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mime_type');

        expect(File::query()->count())->toBe(0);
    });

    it('refuses a file over the per-file ceiling', function () {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/files/presign', [
                'name' => 'raw-footage.mp4',
                'mime_type' => 'video/mp4',
                'size_bytes' => 501 * 1024 * 1024,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('size_bytes');
    });

    it('refuses when the storage quota is full — pending rows count too', function () {
        $user = User::factory()->create();

        // Free plan: 2 GB. A pending upload already claims all of it.
        File::factory()->for($user)->pending()->create([
            'size_bytes' => 2 * 1024 * 1024 * 1024,
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/files/presign', [
                'name' => 'one-more.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1_000_000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('size_bytes');
    });
});

describe('complete', function () {
    it('believes R2, not the browser: no object means no ready file', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = File::factory()->for($user)->pending()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/complete")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        expect($file->fresh()->status)->toBe(File::STATUS_PENDING);
    });

    it('marks the file ready with the real size winning over the declared one', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = File::factory()->for($user)->pending()->create(['size_bytes' => 999]);

        Storage::disk('s3')->put($file->path, 'the-actual-bytes');

        $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/complete", ['width' => 4000, 'height' => 3000])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.width', 4000);

        expect($file->fresh()->size_bytes)->toBe(strlen('the-actual-bytes'));
    });

    it('answers 409 the second time — an upload completes once', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = File::factory()->for($user)->pending()->create();
        Storage::disk('s3')->put($file->path, 'bytes');

        $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/complete")
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/complete")
            ->assertStatus(409);
    });
});

describe('delete', function () {
    it('refuses with 409 while a Space still shows the file', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = File::factory()->for($user)->create();
        SpaceItem::factory()
            ->for(Space::factory()->for($user))
            ->create(['file_id' => $file->id]);

        $this->actingAs($user)
            ->deleteJson("/api/v1/files/{$file->ulid}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This file is used in a Space — remove it there first.');

        expect(File::query()->count())->toBe(1);
    });

    it('deletes object and row once nothing references it', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = File::factory()->for($user)->create();
        Storage::disk('s3')->put($file->path, 'bytes');

        $this->actingAs($user)
            ->deleteJson("/api/v1/files/{$file->ulid}")
            ->assertNoContent();

        expect(File::query()->count())->toBe(0);
        Storage::disk('s3')->assertMissing($file->path);
    });
});

describe('the library', function () {
    it('lists own files without the failed ones, with storage meta', function () {
        $user = User::factory()->create();
        File::factory()->for($user)->create(['name' => 'keeper.jpg', 'size_bytes' => 1_000]);
        File::factory()->for($user)->pending()->create(['size_bytes' => 500]);
        File::factory()->for($user)->create(['status' => File::STATUS_FAILED]);
        File::factory()->create(); // someone else's

        $response = $this->actingAs($user)->getJson('/api/v1/files')->assertOk();

        expect($response->json('data'))->toHaveCount(2)
            ->and($response->json('meta.storage_used'))->toBe(1_500)
            ->and($response->json('meta.storage_limit'))->toBe(2 * 1024 * 1024 * 1024);
    });

    it('searches by name and filters by type', function () {
        $user = User::factory()->create();
        File::factory()->for($user)->create(['name' => 'winter-noel-01.jpg']);
        File::factory()->for($user)->create([
            'name' => 'brief.pdf', 'mime_type' => 'application/pdf',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/files?search=winter')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'winter-noel-01.jpg');

        $this->actingAs($user)
            ->getJson('/api/v1/files?type=pdf')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'brief.pdf');
    });

    it('shows a file detail with exif and hides other users\' files', function () {
        $user = User::factory()->create();
        $file = File::factory()->for($user)->create([
            'exif' => ['cameraMake' => 'Fujifilm', 'aperture' => 2.8],
        ]);
        $foreign = File::factory()->create();

        $this->actingAs($user)
            ->getJson("/api/v1/files/{$file->ulid}")
            ->assertOk()
            ->assertJsonPath('data.exif.cameraMake', 'Fujifilm');

        $this->actingAs($user)
            ->getJson("/api/v1/files/{$foreign->ulid}")
            ->assertNotFound();
    });

    it('rejects guests', function () {
        $this->getJson('/api/v1/files')->assertUnauthorized();
        $this->postJson('/api/v1/files/presign', [])->assertUnauthorized();
    });
});
