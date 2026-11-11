<?php

use App\Enums\SocialPostStatus;
use App\Jobs\ApplyMediaRetention;
use App\Models\File;
use App\Models\Profile;
use App\Models\SpaceItem;
use App\Models\Transfer;
use App\Support\Media\MediaRetention;
use App\Support\Media\RetentionVerdict;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/*
 * Rule 3: retention. Report mode (default) deletes nothing; delete mode
 * removes object + row together. Spaces, transfers, avatars and brand
 * assets are never touched; neither is anything on a queued post or a
 * failed post still inside its retry window.
 */

beforeEach(function () {
    Storage::fake('s3');
});

/** Put bytes behind a file so a delete can be observed in "R2". */
function stored(File $file): File
{
    Storage::disk('s3')->put($file->path, 'bytes');

    return $file;
}

function verdict(File $file): RetentionVerdict
{
    MediaRetention::flush();

    return MediaRetention::for($file->fresh());
}

it('defaults to report mode', function () {
    expect(config('media.retention_mode'))->toBe('report');
});

describe('paid', function () {
    it('deletes a file 30 days after the last post using it published — in delete mode only', function () {
        $user = mediaUser('pro');
        $old = stored(mediaFile($user));
        $recent = stored(mediaFile($user));

        postWith($user, [$old], SocialPostStatus::Published, ['updated_at' => now()->subDays(31)]);
        postWith($user, [$recent], SocialPostStatus::Published, ['updated_at' => now()->subDays(29)]);

        expect(verdict($old)->rule)->toBe(RetentionVerdict::PAID_AFTER_PUBLISH)
            ->and(verdict($old)->isDue())->toBeTrue()
            ->and(verdict($recent)->isDue())->toBeFalse();

        $report = (new ApplyMediaRetention('report'))->handle();
        expect($report['files'])->toBe(1)
            ->and($report['users'][$user->ulid]['rules'])->toBe([RetentionVerdict::PAID_AFTER_PUBLISH => 1])
            ->and(File::query()->whereKey($old->id)->exists())->toBeTrue()
            ->and(Storage::disk('s3')->exists($old->path))->toBeTrue();

        $deleted = (new ApplyMediaRetention('delete'))->handle();
        expect($deleted['files'])->toBe(1)
            ->and(File::withTrashed()->whereKey($old->id)->exists())->toBeFalse()
            ->and(Storage::disk('s3')->exists($old->path))->toBeFalse()
            ->and(File::query()->whereKey($recent->id)->exists())->toBeTrue();

        // Idempotent: nothing left to do.
        expect((new ApplyMediaRetention('delete'))->handle()['files'])->toBe(0);
    });

    it('uses the latest publish when several posts used the file', function () {
        $user = mediaUser('max');
        $file = stored(mediaFile($user));
        postWith($user, [$file], SocialPostStatus::Published, ['updated_at' => now()->subDays(40)]);
        postWith($user, [$file], SocialPostStatus::Published, ['updated_at' => now()->subDays(10)]);

        expect(verdict($file)->dueAt->toDateString())->toBe(now()->subDays(10)->addDays(30)->toDateString());
    });

    it('never deletes kept files, queued-post media, or files never published', function () {
        $user = mediaUser('pro');
        $kept = stored(mediaFile($user, ['kept_at' => now()]));
        $queued = stored(mediaFile($user));
        $never = stored(mediaFile($user, ['created_at' => now()->subYear()]));

        postWith($user, [$kept], SocialPostStatus::Published, ['updated_at' => now()->subDays(90)]);
        postWith($user, [$queued], SocialPostStatus::Published, ['updated_at' => now()->subDays(90)]);
        postWith($user, [$queued], SocialPostStatus::Scheduled);

        expect(verdict($kept)->rule)->toBe(RetentionVerdict::KEPT)
            ->and(verdict($queued)->rule)->toBe(RetentionVerdict::QUEUED)
            ->and(verdict($never)->rule)->toBe(RetentionVerdict::NEVER_PUBLISHED);

        expect((new ApplyMediaRetention('delete'))->handle()['files'])->toBe(0)
            ->and(File::query()->count())->toBe(3);
    });

    it('treats a trial as paid', function () {
        $user = mediaUser('trial');
        $file = stored(mediaFile($user, ['created_at' => now()->subDays(3)]));

        // Free would take an unattached file after 24 h; a trial does not.
        expect(verdict($file)->rule)->toBe(RetentionVerdict::NEVER_PUBLISHED);
    });
});

describe('free', function () {
    it('deletes a file right after the post using it publishes', function () {
        $user = mediaUser('free');
        $file = stored(mediaFile($user));
        postWith($user, [$file], SocialPostStatus::Published, ['updated_at' => now()->subMinute()]);

        expect(verdict($file)->rule)->toBe(RetentionVerdict::FREE_PUBLISHED)
            ->and(verdict($file)->isDue())->toBeTrue();

        (new ApplyMediaRetention('delete'))->handle();
        expect(File::withTrashed()->whereKey($file->id)->exists())->toBeFalse()
            ->and(Storage::disk('s3')->exists($file->path))->toBeFalse();
    });

    it('keeps a failed post\'s media through its 7-day window, then deletes it', function () {
        $user = mediaUser('free');
        $retryable = stored(mediaFile($user));
        $expired = stored(mediaFile($user));

        postWith($user, [$retryable], SocialPostStatus::Failed, ['scheduled_at' => now()->subDays(6)]);
        postWith($user, [$expired], SocialPostStatus::Failed, ['scheduled_at' => now()->subDays(8)]);

        expect(verdict($retryable)->rule)->toBe(RetentionVerdict::FREE_FAILED_WINDOW)
            ->and(verdict($retryable)->isDue())->toBeFalse()
            ->and(verdict($expired)->isDue())->toBeTrue();

        $summary = (new ApplyMediaRetention('delete'))->handle();
        expect($summary['files'])->toBe(1)
            ->and(File::query()->whereKey($retryable->id)->exists())->toBeTrue()
            ->and(File::query()->whereKey($expired->id)->exists())->toBeFalse();
    });

    it('deletes a file on no queued post 24 h after upload', function () {
        $user = mediaUser('free');
        $old = stored(mediaFile($user, ['created_at' => now()->subHours(25)]));
        $young = stored(mediaFile($user, ['created_at' => now()->subHours(23)]));
        $cancelledOnly = stored(mediaFile($user, ['created_at' => now()->subHours(30)]));
        postWith($user, [$cancelledOnly], SocialPostStatus::Cancelled);

        expect(verdict($old)->rule)->toBe(RetentionVerdict::FREE_UNATTACHED)
            ->and(verdict($old)->isDue())->toBeTrue()
            ->and(verdict($young)->isDue())->toBeFalse()
            ->and(verdict($cancelledOnly)->isDue())->toBeTrue();

        expect((new ApplyMediaRetention('report'))->handle()['files'])->toBe(2)
            ->and(File::query()->count())->toBe(3); // report deletes nothing
    });

    it('never deletes media on a scheduled or draft post, however old', function () {
        $user = mediaUser('free');
        $scheduled = stored(mediaFile($user, ['created_at' => now()->subDays(5)]));
        $draft = stored(mediaFile($user, ['created_at' => now()->subDays(5)]));
        postWith($user, [$scheduled], SocialPostStatus::Scheduled);
        postWith($user, [$draft], SocialPostStatus::Draft);
        // Published elsewhere too: the queued post still wins.
        postWith($user, [$scheduled], SocialPostStatus::Published, ['updated_at' => now()->subDays(2)]);

        expect(verdict($scheduled)->rule)->toBe(RetentionVerdict::QUEUED)
            ->and(verdict($draft)->rule)->toBe(RetentionVerdict::QUEUED)
            ->and((new ApplyMediaRetention('delete'))->handle()['files'])->toBe(0);
    });
});

describe('never retention: Spaces, transfers, avatar, brand assets, non-post media', function () {
    it('leaves them all alone, in both modes, on every tier', function (string $tier) {
        $user = mediaUser($tier);
        $old = ['created_at' => now()->subDays(60)];

        $inSpace = stored(mediaFile($user, $old));
        SpaceItem::factory()->create(['file_id' => $inSpace->id]);

        $inTransfer = stored(mediaFile($user, $old));
        Transfer::factory()->for($user)->create()->files()->attach($inTransfer->id);

        $avatar = stored(mediaFile($user, $old));
        $user->forceFill(['avatar_url' => mediaUrl($avatar)])->save();

        $cover = stored(mediaFile($user, $old));
        $logo = stored(mediaFile($user, $old));
        Profile::factory()->for($user)->create([
            'cover_url' => mediaUrl($cover),
            'appearance' => ['logo_url' => mediaUrl($logo)],
        ]);

        $pdf = stored(mediaFile($user, $old + ['mime_type' => 'application/pdf']));

        // Every one of them also sat on a post published long ago.
        postWith($user, [$inSpace, $inTransfer, $avatar, $cover, $logo], SocialPostStatus::Published, ['updated_at' => now()->subDays(60)]);

        foreach ([$inSpace, $inTransfer, $avatar, $cover, $logo] as $file) {
            expect(verdict($file)->rule)->toBe(RetentionVerdict::PROTECTED);
        }
        expect(verdict($pdf)->rule)->toBe(RetentionVerdict::NOT_POST_MEDIA);

        expect((new ApplyMediaRetention('report'))->handle()['files'])->toBe(0)
            ->and((new ApplyMediaRetention('delete'))->handle()['files'])->toBe(0)
            ->and(File::query()->count())->toBe(6);

        foreach ([$inSpace, $inTransfer, $avatar, $cover, $logo, $pdf] as $file) {
            expect(Storage::disk('s3')->exists($file->path))->toBeTrue();
        }
    })->with(['free', 'pro']);
});

it('deletes old versions\' objects with the file', function () {
    $user = mediaUser('free');
    $file = stored(mediaFile($user, ['created_at' => now()->subDays(2)]));
    $version = $file->versions()->create([
        'number' => 1, 'disk' => 's3', 'path' => 'v/'.$file->ulid.'/old.jpg', 'size_bytes' => 10, 'user_id' => $user->id,
    ]);
    Storage::disk('s3')->put($version->path, 'old');

    (new ApplyMediaRetention('delete'))->handle();

    expect(Storage::disk('s3')->exists($version->path))->toBeFalse()
        ->and(Storage::disk('s3')->exists($file->path))->toBeFalse();
});

it('prints a per-user summary from the console, in report mode by default', function () {
    $user = mediaUser('free');
    stored(mediaFile($user, ['created_at' => now()->subDays(2), 'size_bytes' => 2 * 1024 * 1024]));

    expect(Artisan::call('media:retention'))->toBe(0);
    expect(Artisan::output())->toContain('Mode: report')
        ->toContain($user->ulid)
        ->toContain('free_unattached: 1')
        ->toContain('Would delete: 1 files, 2 MB, across 1 users.');

    expect(File::query()->count())->toBe(1);
});

it('refuses an unknown mode', function () {
    $this->artisan('media:retention --mode=wipe')->assertFailed();
});

describe('the API shows the same verdict', function () {
    it('returns is_kept and auto_delete_at on list and detail', function () {
        $user = mediaUser('free');
        $created = now()->subHours(2)->startOfSecond();
        $file = mediaFile($user, ['created_at' => $created]);
        $kept = mediaFile($user, ['kept_at' => now()]);
        $queued = mediaFile($user);
        postWith($user, [$queued], SocialPostStatus::Scheduled);

        $expected = $created->copy()->addHours(24)->utc()->toIso8601ZuluString();

        $list = $this->actingAs($user)->getJson('/api/v1/files')->assertOk();
        $rows = collect($list->json('data'))->keyBy('id');

        expect($rows[$file->ulid]['is_kept'])->toBeFalse()
            ->and($rows[$file->ulid]['auto_delete_at'])->toBe($expected)
            ->and($rows[$kept->ulid]['is_kept'])->toBeTrue()
            ->and($rows[$kept->ulid]['auto_delete_at'])->toBeNull()
            ->and($rows[$queued->ulid]['auto_delete_at'])->toBeNull()
            ->and($list->json('meta.usage.quota_bytes'))->toBe(MEDIA_GB);

        $this->actingAs($user)->getJson("/api/v1/files/{$file->ulid}")
            ->assertOk()
            ->assertJsonPath('data.auto_delete_at', $expected);

        expect(verdict($file)->dueAt?->utc()->toIso8601ZuluString())->toBe($expected);
    });

    it('shows null for paid files with no clock running', function () {
        $user = mediaUser('pro');
        $file = mediaFile($user);

        $this->actingAs($user)->getJson("/api/v1/files/{$file->ulid}")
            ->assertOk()
            ->assertJsonPath('data.is_kept', false)
            ->assertJsonPath('data.auto_delete_at', null);
    });
});
