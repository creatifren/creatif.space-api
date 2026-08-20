<?php

use App\Jobs\ImportDriveFile;
use App\Models\DriveAccount;
use App\Models\File;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

describe('drive accounts', function () {
    it('lists own accounts and never leaks the token', function () {
        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create();
        DriveAccount::factory()->create(); // someone else's

        $this->actingAs($user)
            ->getJson('/api/v1/drive-accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $account->ulid)
            ->assertJsonMissingPath('data.0.access_token');
    });

    it('hands the Picker a short-lived token', function () {
        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create();

        $this->actingAs($user)
            ->getJson("/api/v1/drive-accounts/{$account->ulid}/picker-token")
            ->assertOk()
            ->assertJsonPath('data.access_token', 'test-access-token');
    });

    it('disconnects an account, and the imported copies stay', function () {
        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create();
        File::factory()->for($user)->driveImport()->count(2)->create();

        $this->actingAs($user)
            ->deleteJson("/api/v1/drive-accounts/{$account->ulid}")
            ->assertNoContent();

        // The copies are ours, not references into the Drive.
        expect(DriveAccount::query()->count())->toBe(0)
            ->and(File::query()->count())->toBe(2);
    });

    it('rejects guests', function () {
        $this->getJson('/api/v1/drive-accounts')->assertUnauthorized();
    });
});

describe('the picker', function () {
    it('queues one import job per picked file id, deduplicated', function () {
        Queue::fake();
        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson("/api/v1/drive-accounts/{$account->ulid}/pick", [
                'file_ids' => ['abc', 'def', 'abc'],
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 2);

        Queue::assertPushed(ImportDriveFile::class, 2);
    });

    it('caps a pick at 50 ids — each one is a full byte copy', function () {
        Queue::fake();
        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson("/api/v1/drive-accounts/{$account->ulid}/pick", [
                'file_ids' => array_map(fn ($i) => "file-{$i}", range(1, 51)),
            ])
            ->assertUnprocessable();

        Queue::assertNothingPushed();
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
});

describe('the import job', function () {
    it('copies the bytes to our storage and marks the file ready', function () {
        Storage::fake('s3');
        $account = DriveAccount::factory()->create();

        Http::fake([
            'https://www.googleapis.com/drive/v3/files/file-1*' => Http::sequence()
                ->push([
                    'id' => 'file-1',
                    'name' => 'noel-01.jpg',
                    'mimeType' => 'image/jpeg',
                    'size' => '11',
                    'md5Checksum' => 'hash-a',
                    'imageMediaMetadata' => ['width' => 4000, 'height' => 3000],
                ])
                ->push('jpeg--bytes'),
        ]);

        (new ImportDriveFile($account, 'file-1'))->handle(app(GoogleDriveService::class));

        $file = File::query()->sole();
        expect($file->status)->toBe(File::STATUS_READY)
            ->and($file->user_id)->toBe($account->user_id)
            ->and($file->name)->toBe('noel-01.jpg')
            ->and($file->mime_type)->toBe('image/jpeg')
            ->and($file->size_bytes)->toBe(strlen('jpeg--bytes'))
            ->and($file->checksum)->toBe('hash-a')
            ->and($file->width)->toBe(4000)
            ->and($file->source)->toBe('drive_import')
            ->and($file->source_meta['provider_file_id'])->toBe('file-1')
            ->and($file->source_meta['drive_email'])->toBe($account->email);

        Storage::disk('s3')->assertExists($file->path);
    });

    it('creates nothing when the file is gone or access was lost', function () {
        Storage::fake('s3');
        $account = DriveAccount::factory()->create();

        Http::fake([
            'https://www.googleapis.com/drive/v3/files/file-gone*' => Http::response([], 404),
        ]);

        (new ImportDriveFile($account, 'file-gone'))->handle(app(GoogleDriveService::class));

        // No row was promised for a file that cannot be imported.
        expect(File::query()->count())->toBe(0);
    });

    it('skips Google-native docs and folders — they have no bytes to copy', function () {
        Storage::fake('s3');
        $account = DriveAccount::factory()->create();

        Http::fake([
            'https://www.googleapis.com/drive/v3/files/folder-1*' => Http::response([
                'id' => 'folder-1',
                'name' => 'Shoots',
                'mimeType' => 'application/vnd.google-apps.folder',
            ]),
        ]);

        (new ImportDriveFile($account, 'folder-1'))->handle(app(GoogleDriveService::class));

        expect(File::query()->count())->toBe(0);
    });

    it('refuses the copy when storage is full', function () {
        Storage::fake('s3');
        $account = DriveAccount::factory()->create();

        // Free plan: 2 GB. This one file eats the whole quota.
        File::factory()->for($account->user)->create([
            'size_bytes' => 2 * 1024 * 1024 * 1024,
        ]);

        Http::fake([
            'https://www.googleapis.com/drive/v3/files/file-2*' => Http::response([
                'id' => 'file-2',
                'name' => 'noel-02.jpg',
                'mimeType' => 'image/jpeg',
                'size' => '2048000',
            ]),
        ]);

        (new ImportDriveFile($account, 'file-2'))->handle(app(GoogleDriveService::class));

        expect(File::query()->count())->toBe(1); // only the pre-existing one
    });
});
