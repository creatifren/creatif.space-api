<?php

use App\Models\FileRequest;
use App\Models\FileRequestSubmission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

describe('asked of you', function () {
    it('records the sender when they happen to be signed in', function () {
        Queue::fake();
        Storage::fake('local');
        $owner = User::factory()->create();
        $request = FileRequest::factory()->for($owner)->create();
        $sender = User::factory()->create();

        $this->actingAs($sender)
            ->postJson("/api/v1/r/{$request->slug}/submissions", [
                'sender_name' => 'Rani',
                'sender_email' => 'rani@example.test',
                'files' => [UploadedFile::fake()->image('shot.jpg')],
            ])
            ->assertAccepted();

        expect(FileRequestSubmission::sole()->user_id)->toBe($sender->id);
    });

    it('still accepts a submission from nobody at all', function () {
        Queue::fake();
        Storage::fake('local');
        $request = FileRequest::factory()->create();

        /* The link is the product: no account needed, and never will be.
           Somebody who drops files without one simply has no history. */
        $this->postJson("/api/v1/r/{$request->slug}/submissions", [
            'sender_name' => 'A stranger',
            'sender_email' => 'stranger@example.test',
            'files' => [UploadedFile::fake()->image('shot.jpg')],
        ])->assertAccepted();

        expect(FileRequestSubmission::sole()->user_id)->toBeNull();
    });

    it('lists what I sent, and never what other people sent', function () {
        $owner = User::factory()->create(['name' => 'Budi']);
        $request = FileRequest::factory()->for($owner)->create(['title' => 'Menu photos']);
        $me = User::factory()->create();

        $request->submissions()->create([
            'user_id' => $me->id,
            'sender_name' => 'Rani',
            'sender_email' => 'rani@example.test',
            'files' => [['name' => 'a.jpg', 'file_id' => 'x']],
        ]);
        $request->submissions()->create([
            'user_id' => User::factory()->create()->id,
            'sender_name' => 'Someone else',
            'sender_email' => 'other@example.test',
            'files' => [],
        ]);
        // Anonymous: real, but not attributable to anyone.
        $request->submissions()->create([
            'sender_name' => 'Nobody',
            'sender_email' => 'nobody@example.test',
            'files' => [],
        ]);

        $response = $this->actingAs($me)->getJson('/api/v1/file-requests/asked')->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.title'))->toBe('Menu photos')
            ->and($response->json('data.0.asked_by'))->toBe('Budi')
            ->and($response->json('data.0.files_count'))->toBe(1);
    });

    it('shows one row per request, however many times I sent to it', function () {
        $request = FileRequest::factory()->create();
        $me = User::factory()->create();

        foreach (range(1, 3) as $i) {
            $request->submissions()->create([
                'user_id' => $me->id,
                'sender_name' => 'Rani',
                'sender_email' => 'rani@example.test',
                'files' => [],
            ]);
        }

        expect($this->actingAs($me)->getJson('/api/v1/file-requests/asked')->json('data'))
            ->toHaveCount(1);
    });

    it('says when the link has closed rather than sending me back to it', function () {
        $request = FileRequest::factory()->create(['expires_at' => now()->subDay()]);
        $me = User::factory()->create();
        $request->submissions()->create([
            'user_id' => $me->id,
            'sender_name' => 'Rani',
            'sender_email' => 'rani@example.test',
            'files' => [],
        ]);

        $this->actingAs($me)->getJson('/api/v1/file-requests/asked')
            ->assertOk()
            ->assertJsonPath('data.0.expired', true);
    });

    it('is signed-in only', function () {
        $this->getJson('/api/v1/file-requests/asked')->assertUnauthorized();
    });
});
