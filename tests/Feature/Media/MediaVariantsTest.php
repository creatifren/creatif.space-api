<?php

use App\Http\Controllers\Api\V1\FileController;
use App\Jobs\GenerateMediaVariants;
use App\Models\File;
use App\Models\SocialAccount;
use App\Models\SocialPlanLink;
use App\Models\SocialPost;
use App\Models\User;
use App\Support\Media\ExifOrientation;
use App\Support\Media\MediaVariants;
use App\Support\PlanScope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;

/*
 * q202611141319: the client approval page and plan links loaded every
 * original (a 57-image plan pulled ~200 MB). Library images get a 480 px
 * thumb and a 1080 px feed copy (WebP + JPEG), videos a poster frame; the
 * public payloads carry them, originals stay for the viewer.
 */

beforeEach(function () {
    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('GD is not installed.');
    }
    Storage::fake('s3');
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn (string $path, DateTimeInterface $expires) => 'https://r2.test/'.$path.'?expires='.$expires->getTimestamp()
    );
});

function mvJpeg(int $w, int $h): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, (int) imagecolorallocate($img, 200, 120, 90));
    ob_start();
    imagejpeg($img, null, 90);

    return (string) ob_get_clean();
}

/** The same JPEG with an EXIF APP1 segment carrying Orientation. */
function mvWithOrientation(string $jpeg, int $orientation): string
{
    $tiff = 'MM'.pack('n', 42).pack('N', 8).pack('n', 1)
        .pack('n', 0x0112).pack('n', 3).pack('N', 1).pack('n', $orientation).pack('n', 0)
        .pack('N', 0);
    $app1 = "Exif\0\0".$tiff;

    return "\xFF\xD8\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
}

function mvFile(User $user, string $bytes, string $mime = 'image/jpeg', string $ext = 'jpg', array $attrs = []): File
{
    $ulid = (string) str()->ulid();
    $file = File::factory()->for($user)->create($attrs + [
        'ulid' => $ulid, 'path' => "u/{$user->ulid}/{$ulid}.{$ext}", 'mime_type' => $mime, 'status' => File::STATUS_READY,
        'size_bytes' => strlen($bytes), 'width' => null, 'height' => null,
    ]);
    Storage::disk('s3')->put($file->path, $bytes);

    return $file;
}

function mvSize(string $key): array
{
    $info = getimagesizefromstring((string) Storage::disk('s3')->get($key));

    return [$info[0], $info[1], $info['mime']];
}

it('makes a 480 px thumb and a 1080 px feed copy, WebP and JPEG, next to the original', function () {
    $file = mvFile(User::factory()->create(), mvJpeg(3000, 2000), attrs: ['width' => 3000, 'height' => 2000]);

    $v = MediaVariants::generate($file);

    expect($v)->toMatchArray(['v' => 1, 'webp' => MediaVariants::webpSupported(), 'thumb' => ['w' => 480, 'h' => 320], 'feed' => ['w' => 1080, 'h' => 720]])
        ->and($file->fresh()->variants['token'])->toBe($v['token']);
    $dir = dirname($file->path).'/'.pathinfo($file->path, PATHINFO_FILENAME).'.'.$v['token'];
    expect(mvSize("{$dir}.thumb.jpg"))->toBe([480, 320, 'image/jpeg'])
        ->and(mvSize("{$dir}.feed.jpg"))->toBe([1080, 720, 'image/jpeg']);
    if (MediaVariants::webpSupported()) {
        expect(mvSize("{$dir}.thumb.webp"))->toBe([480, 320, 'image/webp']);
    }
    // The original is untouched and much bigger than the thumb.
    expect(strlen((string) Storage::disk('s3')->get("{$dir}.thumb.jpg")))->toBeLessThan(strlen((string) Storage::disk('s3')->get($file->path)));
});

it('never upscales a small image', function () {
    $v = MediaVariants::generate(mvFile(User::factory()->create(), mvJpeg(300, 400)));

    expect($v['thumb'])->toBe(['w' => 300, 'h' => 400])->and($v['feed'])->toBe(['w' => 300, 'h' => 400]);
});

it('turns a phone photo upright (EXIF orientation), as the browser shows the original', function (int $orientation, array $thumb) {
    $bytes = mvWithOrientation(mvJpeg(1200, 800), $orientation);
    expect(ExifOrientation::fromJpeg($bytes))->toBe($orientation);

    expect(MediaVariants::generate(mvFile(User::factory()->create(), $bytes))['thumb'])->toBe($thumb);
})->with([
    'as stored' => [1, ['w' => 480, 'h' => 320]],
    'rotated 90' => [6, ['w' => 480, 'h' => 720]],
    'rotated 270' => [8, ['w' => 480, 'h' => 720]],
    'upside down' => [3, ['w' => 480, 'h' => 320]],
]);

it('handles PNG (transparency lands on white in the JPEG), GIF and WebP originals', function (string $fixture, string $mime) {
    $v = MediaVariants::generate(mvFile(User::factory()->create(), mediaFixture($fixture), $mime, pathinfo($fixture, PATHINFO_EXTENSION)));

    expect($v['thumb'])->toBe(['w' => 16, 'h' => 16])->and($v)->not->toHaveKey('error');
})->with([['pic.png', 'image/png'], ['pic.gif', 'image/gif'], ['pic.webp', 'image/webp']]);

it('records an unreadable file once and leaves the page on the original', function () {
    $file = mvFile(User::factory()->create(), 'not an image at all');

    expect(MediaVariants::generate($file))->toBe(['v' => 1, 'error' => 'unreadable'])
        ->and(MediaVariants::urls($file->fresh()))->toBeNull()
        ->and(MediaVariants::settled($file->fresh()))->toBeTrue();
});

it('makes a poster frame (and its thumb) for a video when ffmpeg is there', function () {
    $ffmpeg = (new ExecutableFinder)->find('ffmpeg');
    if ($ffmpeg === null) {
        $this->markTestSkipped('ffmpeg is not installed.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'clip').'.mp4';
    exec(escapeshellarg($ffmpeg).' -loglevel error -y -f lavfi -i color=c=red:s=1920x1080:d=1 -pix_fmt yuv420p '.escapeshellarg($tmp));
    $file = mvFile(User::factory()->create(), (string) file_get_contents($tmp), 'video/mp4', 'mp4');
    @unlink($tmp);
    // ffmpeg reads the original through a (here: local) URL.
    Storage::disk('s3')->buildTemporaryUrlsUsing(fn (string $path) => Storage::disk('s3')->path($path));

    $v = MediaVariants::generate($file);

    expect($v['poster'])->toBe(['w' => 1080, 'h' => 608])
        ->and($v['thumb'])->toBe(['w' => 480, 'h' => 270])
        ->and($v)->not->toHaveKey('feed');
});

it('upload complete queues the variants job for images and videos only', function () {
    Queue::fake();
    $user = User::factory()->create();
    $file = File::factory()->for($user)->pending()->create(['size_bytes' => 999]);
    Storage::disk('s3')->put($file->path, mediaFixture('pic.jpg'));

    $this->actingAs($user)->postJson("/api/v1/files/{$file->ulid}/complete")->assertOk();

    Queue::assertPushed(GenerateMediaVariants::class, fn ($job) => $job->fileId === $file->id);
});

it('upload complete makes the variants (sync queue) and a rerun of the job is a no-op', function () {
    $user = User::factory()->create();
    $file = File::factory()->for($user)->pending()->create(['size_bytes' => 999]);
    Storage::disk('s3')->put($file->path, mediaFixture('pic.jpg'));

    $this->actingAs($user)->postJson("/api/v1/files/{$file->ulid}/complete")->assertOk();
    $token = $file->fresh()->variants['token'];

    (new GenerateMediaVariants($file->id))->handle();
    expect($file->fresh()->variants['token'])->toBe($token);
});

it('a new version: back to the original at once, new variants under a new token, old objects removed', function () {
    $file = mvFile(User::factory()->create(), mvJpeg(1200, 800));
    $old = MediaVariants::generate($file);
    $oldThumb = MediaVariants::key($file, $old['token'], 'thumb', 'jpg');
    Storage::disk('s3')->put($file->path, mvJpeg(800, 1200));
    Queue::fake();

    MediaVariants::invalidate($file);

    expect(MediaVariants::urls($file->fresh()))->toBeNull();
    Queue::assertPushed(GenerateMediaVariants::class);
    $new = MediaVariants::generate($file->fresh());
    expect($new['token'])->not->toBe($old['token'])
        ->and($new['thumb'])->toBe(['w' => 480, 'h' => 720])
        ->and(Storage::disk('s3')->exists($oldThumb))->toBeFalse()
        ->and(Storage::disk('s3')->exists(MediaVariants::key($file, $new['token'], 'thumb', 'jpg')))->toBeTrue();
});

it('purging the file removes its variants', function () {
    $file = mvFile(User::factory()->create(), mvJpeg(1200, 800));
    $v = MediaVariants::generate($file);

    FileController::purge($file->fresh());

    expect(Storage::disk('s3')->allFiles())->toBe([]);
    expect(File::withTrashed()->find($file->id))->toBeNull()
        ->and($v)->toHaveKey('token');
});

it('media:variants --dry-run counts, the real run makes them, a rerun has nothing left', function () {
    $user = User::factory()->create();
    mvFile($user, mvJpeg(900, 900));
    mvFile($user, mvJpeg(900, 600));
    mvFile($user, 'broken');
    File::factory()->for($user)->create(['mime_type' => 'application/pdf', 'status' => File::STATUS_READY]);
    File::factory()->for($user)->pending()->create(['mime_type' => 'image/jpeg']);

    Artisan::call('media:variants', ['--dry-run' => true]);
    expect(trim(Artisan::output()))->toBe('3 files need variants (dry run: nothing was written).')
        ->and(File::query()->whereNotNull('variants')->count())->toBe(0);

    Artisan::call('media:variants');
    expect(trim(Artisan::output()))->toBe('3 of 3 files processed, 1 left on the original (unreadable, too large, or no video tool).');

    Artisan::call('media:variants', ['--dry-run' => true]);
    expect(trim(Artisan::output()))->toStartWith('0 files need variants');
});

it('signed variant URLs stay the same within a 6-hour window (browser cache), then move on', function () {
    config(['filesystems.disks.s3' => [...config('filesystems.disks.s3'), 'driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'auto', 'bucket' => 'b', 'endpoint' => 'https://r2.test']]);
    Storage::forgetDisk('s3');
    $file = File::factory()->for(User::factory()->create())->create(['variants' => ['v' => 1, 'token' => 'abcd1234', 'webp' => true, 'thumb' => ['w' => 480, 'h' => 320], 'feed' => ['w' => 1080, 'h' => 720]]]);
    $this->travelTo(now()->startOfDay()->addHour());

    $first = MediaVariants::urls($file);
    $this->travel(2)->hours();
    $again = MediaVariants::urls($file);
    $this->travel(5)->hours();
    $later = MediaVariants::urls($file);

    expect($first['thumb']['webp'])->toBe($again['thumb']['webp'])
        ->toContain('.abcd1234.thumb.webp')
        ->and($first['thumb']['jpeg'])->toContain('.abcd1234.thumb.jpg')
        ->and($later['thumb']['webp'])->not->toBe($first['thumb']['webp'])
        ->and($first['poster'])->toBeNull();
});

describe('public payloads', function () {
    function mvPost(User $owner, array $media): SocialPost
    {
        $account = SocialAccount::factory()->for($owner)->create(['platform' => 'instagram', 'username' => 'brand']);
        $post = SocialPost::factory()->for($owner)->create([
            'provider_post_id' => null, 'status' => 'scheduled', 'approval' => 'client',
            'scheduled_at' => now()->addDays(3), 'caption' => 'Hello', 'media' => $media,
        ]);
        $post->targets()->create(['social_account_id' => $account->id, 'status' => 'pending']);

        return $post;
    }

    it('media items carry thumb_url / feed_url / poster_url with their sizes; originals stay in url', function () {
        $owner = User::factory()->create();
        $image = mvFile($owner, mvJpeg(2000, 2500), attrs: ['width' => 2000, 'height' => 2500]);
        MediaVariants::generate($image);
        $cover = mvFile($owner, mvJpeg(1080, 1920));
        MediaVariants::generate($cover);
        $video = mvFile($owner, 'video bytes', 'video/mp4', 'mp4');
        $plain = mvFile($owner, mvJpeg(10, 10));
        $post = mvPost($owner, [
            ['url' => "https://bucket.r2.test/{$image->path}?X-Amz-Signature=x"],
            ['url' => "https://bucket.r2.test/{$video->path}?X-Amz-Signature=x", 'thumbnail_url' => "https://bucket.r2.test/{$cover->path}?sig=y"],
            ['url' => "https://bucket.r2.test/{$plain->path}?X-Amz-Signature=x"],
            ['url' => 'https://cdn.test/elsewhere.jpg'],
        ]);

        [$img, $vid, $none, $external] = PlanScope::media(collect([$post->fresh()]))[$post->id];

        $ext = MediaVariants::webpSupported() ? 'webp' : 'jpg';
        expect($img['url'])->toBe("https://bucket.r2.test/{$image->path}?X-Amz-Signature=x")
            ->and($img['thumb_url'])->toContain(".thumb.{$ext}")
            ->and($img['feed_url'])->toContain(".feed.{$ext}")
            ->and($img['poster_url'])->toBeNull()
            ->and($img['variants']['thumb'])->toMatchArray(['width' => 480, 'height' => 600])
            ->and($img['variants']['thumb']['jpeg'])->toContain('.thumb.jpg')
            ->and($img['variants']['feed'])->toMatchArray(['width' => 1080, 'height' => 1350]);
        // A video's chosen cover wins over a frame; the video file itself is only for play.
        expect($vid['type'])->toBe('video')
            ->and($vid['poster_url'])->toContain(pathinfo($cover->path, PATHINFO_FILENAME))->toContain(".feed.{$ext}")
            ->and($vid['thumb_url'])->toContain(pathinfo($cover->path, PATHINFO_FILENAME))
            ->and($vid['feed_url'])->toBeNull();
        // No variants yet / not a library file: null, the page uses url.
        expect($none['thumb_url'])->toBeNull()->and($external['thumb_url'])->toBeNull()
            ->and($external['variants'])->toBe(['thumb' => null, 'feed' => null, 'poster' => null]);
    });

    it('the plan link payload carries the same media_items (media unchanged)', function () {
        $owner = User::factory()->create();
        $image = mvFile($owner, mvJpeg(2000, 2000));
        MediaVariants::generate($image);
        $media = [['url' => "https://bucket.r2.test/{$image->path}?X-Amz-Signature=x"]];
        mvPost($owner, $media);
        $link = SocialPlanLink::create(['user_id' => $owner->id, 'title' => 'Next week', 'starts_at' => now(), 'ends_at' => now()->addWeek()]);

        $post = $this->getJson("/api/v1/plan/{$link->token}")->assertOk()->json('data.posts.0');

        expect($post['media'])->toBe($media)
            ->and($post['media_items'][0]['thumb_url'])->toContain('.thumb.')
            ->and($post['media_items'][0]['variants']['feed'])->toMatchArray(['width' => 1080, 'height' => 1080]);
    });
});
