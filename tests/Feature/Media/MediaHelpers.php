<?php

use App\Enums\SocialPostStatus;
use App\Models\File;
use App\Models\Plan;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Media\MediaRetention;

const MEDIA_GB = 1024 * 1024 * 1024;

/** A user on a media tier: free | pro | max | trial (a Max trial — still Pro). */
function mediaUser(string $tier = 'free'): User
{
    $user = User::factory()->create();

    if ($tier !== 'free') {
        Subscription::factory()->for($user)->create([
            'plan_id' => Plan::query()->where('key', $tier === 'trial' ? 'max' : $tier)->value('id'),
            'status' => $tier === 'trial' ? 'trialing' : 'active',
        ]);
    }

    MediaRetention::flush();

    return $user;
}

/** A ready library file whose key follows File::keyFor. */
function mediaFile(User $user, array $attributes = []): File
{
    $ulid = (string) str()->ulid();
    $mime = $attributes['mime_type'] ?? 'image/jpeg';
    $ext = match ($mime) {
        'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'application/pdf' => 'pdf', 'image/svg+xml' => 'svg', default => 'jpg',
    };

    return File::factory()->for($user)->create($attributes + [
        'ulid' => $ulid,
        'path' => "u/{$user->ulid}/{$ulid}.{$ext}",
        'mime_type' => $mime,
        'size_bytes' => 1_000,
    ]);
}

/** The signed URL shape the composer sends for a library file. */
function mediaUrl(File $file): string
{
    return 'https://bucket.example.r2.cloudflarestorage.com/'.$file->path.'?X-Amz-Expires=86400&X-Amz-Signature=abc';
}

/**
 * A post carrying these files (links are synced by SocialPost::saved).
 *
 * @param  list<File>  $files
 */
function postWith(User $user, array $files, SocialPostStatus $status, array $attributes = []): SocialPost
{
    $post = SocialPost::factory()->for($user)->create([
        'media' => array_map(fn (File $f) => ['url' => mediaUrl($f)], $files),
        'status' => $status,
    ] + $attributes);

    // Timestamps a test sets are kept as given (create() would stamp now).
    if (isset($attributes['updated_at']) || isset($attributes['created_at'])) {
        SocialPost::query()->whereKey($post->id)->update(array_intersect_key($attributes, array_flip(['created_at', 'updated_at'])));
    }

    MediaRetention::flush();

    return $post->refresh();
}

function mediaFixture(string $name): string
{
    return (string) file_get_contents(base_path('tests/fixtures/media/'.$name));
}
