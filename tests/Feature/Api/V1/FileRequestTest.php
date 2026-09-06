<?php

use App\Enums\FileRequestStatus;
use App\Enums\SubmissionStatus;
use App\Jobs\UploadSubmissionFiles;
use App\Models\File;
use App\Models\FileRequest;
use App\Models\FileRequestSubmission;
use App\Models\User;
use App\Notifications\FilesReceived;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function requestPayload(array $overrides = []): array
{
    return array_merge(['title' => 'Send me the brief'], $overrides);
}

/** A file the allowlist accepts, at a size it accepts. */
function goodFile(string $name = 'brief.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, 120, 'application/pdf');
}

describe('making a request', function () {
    it('mints a short slug and a link the owner can paste anywhere', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/file-requests', requestPayload())
            ->assertCreated();

        $slug = $response->json('data.slug');

        // Short enough to read out loud — a 26-char ULID in a WhatsApp
        // message is hostile.
        expect(strlen($slug))->toBe(10)
            ->and($response->json('data.url'))->toEndWith('/r/'.$slug)
            ->and($response->json('data.status'))->toBe('open');
    });

    it('gives a link an end date even when nobody picks one', function () {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/file-requests', requestPayload());

        expect(FileRequest::query()->sole()->expires_at)->not->toBeNull();
    });

    it('lists only this creator’s requests', function () {
        $user = User::factory()->create();
        FileRequest::factory()->for($user)->create();
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

    it('closes on request', function () {
        $request = FileRequest::factory()->create();

        $this->actingAs($request->user)
            ->patchJson("/api/v1/file-requests/{$request->ulid}", ['status' => 'closed'])
            ->assertOk();

        expect($request->fresh()->status)->toBe(FileRequestStatus::Closed);
    });
});

describe('the limits the owner sets', function () {
    it('takes max_files and max_mb, and falls back to the column defaults', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/file-requests', requestPayload(['max_files' => 12, 'max_mb' => 250]))
            ->assertCreated()
            ->assertJsonPath('data.max_files', 12)
            ->assertJsonPath('data.max_mb', 250);

        $this->actingAs($user)
            ->postJson('/api/v1/file-requests', requestPayload())
            ->assertCreated()
            ->assertJsonPath('data.max_files', 5)
            ->assertJsonPath('data.max_mb', 100);
    });

    it('refuses limits past the ceiling', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/file-requests', requestPayload([
                'max_files' => FileRequest::MAX_FILES_CEILING + 1,
            ]))
            ->assertUnprocessable();

        // Past the column's own range, which would silently truncate.
        $this->actingAs($user)
            ->postJson('/api/v1/file-requests', requestPayload(['max_mb' => 99_999]))
            ->assertUnprocessable();
    });

    it('changes the limits on a link that is already out', function () {
        $request = FileRequest::factory()->create(['max_files' => 5]);

        $this->actingAs($request->user)
            ->patchJson("/api/v1/file-requests/{$request->ulid}", ['max_files' => 20])
            ->assertOk()
            ->assertJsonPath('data.max_files', 20);
    });

    it('enforces a raised limit on the public side', function () {
        Storage::fake('local');
        Bus::fake();
        $request = FileRequest::factory()->create(['max_files' => 1]);

        $payload = fn () => [
            'sender_name' => 'Andi',
            'sender_email' => 'andi@winternoel.com',
            'files' => [goodFile('a.pdf'), goodFile('b.pdf')],
        ];

        $this->postJson("/api/v1/r/{$request->slug}/submissions", $payload())
            ->assertUnprocessable();

        $this->actingAs($request->user)
            ->patchJson("/api/v1/file-requests/{$request->ulid}", ['max_files' => 5])
            ->assertOk();

        // The setting is not decoration: the same submission now goes through.
        $this->postJson("/api/v1/r/{$request->slug}/submissions", $payload())
            ->assertAccepted();
    });
});

describe('a password on the link', function () {
    it('reports whether there is one, and never the hash', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/file-requests', requestPayload(['password' => 'winter']))
            ->assertCreated()
            ->assertJsonPath('data.has_password', true);

        expect($response->json('data'))->not->toHaveKey('password_hash')
            ->and(FileRequest::query()->sole()->password_hash)->not->toBe('winter');
    });

    it('locks the page until the password is traded for a token', function () {
        $request = FileRequest::factory()->create();
        $this->actingAs($request->user)
            ->patchJson("/api/v1/file-requests/{$request->ulid}", ['password' => 'winter'])
            ->assertOk()
            ->assertJsonPath('data.has_password', true);

        // Nothing leaks through the lock — not even the title.
        $locked = $this->getJson("/api/v1/r/{$request->slug}")->assertStatus(423);
        expect($locked->json())->not->toHaveKey('title');

        $this->postJson("/api/v1/r/{$request->slug}/unlock", ['password' => 'nope'])
            ->assertUnprocessable();

        $token = $this->postJson("/api/v1/r/{$request->slug}/unlock", ['password' => 'winter'])
            ->assertOk()
            ->json('data.token');

        $this->getJson("/api/v1/r/{$request->slug}?st={$token}")
            ->assertOk()
            ->assertJsonPath('data.title', $request->title);
    });

    it('locks the submit route too, not just the page', function () {
        Storage::fake('local');
        Bus::fake();
        $request = FileRequest::factory()->create();
        $this->actingAs($request->user)
            ->patchJson("/api/v1/file-requests/{$request->ulid}", ['password' => 'winter']);

        $payload = [
            'sender_name' => 'Andi',
            'sender_email' => 'andi@winternoel.com',
            'files' => [goodFile()],
        ];

        // The form is trivially rebuilt from the slug — the door and the
        // window need the same lock.
        $this->postJson("/api/v1/r/{$request->slug}/submissions", $payload)
            ->assertStatus(423);

        expect(FileRequestSubmission::query()->count())->toBe(0);

        $token = $this->postJson("/api/v1/r/{$request->slug}/unlock", ['password' => 'winter'])
            ->json('data.token');

        $this->postJson("/api/v1/r/{$request->slug}/submissions", $payload + ['st' => $token])
            ->assertAccepted();
    });

    it('says expired before it asks for a password', function () {
        $request = FileRequest::factory()->expired()->create();
        $this->actingAs($request->user)
            ->patchJson("/api/v1/file-requests/{$request->ulid}", ['password' => 'winter']);

        // A dead link must not ask for a password it will not honour.
        $this->getJson("/api/v1/r/{$request->slug}")->assertGone();
    });

    it('clears the password with an explicit null and leaves it alone otherwise', function () {
        $request = FileRequest::factory()->create();
        $this->actingAs($request->user)
            ->patchJson("/api/v1/file-requests/{$request->ulid}", ['password' => 'winter']);

        // A rename must not unlock the link.
        $this->actingAs($request->user)
            ->patchJson("/api/v1/file-requests/{$request->ulid}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.has_password', true);

        $this->actingAs($request->user)
            ->patchJson("/api/v1/file-requests/{$request->ulid}", ['password' => null])
            ->assertOk()
            ->assertJsonPath('data.has_password', false);

        $this->getJson("/api/v1/r/{$request->slug}")->assertOk();
    });
});

describe('the public link', function () {
    it('says who is asking and what the limits are', function () {
        $request = FileRequest::factory()->create(['title' => 'Send me the brief']);

        $data = $this->getJson("/api/v1/r/{$request->slug}")->assertOk()->json('data');

        expect($data['title'])->toBe('Send me the brief')
            ->and($data['owner_name'])->toBe($request->user->name)
            ->and($data['max_files'])->toBe(5);
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
    it('takes the delivery, stages the files, and answers 202 without waiting on storage', function () {
        Storage::fake('local');
        Bus::fake();
        $request = FileRequest::factory()->create();

        $response = $this->postJson("/api/v1/r/{$request->slug}/submissions", [
            'sender_name' => 'Andi',
            'sender_email' => 'andi@winternoel.com',
            'message' => 'Two files, the rest tomorrow.',
            'files' => [goodFile('brief.pdf'), goodFile('shot-list.pdf')],
        ])->assertAccepted();

        // 202, not 201: the files are not in the library yet and the sender
        // is told so rather than being held on the line.
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
            // as metadata and nothing else.
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
    it('lands the files in the owner’s library, records what landed, and tells the owner', function () {
        Notification::fake();
        Storage::fake('local');
        Storage::fake('s3');

        $submission = FileRequestSubmission::factory()->create();
        $request = $submission->fileRequest;
        Storage::disk('local')->put('submissions/'.$submission->ulid.'/tmp', 'bytes');

        (new UploadSubmissionFiles($submission, [
            ['path' => 'submissions/'.$submission->ulid.'/tmp', 'name' => 'brief.pdf', 'mime' => 'application/pdf'],
        ]))->handle();

        $file = File::query()->sole();
        expect($file->status)->toBe(File::STATUS_READY)
            ->and($file->user_id)->toBe($request->user_id)
            ->and($file->name)->toBe('brief.pdf')
            ->and($file->source)->toBe('request')
            ->and($file->path)->toStartWith('requests/'.$request->ulid.'/');

        Storage::disk('s3')->assertExists($file->path);

        $fresh = $submission->fresh();
        expect($fresh->status)->toBe(SubmissionStatus::Stored)
            ->and($fresh->files)->toBe([['name' => 'brief.pdf', 'file_id' => $file->ulid]]);

        // The staged copy goes either way.
        expect(Storage::disk('local')->exists('submissions/'.$submission->ulid.'/tmp'))->toBeFalse();

        Notification::assertSentTo($request->user, FilesReceived::class);
    });

    it('says so rather than going quiet when storage is full', function () {
        Notification::fake();
        Storage::fake('local');
        Storage::fake('s3');

        $submission = FileRequestSubmission::factory()->create();

        // Free plan: 2 GB, all of it spoken for.
        File::factory()->for($submission->fileRequest->user)->create([
            'size_bytes' => 2 * 1024 * 1024 * 1024,
        ]);

        Storage::disk('local')->put('submissions/'.$submission->ulid.'/tmp', 'bytes');

        (new UploadSubmissionFiles($submission, [
            ['path' => 'submissions/'.$submission->ulid.'/tmp', 'name' => 'brief.pdf', 'mime' => 'application/pdf'],
        ]))->handle();

        // The sender was already told their files went through, so a
        // failure that nobody hears about is the worst outcome.
        expect($submission->fresh()->status)->toBe(SubmissionStatus::Failed)
            ->and($submission->fresh()->failure_reason)
            ->toBe('Storage is full — free some space or upgrade the plan.');

        // The staging is cleaned whichever way this ends.
        expect(Storage::disk('local')->exists('submissions/'.$submission->ulid.'/tmp'))->toBeFalse();

        Notification::assertSentTo($submission->fileRequest->user, FilesReceived::class);
    });
});
