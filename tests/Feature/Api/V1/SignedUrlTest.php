<?php

use App\Models\File;
use Illuminate\Support\Facades\Storage;

/* Every URL these tests look at is produced by the fake disk, whose signed
   URLs carry `?expiration=` where R2's carry `X-Amz-Expires`. So the thing
   asserted is the property that matters and holds on both: the URL is
   time-limited, and it is not the bucket's own permanent address. */

it('serves files through a URL that expires, not the bucket address', function () {
    Storage::fake('s3');
    $file = File::factory()->create();
    Storage::disk('s3')->put($file->path, 'bytes');

    $signed = $file->url();
    $permanent = Storage::disk('s3')->url($file->path);

    /* The whole point: a URL somebody keeps stops working. Without this
       every gate the backend has — Space password, expiry, the Trash,
       allow_download — guards the page and not the bytes. */
    expect($signed)->not->toBe($permanent)
        ->and($signed)->toContain('expir');
});

it('signs a version the same way', function () {
    Storage::fake('s3');
    $file = File::factory()->create();
    $version = $file->versions()->create([
        'number' => 1,
        'path' => 'v/old/'.str()->ulid().'.jpg',
        'size_bytes' => 10,
    ]);
    Storage::disk('s3')->put($version->path, 'old bytes');

    expect($version->url())->not->toBe(Storage::disk($version->disk)->url($version->path));
});

it('expires within the stated window, not indefinitely', function () {
    Storage::fake('s3');
    $file = File::factory()->create();
    Storage::disk('s3')->put($file->path, 'bytes');

    parse_str((string) parse_url($file->url(), PHP_URL_QUERY), $query);

    /* The fake puts the absolute timestamp in `expiration`; whatever the
       driver calls it, it must land on the 24-hour mark rather than years
       out — a signature good for a year is a public URL with extra steps. */
    expect((int) ($query['expiration'] ?? 0))
        ->toBeGreaterThan(now()->addHours(File::URL_TTL_HOURS)->subMinute()->timestamp)
        ->toBeLessThan(now()->addHours(File::URL_TTL_HOURS)->addMinute()->timestamp);
});

it('asks for the download to arrive as an attachment under its own name', function () {
    /* The fake ignores per-request options, so this checks the argument we
       hand the driver rather than the string it returns — R2 is what turns
       ResponseContentDisposition into a Content-Disposition header. */
    $file = File::factory()->make(['name' => 'winter noel.jpg']);

    Storage::shouldReceive('disk')->once()->andReturnSelf();
    Storage::shouldReceive('temporaryUrl')->once()->withArgs(
        fn ($path, $expiry, $options) => $options['ResponseContentDisposition']
            === 'attachment; filename="winter noel.jpg"',
    )->andReturn('https://example.test/signed');

    expect($file->downloadUrl())->toBe('https://example.test/signed');
});
