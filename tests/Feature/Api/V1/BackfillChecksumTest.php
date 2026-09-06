<?php

use App\Jobs\BackfillFileChecksums;
use App\Models\File;
use Illuminate\Support\Facades\Storage;

it('fills in checksums for files stored before the upload path recorded one', function () {
    Storage::fake('s3');
    $old = File::factory()->create(['checksum' => null]);
    Storage::disk('s3')->put($old->path, 'legacy bytes');

    (new BackfillFileChecksums)->handle();

    expect($old->fresh()->checksum)->toBe(md5('legacy bytes'));
});

it('leaves a file whose object is gone alone, and keeps going', function () {
    Storage::fake('s3');
    $missing = File::factory()->create(['checksum' => null]);
    $present = File::factory()->create(['checksum' => null]);
    Storage::disk('s3')->put($present->path, 'here');

    (new BackfillFileChecksums)->handle();

    /* One unreachable object must not strand the rest of the batch. It
       stays null and the next run tries again. */
    expect($missing->fresh()->checksum)->toBeNull()
        ->and($present->fresh()->checksum)->toBe(md5('here'));
});

it('never touches a checksum that is already there', function () {
    Storage::fake('s3');
    $file = File::factory()->create(['checksum' => 'from-drive']);
    Storage::disk('s3')->put($file->path, 'different bytes');

    (new BackfillFileChecksums)->handle();

    expect($file->fresh()->checksum)->toBe('from-drive');
});
