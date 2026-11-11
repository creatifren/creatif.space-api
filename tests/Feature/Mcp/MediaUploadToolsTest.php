<?php

use App\Http\Controllers\Api\V1\FileController;
use App\Models\File;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
 * The routes behind the MCP media tools, reached with a bearer token:
 * request_media_upload → POST /files/presign, confirm_media_upload →
 * POST /files/{ulid}/complete (+ GET /files/{ulid}), then the file id in
 * draft_post / update_post. Same rules as the app: Free 1 GB, per-file
 * caps, accepted formats, magic bytes.
 */

beforeEach(function () {
    ensurePassportKeys();
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
    ]);
    Http::fake();
});

function mediaToolKey(TestCase $test, User $user, array $scopes = ['read', 'draft']): string
{
    $key = $test->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Media key', 'scopes' => $scopes])
        ->assertCreated()
        ->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

function mediaToolPresign(TestCase $test, string $key, string $name, string $mime, int $size)
{
    return bearer($test, $key)->postJson('/api/v1/files/presign', [
        'name' => $name, 'mime_type' => $mime, 'size_bytes' => $size,
    ]);
}

it('uploads through a token: presign, PUT, complete, then attach to a draft and an update', function () {
    $user = mediaUser('free');
    $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
    $key = mediaToolKey($this, $user);

    $presign = mediaToolPresign($this, $key, 'pic.jpg', 'image/jpeg', 100)
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'upload_url', 'headers' => ['Content-Type'], 'expires_at']]);
    $id = $presign->json('data.id');
    $file = File::query()->where('ulid', $id)->sole();
    expect($file->status)->toBe(File::STATUS_PENDING)
        ->and($file->user_id)->toBe($user->id);

    Storage::fake('s3');
    Storage::disk('s3')->put($file->path, mediaFixture('pic.jpg'));

    bearer($this, $key)->postJson("/api/v1/files/{$id}/complete")
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.mime_type', 'image/jpeg');

    // confirm_media_upload asks again after a 409: the file reads back as ready.
    bearer($this, $key)->postJson("/api/v1/files/{$id}/complete")->assertStatus(409);
    bearer($this, $key)->getJson("/api/v1/files/{$id}")->assertOk()->assertJsonPath('data.status', 'ready');

    bearer($this, $key)->postJson('/api/v1/social-posts', [
        'caption' => 'With the upload',
        'account_ids' => [$ig->ulid],
        'file_ids' => [$id],
        'draft' => true,
    ])->assertCreated();

    $post = SocialPost::query()->sole();
    expect($post->media)->toHaveCount(1)
        ->and($post->media[0]['url'])->toContain($file->path)
        ->and($file->posts()->pluck('social_posts.id')->all())->toBe([$post->id]);

    // update_post: file_ids replace the post's media.
    $second = mediaFile($user);
    bearer($this, $key)->patchJson("/api/v1/social-posts/{$post->ulid}", [
        'file_ids' => [$second->ulid, $id],
    ])->assertOk();

    $media = $post->fresh()->media;
    expect($media)->toHaveCount(2)
        ->and($media[0]['url'])->toContain($second->path)
        ->and($media[1]['url'])->toContain($file->path);
});

it('needs the draft scope to upload', function () {
    $user = mediaUser('free');
    $key = mediaToolKey($this, $user, ['read']);

    mediaToolPresign($this, $key, 'pic.jpg', 'image/jpeg', 100)
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope');

    expect(File::query()->count())->toBe(0);
});

it('refuses a Free upload over 1 GB with the upgrade and free-up-space links', function () {
    $user = mediaUser('free');
    mediaFile($user, ['size_bytes' => MEDIA_GB - 50, 'created_at' => now()->subDays(2)]);
    $key = mediaToolKey($this, $user);

    mediaToolPresign($this, $key, 'over.jpg', 'image/jpeg', 100)
        ->assertStatus(402)
        ->assertJson([
            'reason' => 'quota_exceeded',
            'feature' => 'media_quota',
            'next_step' => 'upgrade_pro',
            'billing_url' => rtrim((string) config('app.frontend_url'), '/').'/settings?section=subscription',
            'files_url' => rtrim((string) config('app.frontend_url'), '/').'/files',
        ]);

    expect(File::query()->where('status', File::STATUS_PENDING)->count())->toBe(0);
});

it('refuses a paid Keep quota overflow with files_url and no upgrade link on Max', function () {
    $user = mediaUser('max');
    mediaFile($user, ['size_bytes' => 50 * MEDIA_GB, 'kept_at' => now(), 'created_at' => now()->subDays(2)]);
    $key = mediaToolKey($this, $user);

    $refused = mediaToolPresign($this, $key, 'over.jpg', 'image/jpeg', 100)
        ->assertStatus(402)
        ->assertJson(['reason' => 'quota_exceeded', 'feature' => 'keep_quota', 'next_step' => 'contact_sales']);

    expect($refused->json())->toHaveKey('files_url')
        ->and($refused->json())->not->toHaveKey('billing_url');
});

it('refuses a format outside the list with a message that says what to do', function () {
    $key = mediaToolKey($this, mediaUser('free'));

    mediaToolPresign($this, $key, 'archive.zip', 'application/zip', 100)
        ->assertUnprocessable()
        ->assertJsonPath('errors.mime_type.0', FileController::REFUSED_FORMAT);

    expect(File::query()->count())->toBe(0);
});

it('refuses bytes that are not the declared format at complete, and fails the row', function () {
    $user = mediaUser('free');
    $key = mediaToolKey($this, $user);
    $id = mediaToolPresign($this, $key, 'holiday.jpg', 'image/jpeg', 100)->assertCreated()->json('data.id');
    $file = File::query()->where('ulid', $id)->sole();

    Storage::fake('s3');
    Storage::disk('s3')->put($file->path, "MZ\x90\x00\x03\x00\x00\x00".str_repeat("\0", 64));

    bearer($this, $key)->postJson("/api/v1/files/{$id}/complete")
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'unsupported_format');

    expect($file->fresh()->status)->toBe(File::STATUS_FAILED)
        ->and(Storage::disk('s3')->exists($file->path))->toBeFalse();
});

it('says the upload never arrived when complete comes before the PUT', function () {
    $user = mediaUser('free');
    $key = mediaToolKey($this, $user);
    $id = mediaToolPresign($this, $key, 'pic.jpg', 'image/jpeg', 100)->assertCreated()->json('data.id');

    Storage::fake('s3');

    bearer($this, $key)->postJson("/api/v1/files/{$id}/complete")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);

    expect(File::query()->where('ulid', $id)->value('status'))->toBe(File::STATUS_PENDING);
});

it('refuses an unconfirmed upload in draft_post and update_post', function () {
    $user = mediaUser('free');
    $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
    $key = mediaToolKey($this, $user);
    $id = mediaToolPresign($this, $key, 'pic.jpg', 'image/jpeg', 100)->assertCreated()->json('data.id');

    bearer($this, $key)->postJson('/api/v1/social-posts', [
        'caption' => 'Too soon',
        'account_ids' => [$ig->ulid],
        'file_ids' => [$id],
        'draft' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors(['file_ids']);
    expect(SocialPost::query()->count())->toBe(0);

    $post = SocialPost::factory()->for($user)->create(['status' => 'draft', 'provider_post_id' => null, 'scheduled_at' => null, 'media' => []]);
    bearer($this, $key)->patchJson("/api/v1/social-posts/{$post->ulid}", ['file_ids' => [$id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file_ids']);
    expect($post->fresh()->media)->toBe([]);
});

it('treats another workspace\'s upload as not found', function () {
    $owner = mediaUser('free');
    $ownerKey = mediaToolKey($this, $owner);
    $id = mediaToolPresign($this, $ownerKey, 'pic.jpg', 'image/jpeg', 100)->assertCreated()->json('data.id');
    $file = File::query()->where('ulid', $id)->sole();
    Storage::fake('s3');
    Storage::disk('s3')->put($file->path, mediaFixture('pic.jpg'));

    $stranger = mediaUser('free');
    $ig = SocialAccount::factory()->for($stranger)->create(['platform' => 'instagram']);
    $strangerKey = mediaToolKey($this, $stranger);

    bearer($this, $strangerKey)->postJson("/api/v1/files/{$id}/complete")->assertNotFound();
    bearer($this, $strangerKey)->getJson("/api/v1/files/{$id}")->assertNotFound();
    expect($file->fresh()->status)->toBe(File::STATUS_PENDING);

    // Even once the owner confirms it, the stranger cannot attach it.
    bearer($this, $ownerKey)->postJson("/api/v1/files/{$id}/complete")->assertOk();
    bearer($this, $strangerKey)->postJson('/api/v1/social-posts', [
        'caption' => 'Not mine',
        'account_ids' => [$ig->ulid],
        'file_ids' => [$id],
        'draft' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors(['file_ids']);
});
