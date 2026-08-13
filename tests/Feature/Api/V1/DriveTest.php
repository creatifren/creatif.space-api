<?php

use App\Jobs\SyncDriveFile;
use App\Models\DriveAccount;
use App\Models\DriveFile;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\Queue;

describe('drive accounts', function () {
    it('lists own accounts with file counts', function () {
        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create();
        DriveFile::factory()->for($account, 'account')->count(3)->create();
        DriveAccount::factory()->create(); // someone else's

        $this->actingAs($user)
            ->getJson('/api/v1/drive-accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $account->ulid)
            ->assertJsonPath('data.0.files_count', 3)
            ->assertJsonMissingPath('data.0.access_token');
    });

    it('queues one sync job per picked file id, deduplicated', function () {
        Queue::fake();
        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson("/api/v1/drive-accounts/{$account->ulid}/pick", [
                'file_ids' => ['abc', 'def', 'abc'],
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 2);

        Queue::assertPushed(SyncDriveFile::class, 2);
    });

    it('forbids picking into another user\'s account', function () {
        Queue::fake();
        $other = DriveAccount::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/drive-accounts/{$other->ulid}/pick", [
                'file_ids' => ['abc'],
            ])
            ->assertForbidden();

        Queue::assertNothingPushed();
    });

    it('disconnects an account and cascades its files', function () {
        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create();
        DriveFile::factory()->for($account, 'account')->count(2)->create();

        $this->actingAs($user)
            ->deleteJson("/api/v1/drive-accounts/{$account->ulid}")
            ->assertNoContent();

        expect(DriveAccount::query()->count())->toBe(0)
            ->and(DriveFile::query()->count())->toBe(0);
    });
});

describe('file browser', function () {
    it('lists only own, untrashed files, folders first', function () {
        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create();
        DriveFile::factory()->for($account, 'account')->create(['name' => 'photo.jpg']);
        DriveFile::factory()->for($account, 'account')->folder()->create(['name' => 'Shoots']);
        DriveFile::factory()->for($account, 'account')->create(['trashed_at' => now()]);
        DriveFile::factory()->create(); // someone else's

        $response = $this->actingAs($user)->getJson('/api/v1/files')->assertOk();

        expect($response->json('data'))->toHaveCount(2)
            ->and($response->json('data.0.is_folder'))->toBeTrue();
    });

    it('searches by name and filters by type', function () {
        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create();
        DriveFile::factory()->for($account, 'account')->create(['name' => 'winter-noel-01.jpg']);
        DriveFile::factory()->for($account, 'account')->create([
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

    it('traverses folders via ?folder=', function () {
        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create();
        DriveFile::factory()->for($account, 'account')->create([
            'name' => 'inside.jpg', 'parent_folder_id' => 'folder-x',
        ]);
        DriveFile::factory()->for($account, 'account')->create(['name' => 'outside.jpg']);

        $this->actingAs($user)
            ->getJson('/api/v1/files?folder=folder-x')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'inside.jpg');
    });

    it('shows a file detail with exif and hides other users\' files', function () {
        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create();
        $file = DriveFile::factory()->for($account, 'account')->create([
            'exif' => ['cameraMake' => 'Fujifilm', 'aperture' => 2.8],
        ]);
        $foreign = DriveFile::factory()->create();

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
        $this->getJson('/api/v1/drive-accounts')->assertUnauthorized();
    });
});

describe('sync jobs', function () {
    it('upserts metadata and flags access loss', function () {
        $account = DriveAccount::factory()->create();

        Http::fake([
            'https://www.googleapis.com/drive/v3/files/file-1*' => Http::response([
                'id' => 'file-1',
                'name' => 'noel-01.jpg',
                'mimeType' => 'image/jpeg',
                'size' => '2048000',
                'md5Checksum' => 'hash-a',
                'thumbnailLink' => 'https://lh3.googleusercontent.com/t/1',
                'parents' => ['root-folder'],
                'trashed' => false,
            ]),
            'https://www.googleapis.com/drive/v3/files/file-gone*' => Http::response([], 404),
        ]);

        (new SyncDriveFile($account, 'file-1'))->handle(app(GoogleDriveService::class));

        $file = DriveFile::query()->where('provider_file_id', 'file-1')->first();
        expect($file)->not->toBeNull()
            ->and($file->name)->toBe('noel-01.jpg')
            ->and($file->version_hash)->toBe('hash-a')
            ->and($file->access_lost_at)->toBeNull();

        // Existing file loses access → flagged, not deleted.
        $existing = DriveFile::factory()->for($account, 'account')->create([
            'provider_file_id' => 'file-gone',
        ]);
        (new SyncDriveFile($account, 'file-gone'))->handle(app(GoogleDriveService::class));

        expect($existing->refresh()->access_lost_at)->not->toBeNull();
    });

    it('detects a version change on re-sync', function () {
        $account = DriveAccount::factory()->create();
        DriveFile::factory()->for($account, 'account')->create([
            'provider_file_id' => 'file-2',
            'version_hash' => 'old-hash',
        ]);

        Http::fake([
            'https://www.googleapis.com/drive/v3/files/file-2*' => Http::response([
                'id' => 'file-2',
                'name' => 'noel-02.jpg',
                'mimeType' => 'image/jpeg',
                'md5Checksum' => 'new-hash',
                'trashed' => false,
            ]),
        ]);

        (new SyncDriveFile($account, 'file-2'))->handle(app(GoogleDriveService::class));

        expect(DriveFile::query()->where('provider_file_id', 'file-2')->value('version_hash'))
            ->toBe('new-hash');
    });
});
