<?php

use App\Models\File;
use App\Models\MediaUploadFlag;
use App\Support\Media\FileSignature;
use App\Support\Media\PresignedUpload;
use Illuminate\Support\Facades\Storage;

/*
 * Rules 4–6: per-file caps (presign and after upload), magic-byte format
 * check after the object lands, the presign signature, and the INTERNAL
 * abuse limits.
 */

const MEDIA_MB = 1024 * 1024;

function presign($test, $user, string $name, string $mime, int $size)
{
    return $test->actingAs($user)->postJson('/api/v1/files/presign', [
        'name' => $name, 'mime_type' => $mime, 'size_bytes' => $size,
    ]);
}

describe('per-file caps at presign', function () {
    dataset('caps', [
        'free image' => ['free', 'image/jpeg', 10 * MEDIA_MB, 422, null],
        'max image' => ['max', 'image/png', 10 * MEDIA_MB, 422, null],
        'free video' => ['free', 'video/mp4', 100 * MEDIA_MB, 422, 'upgrade_pro'],
        'pro video' => ['pro', 'video/quicktime', 500 * MEDIA_MB, 422, 'upgrade_max'],
        'trial video' => ['trial', 'video/mp4', 500 * MEDIA_MB, 422, 'upgrade_max'],
    ]);

    it('accepts exactly the cap and refuses one byte more', function (string $tier, string $mime, int $cap, int $status, ?string $next) {
        $user = mediaUser($tier);

        presign($this, $user, 'ok', $mime, $cap)->assertCreated();

        $refused = presign($this, $user, 'too-big', $mime, $cap + 1)
            ->assertStatus($status)
            ->assertJson(['reason' => 'file_too_large', 'feature' => 'file_size', 'max_bytes' => $cap])
            ->assertJsonValidationErrors('size_bytes');

        expect($refused->json('next_step'))->toBe($next);
    })->with('caps');

    it('gives Max no plan cap on video, only a hidden technical ceiling', function () {
        $user = mediaUser('max');

        presign($this, $user, 'long.mp4', 'video/mp4', 3 * 1024 * MEDIA_MB)->assertCreated();

        $refused = presign($this, $user, 'huge.mp4', 'video/mp4', (int) config('media.technical_max_bytes') + 1)
            ->assertStatus(422)
            ->assertJson(['reason' => 'file_too_large']);

        // The ceiling is never shown.
        expect($refused->json())->not->toHaveKey('max_bytes')
            ->and($refused->json())->not->toHaveKey('next_step')
            ->and($refused->json('message'))->not->toContain('GB');
    });
});

describe('per-file caps after upload', function () {
    it('re-checks the real size: a video that grew past the cap is deleted and failed', function () {
        $user = mediaUser('free');
        $id = presign($this, $user, 'clip.mp4', 'video/mp4', 1_000)->assertCreated()->json('data.id');
        $file = File::query()->where('ulid', $id)->sole();

        Storage::fake('s3');
        // Real MP4 head, padded past 100 MB of declared-vs-real.
        config(['media.tiers.free.video_max_bytes' => 5_000]);
        Storage::disk('s3')->put($file->path, mediaFixture('clip.mp4'));

        $this->actingAs($user)->postJson("/api/v1/files/{$id}/complete")
            ->assertStatus(422)
            ->assertJson(['reason' => 'file_too_large', 'max_bytes' => 5_000]);

        expect(Storage::disk('s3')->exists($file->path))->toBeFalse()
            ->and($file->fresh()->status)->toBe(File::STATUS_FAILED);
    });
});

describe('the presigned PUT', function () {
    it('signs Content-Type and Content-Length, so R2 refuses any other body', function () {
        $url = presign($this, mediaUser('free'), 'a.jpg', 'image/jpeg', 12_345)->assertCreated()->json('data.upload_url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $signed = explode(';', strtolower((string) ($query['X-Amz-SignedHeaders'] ?? '')));

        expect($signed)->toContain('content-type')
            ->toContain('content-length');
    });

    it('changes the signature with the size and the type — what R2 checks the PUT against', function () {
        $sig = function (string $mime, int $bytes) {
            $url = PresignedUpload::url('s3', 'u/x/y.jpg', $mime, $bytes, now()->addMinutes(15));
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

            return $q['X-Amz-Signature'];
        };

        // Signed at the app clock, so a frozen second is the same signature
        // (it used to read time(): flaky when two calls straddled a second).
        $this->travelTo('2026-01-01 00:00:00');
        parse_str((string) parse_url(PresignedUpload::url('s3', 'u/x/y.jpg', 'image/jpeg', 100, now()->addMinutes(15)), PHP_URL_QUERY), $q);
        expect($q['X-Amz-Date'])->toBe('20251231T170000Z')
            ->and($sig('image/jpeg', 100))->toBe($sig('image/jpeg', 100))
            ->not->toBe($sig('image/jpeg', 101))
            ->not->toBe($sig('image/png', 100));
    });
});

describe('format by file signature', function () {
    dataset('accepted', [
        ['pic.jpg', 'image/jpeg', 'image/jpeg'],
        ['pic.png', 'image/png', 'image/png'],
        ['pic.gif', 'image/gif', 'image/gif'],
        ['pic.webp', 'image/webp', 'image/webp'],
        ['clip.mp4', 'video/mp4', 'video/mp4'],
        ['clip.mov', 'video/quicktime', 'video/quicktime'],
        // A phone that calls an MP4 "quicktime": same family, real type wins.
        ['clip.mp4', 'video/quicktime', 'video/mp4'],
    ]);

    it('accepts real files and stores the detected type', function (string $fixture, string $declared, string $detected) {
        $user = mediaUser('pro');
        $id = presign($this, $user, $fixture, $declared, 100)->assertCreated()->json('data.id');
        $file = File::query()->where('ulid', $id)->sole();

        Storage::fake('s3');
        Storage::disk('s3')->put($file->path, mediaFixture($fixture));

        $this->actingAs($user)->postJson("/api/v1/files/{$id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.mime_type', $detected);
    })->with('accepted');

    it('rejects a renamed .exe as .jpg, deletes the object and fails the row', function () {
        $user = mediaUser('free');
        $id = presign($this, $user, 'holiday.jpg', 'image/jpeg', 100)->assertCreated()->json('data.id');
        $file = File::query()->where('ulid', $id)->sole();

        Storage::fake('s3');
        Storage::disk('s3')->put($file->path, "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF".str_repeat("\0", 64).'This program cannot be run in DOS mode.');

        $this->actingAs($user)->postJson("/api/v1/files/{$id}/complete")
            ->assertStatus(422)
            ->assertJson(['reason' => 'unsupported_format']);

        expect(Storage::disk('s3')->exists($file->path))->toBeFalse()
            ->and($file->fresh()->status)->toBe(File::STATUS_FAILED);

        // A failed upload never shows in the library.
        $this->actingAs($user)->getJson('/api/v1/files')->assertJsonCount(0, 'data');
    });

    it('rejects bytes of the wrong family (a JPEG declared as video)', function () {
        $user = mediaUser('pro');
        $id = presign($this, $user, 'clip.mp4', 'video/mp4', 100)->assertCreated()->json('data.id');
        $file = File::query()->where('ulid', $id)->sole();

        Storage::fake('s3');
        Storage::disk('s3')->put($file->path, mediaFixture('pic.jpg'));

        $this->actingAs($user)->postJson("/api/v1/files/{$id}/complete")->assertStatus(422)->assertJson(['reason' => 'unsupported_format']);
    });

    it('knows the signatures', function () {
        expect(FileSignature::detect(mediaFixture('pic.jpg')))->toBe('image/jpeg')
            ->and(FileSignature::detect(mediaFixture('clip.mov')))->toBe('video/quicktime')
            ->and(FileSignature::detect("\0\0\0\x18ftypheic\0\0\0\0mif1heic"))->toBeNull() // HEIC is not video
            ->and(FileSignature::detect("\0\0\0\x08wide\0\0\0\x10mdat"))->toBe('video/quicktime') // classic QuickTime
            ->and(FileSignature::detect('%PDF-1.7'))->toBe('application/pdf')
            ->and(FileSignature::detect('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>'))->toBe('image/svg+xml')
            ->and(FileSignature::detect('<html><script>'))->toBeNull()
            ->and(FileSignature::detect('PK'."\x03\x04"))->toBeNull()
            ->and(FileSignature::detect(''))->toBeNull();
    });
});

describe('abuse limits (internal)', function () {
    it('stops at the daily count with a generic message', function () {
        config(['media.abuse.daily_count' => 2]);
        $user = mediaUser('pro');

        presign($this, $user, 'a.jpg', 'image/jpeg', 10)->assertCreated();
        presign($this, $user, 'b.jpg', 'image/jpeg', 10)->assertCreated();
        $refused = presign($this, $user, 'c.jpg', 'image/jpeg', 10)
            ->assertStatus(429)
            ->assertExactJson(['message' => 'Upload limit reached, try again tomorrow', 'reason' => 'upload_limit']);

        expect($refused->getContent())->not->toContain('2');
        expect(MediaUploadFlag::query()->where('user_id', $user->id)->value('last_reason'))->toBe('daily_count');

        // Tomorrow it works again.
        $this->travel(1)->days();
        presign($this, $user, 'd.jpg', 'image/jpeg', 10)->assertCreated();
    });

    it('stops at the daily bytes', function () {
        config(['media.abuse.daily_bytes' => 1_000]);
        $user = mediaUser('pro');

        presign($this, $user, 'a.jpg', 'image/jpeg', 1_000)->assertCreated();
        presign($this, $user, 'b.jpg', 'image/jpeg', 1)->assertStatus(429)->assertJson(['reason' => 'upload_limit']);
    });

    it('rate-limits presign per minute', function () {
        config(['media.abuse.presign_per_minute' => 2]);
        $user = mediaUser('pro');

        presign($this, $user, 'a.jpg', 'image/jpeg', 10)->assertCreated();
        presign($this, $user, 'b.jpg', 'image/jpeg', 10)->assertCreated();
        presign($this, $user, 'c.jpg', 'image/jpeg', 10)->assertStatus(429)->assertJson(['message' => 'Upload limit reached, try again tomorrow']);
    });

    it('flags an account that hits caps repeatedly — and never bans it', function () {
        config(['media.abuse.flag_after_hits' => 3, 'media.abuse.daily_count' => 1]);
        $user = mediaUser('free');

        presign($this, $user, 'a.jpg', 'image/jpeg', 10)->assertCreated();
        presign($this, $user, 'b.jpg', 'image/jpeg', 10)->assertStatus(429);
        presign($this, $user, 'c.jpg', 'image/jpeg', 10)->assertStatus(429);
        expect(MediaUploadFlag::query()->where('user_id', $user->id)->value('flagged_at'))->toBeNull();

        presign($this, $user, 'd.jpg', 'image/jpeg', 10)->assertStatus(429);

        $flag = MediaUploadFlag::query()->where('user_id', $user->id)->sole();
        expect($flag->flagged_at)->not->toBeNull()
            ->and($flag->hits)->toBe(3)
            ->and($user->fresh()->status->value)->toBe('active');

        // Still allowed to upload tomorrow: flagging is for review only.
        $this->travel(1)->days();
        presign($this, $user, 'e.jpg', 'image/jpeg', 10)->assertCreated();
    });
});
