<?php

use App\Enums\ApprovalCancelReason;
use App\Enums\ApprovalStatus;
use App\Enums\DriveAccountStatus;
use App\Jobs\RecheckDriveFiles;
use App\Jobs\SyncDriveFile;
use App\Models\Approval;
use App\Models\DriveAccount;
use App\Models\DriveFile;
use App\Models\Space;
use App\Models\SpaceItem;
use App\Models\User;
use App\Notifications\ApprovalsVoided;
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * The sweep that makes "approvals auto-cancel when a file changes" true. It
 * only selects; SyncDriveFile does the work, so most of these assert which
 * files get picked and which are deliberately left alone.
 */
describe('drive re-check sweep', function () {
    it('queues a re-sync for a stale file', function () {
        Queue::fake();
        $account = DriveAccount::factory()->create([
            'status' => DriveAccountStatus::Connected,
        ]);
        DriveFile::factory()->for($account, 'account')->create([
            'last_synced_at' => now()->subDay(),
        ]);

        (new RecheckDriveFiles)->handle();

        Queue::assertPushed(SyncDriveFile::class, 1);
    });

    it('leaves freshly synced files alone', function () {
        Queue::fake();
        $account = DriveAccount::factory()->create([
            'status' => DriveAccountStatus::Connected,
        ]);
        DriveFile::factory()->for($account, 'account')->create([
            'last_synced_at' => now()->subHour(),
        ]);

        (new RecheckDriveFiles)->handle();

        Queue::assertNothingPushed();
    });

    it('treats a never-synced file as the stalest of all', function () {
        Queue::fake();
        $account = DriveAccount::factory()->create([
            'status' => DriveAccountStatus::Connected,
        ]);
        DriveFile::factory()->for($account, 'account')->create([
            'last_synced_at' => null,
        ]);

        (new RecheckDriveFiles)->handle();

        Queue::assertPushed(SyncDriveFile::class, 1);
    });

    it('skips folders, which would fan out into a full traversal', function () {
        Queue::fake();
        $account = DriveAccount::factory()->create([
            'status' => DriveAccountStatus::Connected,
        ]);
        DriveFile::factory()->for($account, 'account')->folder()->create([
            'last_synced_at' => now()->subDay(),
        ]);

        (new RecheckDriveFiles)->handle();

        Queue::assertNothingPushed();
    });

    it('skips trashed files', function () {
        Queue::fake();
        $account = DriveAccount::factory()->create([
            'status' => DriveAccountStatus::Connected,
        ]);
        DriveFile::factory()->for($account, 'account')->create([
            'last_synced_at' => now()->subDay(),
            'trashed_at' => now()->subWeek(),
        ]);

        (new RecheckDriveFiles)->handle();

        Queue::assertNothingPushed();
    });

    it('skips accounts that need reconnecting', function () {
        Queue::fake();
        $account = DriveAccount::factory()->create([
            'status' => DriveAccountStatus::ReconnectNeeded,
        ]);
        DriveFile::factory()->for($account, 'account')->create([
            'last_synced_at' => now()->subDay(),
        ]);

        (new RecheckDriveFiles)->handle();

        // Their token throws on refresh; the account status already knows.
        Queue::assertNothingPushed();
    });

    it('checks files that are in a Space before files that are not', function () {
        Queue::fake();
        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create([
            'status' => DriveAccountStatus::Connected,
        ]);

        // The loose file is staler, so only the priority pass can put the
        // Space file first.
        $loose = DriveFile::factory()->for($account, 'account')->create([
            'last_synced_at' => now()->subYear(),
        ]);
        $inSpace = DriveFile::factory()->for($account, 'account')->create([
            'last_synced_at' => now()->subDay(),
        ]);
        $space = Space::factory()->for($user)->create();
        SpaceItem::factory()->for($space)->create(['drive_file_id' => $inSpace->id]);

        (new RecheckDriveFiles)->handle();

        $order = [];
        Queue::assertPushed(SyncDriveFile::class, function (SyncDriveFile $job) use (&$order) {
            $order[] = $job->providerFileId;

            return true;
        });

        expect($order)->toBe([$inSpace->provider_file_id, $loose->provider_file_id]);
    });

    it('caps how many files one run asks Drive about', function () {
        Queue::fake();
        $account = DriveAccount::factory()->create([
            'status' => DriveAccountStatus::Connected,
        ]);
        DriveFile::factory()->for($account, 'account')
            ->count(RecheckDriveFiles::MAX_FILES + 5)
            ->create(['last_synced_at' => now()->subDay()]);

        (new RecheckDriveFiles)->handle();

        Queue::assertPushed(SyncDriveFile::class, RecheckDriveFiles::MAX_FILES);
    });

    it('voids a standing approval when the sweep finds a new version', function () {
        Notification::fake();

        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create([
            'status' => DriveAccountStatus::Connected,
            'access_token' => 'valid-token',
            'token_expires_at' => now()->addHour(),
        ]);
        $file = DriveFile::factory()->for($account, 'account')->create([
            'version_hash' => 'the-old-md5',
            'last_synced_at' => now()->subDay(),
        ]);

        $space = Space::factory()->for($user)->create();
        $item = SpaceItem::factory()->for($space)->create(['drive_file_id' => $file->id]);
        $approval = Approval::factory()->for($item, 'spaceItem')->create([
            'status' => ApprovalStatus::Approved,
        ]);

        // Drive answers with a different checksum: the photo was swapped
        // after the client signed it off.
        Http::fake([
            'www.googleapis.com/drive/v3/files/*' => Http::response([
                'id' => $file->provider_file_id,
                'name' => $file->name,
                'mimeType' => 'image/jpeg',
                'md5Checksum' => 'a-different-md5',
            ]),
        ]);

        // Run the selection, then the job it selected — end to end.
        (new RecheckDriveFiles)->handle();
        (new SyncDriveFile($account, $file->provider_file_id))
            ->handle(app(GoogleDriveService::class));

        expect($approval->fresh()->status)->toBe(ApprovalStatus::Cancelled)
            ->and($approval->fresh()->cancelled_reason)
            ->toBe(ApprovalCancelReason::FileVersionChanged);

        Notification::assertSentTo($user, ApprovalsVoided::class);
    });

    it('flags a file Drive will no longer show us', function () {
        $account = DriveAccount::factory()->create([
            'status' => DriveAccountStatus::Connected,
            'access_token' => 'valid-token',
            'token_expires_at' => now()->addHour(),
        ]);
        $file = DriveFile::factory()->for($account, 'account')->create([
            'access_lost_at' => null,
            'last_synced_at' => now()->subDay(),
        ]);

        Http::fake([
            'www.googleapis.com/drive/v3/files/*' => Http::response([], 404),
        ]);

        (new SyncDriveFile($account, $file->provider_file_id))
            ->handle(app(GoogleDriveService::class));

        // This is what Home's "Needs Attention" strip reads.
        expect($file->fresh()->access_lost_at)->not->toBeNull();
    });
});
