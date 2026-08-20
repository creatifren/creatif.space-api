<?php

use App\Jobs\PruneStaleUploads;
use App\Models\File;
use Illuminate\Support\Facades\Storage;

it('deletes pending uploads older than a day, object and row', function () {
    Storage::fake('s3');

    $stale = File::factory()->pending()->create();
    Storage::disk('s3')->put($stale->path, 'orphaned-bytes');

    $this->travel(25)->hours();

    (new PruneStaleUploads)->handle();

    expect(File::query()->count())->toBe(0);
    Storage::disk('s3')->assertMissing($stale->path);
});

it('leaves fresh pending uploads and ready files alone', function () {
    Storage::fake('s3');

    $fresh = File::factory()->pending()->create();
    $ready = File::factory()->create();
    Storage::disk('s3')->put($ready->path, 'bytes');

    $this->travel(25)->hours();
    $old = File::factory()->pending()->create(); // created "now", 25h in

    (new PruneStaleUploads)->handle();

    expect(File::query()->pluck('id')->all())
        ->toContain($ready->id)
        ->toContain($old->id)
        ->not->toContain($fresh->id);
    Storage::disk('s3')->assertExists($ready->path);
});
