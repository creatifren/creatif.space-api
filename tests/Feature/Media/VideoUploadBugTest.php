<?php

use App\Models\File;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OutstandService;
use App\Support\Credits;
use App\Support\PublishPreflight;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * Rule 7 — the video upload bug.
 *
 * A video picked on a phone often has no extension in its name ("IMG_4021",
 * "video"). The object key took its extension from that name, so the key
 * had none; everything downstream reads the type from the URL:
 *  - Outstand gets a filename per media item and named every extension-less
 *    file "media-N.jpg", so a video went out as a JPEG and the network
 *    refused it;
 *  - the publish preflight saw "unknown", so YouTube refused before sending
 *    ("YouTube only takes video").
 * The key now takes its extension from the MIME, and files already stored
 * without one are named from their MIME.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'credits_monthly' => 0]]);
    }
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
    ]);
});

/** Presign -> PUT (fake R2) -> complete, as the app does it. */
function uploadThrough($test, User $user, string $name, string $mime, string $bytes): File
{
    $id = $test->actingAs($user)->postJson('/api/v1/files/presign', [
        'name' => $name, 'mime_type' => $mime, 'size_bytes' => strlen($bytes),
    ])->assertCreated()->json('data.id');

    $file = File::query()->where('ulid', $id)->sole();
    Storage::fake('s3');
    Storage::disk('s3')->put($file->path, $bytes);

    $test->actingAs($user)->postJson("/api/v1/files/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'ready');
    // Back to the real (offline-signing) driver for the URLs a post carries.
    Storage::forgetDisk('s3');

    return $file->fresh();
}

dataset('videos', [
    'MOV from an iPhone, no extension' => ['IMG_4021', 'video/quicktime', 'clip.mov', 'mov'],
    'MP4 from Android, no extension' => ['video', 'video/mp4', 'clip.mp4', 'mp4'],
    'MP4 with its extension' => ['clip.mp4', 'video/mp4', 'clip.mp4', 'mp4'],
    'MOV with an upper-case extension' => ['Holiday.MOV', 'video/quicktime', 'clip.mov', 'mov'],
]);

it('uploads a real video and publishes it as a video', function (string $name, string $mime, string $fixture, string $ext) {
    // Library media goes out from Outstand's media host (upload → PUT → confirm) before the post is created.
    Http::fake([
        'api.outstand.test/v1/media/upload' => Http::response(['success' => true, 'data' => ['id' => 'm1', 'upload_url' => 'https://outstand-media.test/put/m1', 'expires_in' => 3600]]),
        'outstand-media.test/*' => Http::response('', 200),
        'api.outstand.test/v1/media/m1/confirm' => Http::response(['success' => true, 'data' => ['id' => 'm1', 'url' => 'https://media.outstand.so/org/m1/clip', 'status' => 'active']]),
        'api.outstand.test/*' => Http::response(['success' => true, 'post' => ['id' => 'v1', 'publishedAt' => null]]),
    ]);

    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, 30_000, 'Test');
    $yt = SocialAccount::factory()->for($user)->create(['platform' => 'youtube', 'provider' => 'outstand']);

    $file = uploadThrough($this, $user, $name, $mime, mediaFixture($fixture));

    expect($file->path)->toEndWith(".{$ext}")
        ->and($file->mime_type)->toBe($mime);

    // The bytes are read from storage for the copy; the post still carries a signed R2 link until then.
    Storage::fake('s3');
    Storage::disk('s3')->put($file->path, mediaFixture($fixture));
    Storage::disk('s3')->buildTemporaryUrlsUsing(fn (string $path) => 'https://bucket.example.r2.cloudflarestorage.com/'.$path.'?X-Amz-Signature=abc');

    $this->actingAs($user)->postJson('/api/v1/social-posts', [
        'caption' => 'Behind the scenes',
        'account_ids' => [$yt->ulid],
        'file_ids' => [$file->ulid],
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated();

    Http::assertSent(function (HttpRequest $r) use ($ext) {
        $media = $r['containers'][0]['media'][0] ?? [];

        return str_ends_with($r->url(), '/posts') && str_ends_with((string) ($media['filename'] ?? ''), ".{$ext}")
            && ($media['url'] ?? null) === 'https://media.outstand.so/org/m1/clip';
    });
})->with('videos');

it('still names a video stored before the fix (no extension in its key) as a video', function () {
    $user = User::factory()->create();
    $legacy = File::factory()->for($user)->create([
        'path' => 'u/'.$user->ulid.'/01JLEGACYVIDE0000000000000',
        'ulid' => '01JLEGACYVIDE0000000000000',
        'mime_type' => 'video/quicktime',
    ]);
    $url = 'https://bucket.example.r2.cloudflarestorage.com/'.$legacy->path.'?X-Amz-Signature=abc';

    expect(PublishPreflight::kind($url))->toBe('video');

    $filename = (new ReflectionMethod(OutstandService::class, 'filename'))->invoke(null, $url, 0);
    expect($filename)->toBe('01JLEGACYVIDE0000000000000.mov');
});
