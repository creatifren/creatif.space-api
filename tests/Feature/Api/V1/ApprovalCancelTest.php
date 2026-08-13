<?php

use App\Enums\ApprovalStatus;
use App\Jobs\SyncDriveFile;
use App\Models\Approval;
use App\Models\Client;
use App\Models\DriveAccount;
use App\Models\DriveFile;
use App\Models\Space;
use App\Models\SpaceItem;
use App\Models\User;
use App\Notifications\ApprovalsVoided;
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * Arrange a signed-off file, then let Drive move it under our feet.
 *
 * @return array{0: Approval, 1: DriveFile, 2: DriveAccount, 3: User}
 */
function approvedFile(string $versionHash = 'v1'): array
{
    $owner = User::factory()->create();
    $space = Space::factory()->for($owner)->published()->create(['approval_enabled' => true]);
    $account = DriveAccount::factory()->for($owner)->create();

    $file = DriveFile::factory()->for($account, 'account')->create([
        'provider_file_id' => 'file-1',
        'name' => 'winter-noel-07.jpg',
        'version_hash' => $versionHash,
    ]);

    $item = SpaceItem::factory()->for($space)->create(['drive_file_id' => $file->id]);
    $approval = Approval::factory()->for($item, 'spaceItem')->approved()->create();

    return [$approval, $file, $account, $owner];
}

it('voids standing approvals when the file version moves', function () {
    Notification::fake();
    [$approval, $file, $account, $owner] = approvedFile('v1');

    Http::fake([
        'https://www.googleapis.com/drive/v3/files/file-1*' => Http::response([
            'id' => 'file-1',
            'name' => 'winter-noel-07.jpg',
            'mimeType' => 'image/jpeg',
            'md5Checksum' => 'v2-the-file-changed',
        ]),
    ]);

    (new SyncDriveFile($account, 'file-1'))->handle(app(GoogleDriveService::class));

    expect($approval->refresh()->status)->toBe(ApprovalStatus::Cancelled)
        ->and($approval->cancelled_reason->value)->toBe('file_version_changed')
        // The date it was signed off stays: it is history, not a claim.
        ->and($approval->approved_at)->not->toBeNull();

    Notification::assertSentTo($owner, ApprovalsVoided::class);
});

it('leaves everything alone when the version is unchanged', function () {
    Notification::fake();
    [$approval, $file, $account] = approvedFile('v1');

    Http::fake([
        'https://www.googleapis.com/drive/v3/files/file-1*' => Http::response([
            'id' => 'file-1',
            'name' => 'winter-noel-07.jpg',
            'mimeType' => 'image/jpeg',
            'md5Checksum' => 'v1',
        ]),
    ]);

    (new SyncDriveFile($account, 'file-1'))->handle(app(GoogleDriveService::class));

    expect($approval->refresh()->status)->toBe(ApprovalStatus::Approved);
    Notification::assertNothingSent();
});

it('sends one notification per Space, not one per approval', function () {
    Notification::fake();
    [$approval, $file, $account, $owner] = approvedFile('v1');

    // Two more clients signed off on the very same file.
    foreach (range(1, 2) as $i) {
        Approval::factory()
            ->for($approval->spaceItem, 'spaceItem')
            ->for(Client::factory(), 'client')
            ->approved()
            ->create();
    }

    Http::fake([
        'https://www.googleapis.com/drive/v3/files/file-1*' => Http::response([
            'id' => 'file-1',
            'name' => 'winter-noel-07.jpg',
            'mimeType' => 'image/jpeg',
            'md5Checksum' => 'v2',
        ]),
    ]);

    (new SyncDriveFile($account, 'file-1'))->handle(app(GoogleDriveService::class));

    Notification::assertSentToTimes($owner, ApprovalsVoided::class, 1);
});

it('surfaces the void on the Needs Attention strip', function () {
    Notification::fake();
    [$approval, $file, $account, $owner] = approvedFile('v1');

    Http::fake([
        'https://www.googleapis.com/drive/v3/files/file-1*' => Http::response([
            'id' => 'file-1',
            'name' => 'winter-noel-07.jpg',
            'mimeType' => 'image/jpeg',
            'md5Checksum' => 'v2',
        ]),
    ]);

    (new SyncDriveFile($account, 'file-1'))->handle(app(GoogleDriveService::class));

    $this->actingAs($owner)->getJson('/api/v1/me/attention')
        ->assertOk()
        ->assertJsonPath('data.0.kind', 'approval_void')
        ->assertJsonPath('data.0.file_name', 'winter-noel-07.jpg');
});

it('is empty for someone with nothing broken', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/me/attention')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('resets a whole Space back to pending when the owner asks', function () {
    Notification::fake();
    [$approval] = approvedFile('v1');
    $space = $approval->spaceItem->space;

    $this->actingAs($space->user)
        ->postJson("/api/v1/spaces/{$space->ulid}/approvals/reset")
        ->assertOk()
        ->assertJsonPath('data.reset', 1);

    expect($approval->refresh()->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->approved_at)->toBeNull();
});

it('404s when someone else tries to reset your Space', function () {
    [$approval] = approvedFile('v1');

    $this->actingAs(User::factory()->create())
        ->postJson("/api/v1/spaces/{$approval->spaceItem->space->ulid}/approvals/reset")
        ->assertNotFound();
});
