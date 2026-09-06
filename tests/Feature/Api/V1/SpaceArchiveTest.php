<?php

use App\Jobs\BuildSpaceArchive;
use App\Jobs\PruneSpaceArchives;
use App\Models\File;
use App\Models\Handle;
use App\Models\Space;
use App\Models\SpaceArchive;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function archivableSpace(int $files = 2, array $settings = []): Space
{
    Storage::fake('s3');
    $user = User::factory()->create();
    Handle::factory()->for($user)->create(['name' => 'rani']);
    $space = Space::factory()->for($user)->published()->create([
        'slug' => 'winter-noel',
        'title' => 'Winter Noel',
        'settings' => array_merge(Space::defaultSettings(), $settings),
    ]);

    for ($i = 0; $i < $files; $i++) {
        $file = File::factory()->for($user)->create(['name' => "shot-$i.jpg", 'size_bytes' => 10]);
        Storage::disk('s3')->put($file->path, "bytes of $i");
        $space->items()->create(['file_id' => $file->id, 'sort_order' => $i]);
    }

    return $space;
}

const ARCHIVE_URL = '/api/v1/profiles/rani/spaces/winter-noel/archive';

describe('asking for the archive', function () {
    it('starts one build and answers pending, then reuses it', function () {
        Queue::fake();
        archivableSpace();

        $this->getJson(ARCHIVE_URL)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->getJson(ARCHIVE_URL)->assertOk()->assertJsonPath('data.status', 'pending');

        // Two presses, one job: the second is a poll, not a second build.
        Queue::assertPushed(BuildSpaceArchive::class, 1);
        expect(SpaceArchive::count())->toBe(1);
    });

    it('builds again once the Space changes', function () {
        Queue::fake();
        $space = archivableSpace();
        $this->getJson(ARCHIVE_URL)->assertOk();

        $extra = File::factory()->for($space->user)->create();
        $space->items()->create(['file_id' => $extra->id, 'sort_order' => 9]);

        /* A new photo changes the signature: the visitor gets a fresh
           build, not yesterday's archive missing a file. */
        $this->getJson(ARCHIVE_URL)->assertOk();
        expect(SpaceArchive::count())->toBe(2);
    });

    it('does not reuse a failed build', function () {
        Queue::fake();
        $space = archivableSpace();
        SpaceArchive::create([
            'space_id' => $space->id,
            'signature' => SpaceArchive::signatureFor($space),
            'status' => SpaceArchive::STATUS_FAILED,
            'failure_reason' => 'build_failed',
        ]);

        $this->getJson(ARCHIVE_URL)->assertOk()->assertJsonPath('data.status', 'pending');
        Queue::assertPushed(BuildSpaceArchive::class, 1);
    });

    it('is behind the same three gates as the page', function () {
        Queue::fake();
        $space = archivableSpace(2, ['allow_download' => false]);
        $this->getJson(ARCHIVE_URL)->assertForbidden();

        $space->forceFill(['expires_at' => now()->subDay()])->save();
        $this->getJson(ARCHIVE_URL)->assertStatus(410);

        Queue::assertNothingPushed();
    });
});

describe('building it', function () {
    it('zips every file, streams it to the bucket, and signs the link', function () {
        $space = archivableSpace(3);
        $archive = SpaceArchive::create([
            'space_id' => $space->id,
            'signature' => SpaceArchive::signatureFor($space),
        ]);

        (new BuildSpaceArchive($archive))->handle();

        $archive->refresh();
        expect($archive->status)->toBe(SpaceArchive::STATUS_READY)
            ->and($archive->expires_at?->isFuture())->toBeTrue()
            ->and($archive->url())->toContain('expir');
        Storage::disk('s3')->assertExists($archive->path);

        // The zip is real and holds the three files under their own names.
        $zip = new ZipArchive;
        $tmp = tempnam(sys_get_temp_dir(), 'cs');
        file_put_contents($tmp, Storage::disk('s3')->get($archive->path));
        expect($zip->open($tmp))->toBeTrue()
            ->and($zip->numFiles)->toBe(3)
            ->and($zip->getNameIndex(0))->toBe('shot-0.jpg');
        $zip->close();
        unlink($tmp);

        // And nothing is left on local disk.
        expect(is_dir(storage_path('app/private/archives/'.$archive->ulid)))->toBeFalse();
    });

    it('keeps both files when two share a name', function () {
        $space = archivableSpace(0);
        foreach ([0, 1] as $i) {
            $file = File::factory()->for($space->user)->create(['name' => 'same.jpg', 'size_bytes' => 5]);
            Storage::disk('s3')->put($file->path, "copy $i");
            $space->items()->create(['file_id' => $file->id, 'sort_order' => $i]);
        }
        $archive = SpaceArchive::create(['space_id' => $space->id, 'signature' => 'x']);

        (new BuildSpaceArchive($archive))->handle();

        $zip = new ZipArchive;
        $tmp = tempnam(sys_get_temp_dir(), 'cs');
        file_put_contents($tmp, Storage::disk('s3')->get($archive->fresh()->path));
        $zip->open($tmp);
        expect($zip->numFiles)->toBe(2)
            ->and($zip->getNameIndex(1))->toBe('same (2).jpg');
        $zip->close();
        unlink($tmp);
    });

    it('refuses a Space over the size ceiling rather than filling the disk', function () {
        $space = archivableSpace(1);
        $space->items()->first()->file->update(['size_bytes' => SpaceArchive::MAX_BYTES + 1]);
        $archive = SpaceArchive::create(['space_id' => $space->id, 'signature' => 'x']);

        (new BuildSpaceArchive($archive))->handle();

        expect($archive->fresh()->status)->toBe(SpaceArchive::STATUS_FAILED)
            ->and($archive->fresh()->failure_reason)->toBe('too_large');
        Storage::disk('s3')->assertMissing('archives/'.$space->ulid.'/'.$archive->ulid.'.zip');
    });
});

it('prunes built archives after their day, object and row', function () {
    Storage::fake('s3');
    $space = archivableSpace(0);
    Storage::disk('s3')->put('archives/old.zip', 'zip');
    $old = SpaceArchive::create([
        'space_id' => $space->id, 'signature' => 'a', 'status' => 'ready',
        'path' => 'archives/old.zip', 'expires_at' => now()->subMinute(),
    ]);
    $fresh = SpaceArchive::create([
        'space_id' => $space->id, 'signature' => 'b', 'status' => 'ready',
        'path' => 'archives/fresh.zip', 'expires_at' => now()->addHour(),
    ]);

    (new PruneSpaceArchives)->handle();

    expect(SpaceArchive::pluck('id')->all())->toBe([$fresh->id]);
    Storage::disk('s3')->assertMissing('archives/old.zip');
});
