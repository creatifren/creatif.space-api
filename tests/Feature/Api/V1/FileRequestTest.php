<?php

use App\Enums\FileRequestStatus;
use App\Enums\SubmissionStatus;
use App\Jobs\UploadSubmissionFiles;
use App\Models\DriveAccount;
use App\Models\FileRequest;
use App\Models\FileRequestSubmission;
use App\Models\User;
use App\Notifications\FilesReceived;
use App\Services\GoogleDriveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * A creator with a Drive connected — a request cannot exist without one,
 * since it has to land somewhere.
 *
 * @return array{0: User, 1: DriveAccount}
 */
function creatorWithDrive(): array
{
    $user = User::factory()->create();

    return [$user, DriveAccount::factory()->for($user)->create()];
}

function requestPayload(DriveAccount $account, array $overrides = []): array
{
    return array_merge([
        'title' => 'Send me the brief',
        'drive_account_id' => $account->ulid,
        'folder_id' => 'folder-abc',
    ], $overrides);
}

/** A file the allowlist accepts, at a size it accepts. */
function goodFile(string $name = 'brief.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, 120, 'application/pdf');
}

describe('making a request', function () {
    it('mints a short slug and a link the owner can paste anywhere', function () {
        [$user, $account] = creatorWithDrive();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/file-requests', requestPayload($account))
            ->assertCreated();

        $slug = $response->json('data.slug');

        // Short enough to read out loud — a 26-char ULID in a WhatsApp
        // message is hostile.
        expect(strlen($slug))->toBe(10)
            ->and($response->json('data.url'))->toEndWith('/r/'.$slug)
            ->and($response->json('data.status'))->toBe('open');
    });

    it('gives a link an end date even when nobody picks one', function () {
        [$user, $account] = creatorWithDrive();

        $this->actingAs($user)->postJson('/api/v1/file-requests', requestPayload($account));

        expect(FileRequest::query()->sole()->expires_at)->not->toBeNull();
    });

    it('refuses a Drive that is not theirs, with a sentence', function () {
        [$user] = creatorWithDrive();
        $stranger = DriveAccount::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/file-requests', requestPayload($stranger))
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.drive_account_id.0',
                'Connect that Drive again before asking for files.',
            );
    });

    it('lists only this creator’s requests', function () {
        [$user, $account] = creatorWithDrive();
        FileRequest::factory()->for($user)->create(['drive_account_id' => $account->id]);
        FileRequest::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/file-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('is invisible to anyone else — 404, not 403', function () {
        $mine = FileRequest::factory()->create();
        $someoneElse = User::factory()->create();

        $this->actingAs($someoneElse)
            ->patchJson("/api/v1/file-requests/{$mine->ulid}", ['status' => 'closed'])
            ->assertNotFound();

        $this->actingAs($someoneElse)
            ->deleteJson("/api/v1/file-requests/{$mine->ulid}")
            ->assertNotFound();
    });

    it('closes on request, and the folder stays where it was', function () {
        $request = FileRequest::factory()->create();

        $this->actingAs($request->user)
            ->patchJson("/api/v1/file-requests/{$request->ulid}", [
                'status' => 'closed',
                // Not a field — a request that changes destination halfway
                // makes its own delivery history a lie.
                'folder_id' => 'somewhere-else',
            ])
            ->assertOk();

        $fresh = $request->fresh();
        expect($fresh->status)->toBe(FileRequestStatus::Closed)
            ->and($fresh->target_folder_id)->toBe($request->target_folder_id);
    });
});

describe('the public link', function () {
    it('says who is asking and what the limits are — and nothing about the Drive', function () {
        $request = FileRequest::factory()->create(['title' => 'Send me the brief']);

        $data = $this->getJson("/api/v1/r/{$request->slug}")->assertOk()->json('data');

        expect($data['title'])->toBe('Send me the brief')
            ->and($data['owner_name'])->toBe($request->user->name)
            ->and($data['max_files'])->toBe(5)
            ->and($data)->not->toHaveKey('folder_id')
            ->and($data)->not->toHaveKey('drive_account_id');
    });

    it('answers 404 unknown, 410 expired, 409 closed — in that order', function () {
        $expired = FileRequest::factory()->expired()->create();
        $closed = FileRequest::factory()->closed()->create();
        // Expiry wins over closed: the earlier gate is the truer answer.
        $both = FileRequest::factory()->closed()->expired()->create();

        $this->getJson('/api/v1/r/nothinghere')->assertNotFound();
        $this->getJson("/api/v1/r/{$expired->slug}")->assertGone();
        $this->getJson("/api/v1/r/{$closed->slug}")->assertConflict();
        $this->getJson("/api/v1/r/{$both->slug}")->assertGone();
    });
});

describe('sending files', function () {
    it('takes the delivery, stages the files, and answers 202 without waiting on Drive', function () {
        Storage::fake('local');
        Bus::fake();
        $request = FileRequest::factory()->create();

        $response = $this->postJson("/api/v1/r/{$request->slug}/submissions", [
            'sender_name' => 'Andi',
            'sender_email' => 'andi@winternoel.com',
            'message' => 'Two files, the rest tomorrow.',
            'files' => [goodFile('brief.pdf'), goodFile('shot-list.pdf')],
        ])->assertAccepted();

        // 202, not 201: the files are not in Drive yet and the sender is
        // told so rather than being held on the line.
        expect($response->json('data.status'))->toBe('uploading')
            ->and($response->json('data.files'))->toBe(2);

        $submission = FileRequestSubmission::query()->sole();
        expect($submission->sender_name)->toBe('Andi')
            ->and($submission->status)->toBe(SubmissionStatus::Uploading);

        Bus::assertDispatched(UploadSubmissionFiles::class);
    });

    it('never uses the sender’s filename as a path', function () {
        Storage::fake('local');
        Bus::fake();
        $request = FileRequest::factory()->create();

        $this->postJson("/api/v1/r/{$request->slug}/submissions", [
            'sender_name' => 'Andi',
            'sender_email' => 'andi@winternoel.com',
            'files' => [goodFile('../../etc/passwd.pdf')],
        ])->assertAccepted();

        Bus::assertDispatched(function (UploadSubmissionFiles $job): bool {
            // The stored path is a name we chose; the sender's name travels
            // as metadata for Drive and nothing else.
            return ! str_contains($job->staged[0]['path'], '..')
                && str_starts_with($job->staged[0]['path'], 'submissions/');
        });
    });

    it('refuses a file type that is not on the allowlist', function () {
        Storage::fake('local');
        $request = FileRequest::factory()->create();

        $this->postJson("/api/v1/r/{$request->slug}/submissions", [
            'sender_name' => 'Andi',
            'sender_email' => 'andi@winternoel.com',
            // Sniffed content type, not the extension — a filename is
            // whatever the sender says it is.
            'files' => [UploadedFile::fake()->create('payload.pdf', 10, 'application/x-php')],
        ])->assertUnprocessable();

        expect(FileRequestSubmission::query()->count())->toBe(0);
    });

    it('refuses more files than the request allows, and files that are too big', function () {
        Storage::fake('local');
        $request = FileRequest::factory()->create(['max_files' => 2, 'max_mb' => 1]);

        $this->postJson("/api/v1/r/{$request->slug}/submissions", [
            'sender_name' => 'Andi',
            'sender_email' => 'andi@winternoel.com',
            'files' => [goodFile(), goodFile(), goodFile()],
        ])->assertUnprocessable();

        $this->postJson("/api/v1/r/{$request->slug}/submissions", [
            'sender_name' => 'Andi',
            'sender_email' => 'andi@winternoel.com',
            'files' => [UploadedFile::fake()->create('huge.pdf', 4096, 'application/pdf')],
        ])->assertUnprocessable();
    });

    it('closes itself once the ceiling is reached', function () {
        Storage::fake('local');
        Bus::fake();
        $request = FileRequest::factory()->create();
        FileRequestSubmission::factory()
            ->count(FileRequest::SUBMISSION_CEILING - 1)
            ->for($request)
            ->create();

        $this->postJson("/api/v1/r/{$request->slug}/submissions", [
            'sender_name' => 'Andi',
            'sender_email' => 'andi@winternoel.com',
            'files' => [goodFile()],
        ])->assertAccepted();

        // A link that went further than its owner meant stops on its own.
        expect($request->fresh()->status)->toBe(FileRequestStatus::Closed);
    });

    it('refuses a closed or expired request outright', function () {
        Storage::fake('local');
        $closed = FileRequest::factory()->closed()->create();
        $expired = FileRequest::factory()->expired()->create();

        $payload = [
            'sender_name' => 'Andi',
            'sender_email' => 'andi@winternoel.com',
            'files' => [goodFile()],
        ];

        $this->postJson("/api/v1/r/{$closed->slug}/submissions", $payload)->assertConflict();
        $this->postJson("/api/v1/r/{$expired->slug}/submissions", $payload)->assertGone();

        expect(FileRequestSubmission::query()->count())->toBe(0);
    });
});

describe('the upload job', function () {
    it('puts the files in Drive, records what landed, and tells the owner', function () {
        Notification::fake();
        Storage::fake('local');

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fresh', 'expires_in' => 3600]),
            'www.googleapis.com/upload/*' => Http::sequence()
                ->push([], 200, ['Location' => 'https://upload.googleapis.com/session/1'])
                ->push(['id' => 'drive-file-1']),
            'upload.googleapis.com/*' => Http::response(['id' => 'drive-file-1']),
        ]);

        $submission = FileRequestSubmission::factory()->create();
        Storage::disk('local')->put('submissions/'.$submission->ulid.'/tmp', 'bytes');

        (new UploadSubmissionFiles($submission, [
            ['path' => 'submissions/'.$submission->ulid.'/tmp', 'name' => 'brief.pdf', 'mime' => 'application/pdf'],
        ]))->handle(app(GoogleDriveService::class));

        $fresh = $submission->fresh();
        expect($fresh->status)->toBe(SubmissionStatus::Stored)
            ->and($fresh->files)->toBe([['name' => 'brief.pdf', 'provider_file_id' => 'drive-file-1']]);

        // This app stores no files — the staged copy goes either way.
        expect(Storage::disk('local')->exists('submissions/'.$submission->ulid.'/tmp'))->toBeFalse();

        Notification::assertSentTo($submission->fileRequest->user, FilesReceived::class);
    });

    it('says so rather than going quiet when Drive refuses the folder', function () {
        Notification::fake();
        Storage::fake('local');

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fresh', 'expires_in' => 3600]),
            'www.googleapis.com/upload/*' => Http::response([], 404),
        ]);

        $submission = FileRequestSubmission::factory()->create();
        Storage::disk('local')->put('submissions/'.$submission->ulid.'/tmp', 'bytes');

        (new UploadSubmissionFiles($submission, [
            ['path' => 'submissions/'.$submission->ulid.'/tmp', 'name' => 'brief.pdf', 'mime' => 'application/pdf'],
        ]))->handle(app(GoogleDriveService::class));

        // The sender was already told their files went through, so a
        // failure that nobody hears about is the worst outcome.
        expect($submission->fresh()->status)->toBe(SubmissionStatus::Failed)
            ->and($submission->fresh()->failure_reason)->toContain('can’t be reached');

        Notification::assertSentTo($submission->fileRequest->user, FilesReceived::class);
    });
});
