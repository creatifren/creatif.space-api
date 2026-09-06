<?php

use App\Enums\ActivityAction;
use App\Jobs\PruneActivityLog;
use App\Models\Activity;
use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

describe('the ledger', function () {
    it('records an upload when it completes', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = File::factory()->for($user)->pending()->create(['name' => 'shot.jpg']);
        Storage::disk('s3')->put($file->path, 'bytes');

        $this->actingAs($user)
            ->postJson("/api/v1/files/{$file->ulid}/complete")
            ->assertOk();

        $row = Activity::sole();
        expect($row->action)->toBe(ActivityAction::Upload)
            ->and($row->summary)->toBe('Uploaded shot.jpg')
            ->and($row->subject_id)->toBe($file->ulid);
    });

    it('records the trash, the restore, and the purge as three separate facts', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = File::factory()->for($user)->create(['name' => 'shot.jpg']);
        Storage::disk('s3')->put($file->path, 'bytes');

        $this->actingAs($user)->deleteJson("/api/v1/files/{$file->ulid}")->assertNoContent();
        $this->actingAs($user)->postJson("/api/v1/files/trash/{$file->ulid}/restore")->assertOk();
        $this->actingAs($user)->deleteJson("/api/v1/files/{$file->ulid}")->assertNoContent();
        $this->actingAs($user)->deleteJson("/api/v1/files/trash/{$file->ulid}")->assertNoContent();

        expect(Activity::pluck('action')->map(fn ($a) => $a->value)->all())
            ->toBe(['trash', 'restore', 'trash', 'purge']);
    });

    it('never lets a ledger failure take the action down with it', function () {
        /* A history line is worth less than the upload it describes, so
           log() swallows. Passing no user is the cheapest way to prove the
           call site is not guarding it. */
        Activity::log(null, ActivityAction::Upload, 'nobody did this');

        expect(Activity::count())->toBe(0);
    });
});

describe('the activity feed', function () {
    it('interleaves what I did with what I was told, newest first', function () {
        $user = User::factory()->create();

        Activity::log($user, ActivityAction::SpacePublish, 'Published Winter Noel', 'space', 'x');
        $user->notify(new App\Notifications\StorageAlmostFull(1_900_000_000, 2_000_000_000));

        $rows = $this->actingAs($user)->getJson('/api/v1/me/activity')->assertOk()->json('data');

        expect($rows)->toHaveCount(2)
            ->and(collect($rows)->pluck('kind')->sort()->values()->all())
            ->toBe(['action', 'notification']);
    });

    it('marks my own actions read — a thing I did was never news to me', function () {
        $user = User::factory()->create();
        Activity::log($user, ActivityAction::Upload, 'Uploaded shot.jpg');

        $row = collect($this->actingAs($user)->getJson('/api/v1/me/activity')->json('data'))
            ->firstWhere('kind', 'action');

        /* Otherwise "mark all read" would appear to leave rows behind
           forever — there is no way to mark a fact read. */
        expect($row['read_at'])->not->toBeNull()
            ->and($row['tool'])->toBe('Files');
    });

    it('honours the range and refuses one past what is kept', function () {
        $user = User::factory()->create();
        Activity::log($user, ActivityAction::Upload, 'Old news');
        Activity::query()->update(['created_at' => now()->subDays(45)]);

        expect($this->actingAs($user)->getJson('/api/v1/me/activity?days=30')->json('data'))
            ->toHaveCount(0)
            ->and($this->actingAs($user)->getJson('/api/v1/me/activity?days=60')->json('data'))
            ->toHaveCount(1);

        $this->actingAs($user)->getJson('/api/v1/me/activity?days=365')->assertStatus(422);
    });

    it('is signed-in only', function () {
        $this->getJson('/api/v1/me/activity')->assertUnauthorized();
    });
});

it('prunes the ledger past its ninety days', function () {
    $user = User::factory()->create();
    Activity::log($user, ActivityAction::Upload, 'Ancient');
    Activity::query()->update(['created_at' => now()->subDays(PruneActivityLog::KEEP_DAYS + 1)]);
    Activity::log($user, ActivityAction::Upload, 'Recent');

    (new PruneActivityLog)->handle();

    expect(Activity::pluck('summary')->all())->toBe(['Recent']);
});
