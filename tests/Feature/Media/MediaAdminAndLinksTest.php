<?php

use App\Enums\SocialPostStatus;
use App\Enums\UserStatus;
use App\Models\MediaUploadFlag;
use App\Models\User;
use App\Support\Media\MediaLinks;
use App\Support\Media\MediaUploadGuard;
use Illuminate\Support\Facades\DB;

it('links a post to the library files in its media URLs, and follows edits', function () {
    $user = mediaUser('pro');
    $a = mediaFile($user);
    $b = mediaFile($user);
    $foreign = mediaFile(mediaUser('pro'));

    $post = postWith($user, [$a, $foreign], SocialPostStatus::Scheduled);
    expect(DB::table('social_post_files')->where('social_post_id', $post->id)->pluck('file_id')->all())->toBe([$a->id]);

    $post->update(['media' => [['url' => mediaUrl($b), 'thumbnail_url' => mediaUrl($a)]]]);
    expect(DB::table('social_post_files')->where('social_post_id', $post->id)->orderBy('file_id')->pluck('file_id')->all())
        ->toBe([$a->id, $b->id]);

    $post->update(['media' => [['url' => 'https://elsewhere.test/photo.jpg']]]);
    expect(DB::table('social_post_files')->where('social_post_id', $post->id)->count())->toBe(0);
});

it('backfills links for posts saved before the table existed (what the migration runs)', function () {
    $user = mediaUser('free');
    $file = mediaFile($user);
    $post = postWith($user, [$file], SocialPostStatus::Published);
    DB::table('social_post_files')->delete();

    expect(MediaLinks::backfill())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('social_post_files')->where('social_post_id', $post->id)->value('file_id'))->toBe($file->id);

    MediaLinks::backfill(); // re-run: no duplicates
    expect(DB::table('social_post_files')->count())->toBe(1);
});

it('reads the file ulid from signed URLs, path-style or not', function () {
    $ulid = '01JABCDEFGHJKMNPQRSTVWXYZ0';
    expect(MediaLinks::fileUlid("https://acc.r2.cloudflarestorage.com/bucket/u/01JUSERUSERUSERUSERUSERUS0/{$ulid}.mp4?X-Amz-Signature=x"))->toBe($ulid)
        ->and(MediaLinks::fileUlid("https://bucket.acc.r2.cloudflarestorage.com/u/01JUSERUSERUSERUSERUSERUS0/{$ulid}?X-Amz-Signature=x"))->toBe($ulid)
        ->and(MediaLinks::fileUlid('https://cdn.example.test/u/photo.jpg'))->toBeNull();
});

it('lists flagged accounts for staff in Filament', function () {
    $user = mediaUser('free');
    config(['media.abuse.flag_after_hits' => 1]);
    MediaUploadGuard::hit($user, 'daily_bytes');
    expect(MediaUploadFlag::query()->whereNotNull('flagged_at')->count())->toBe(1);

    $staff = User::factory()->create(['is_staff' => true, 'status' => UserStatus::Active]);

    $this->actingAs(User::findOrFail($staff->id))
        ->get('/admin/media-upload-flags')
        ->assertOk()
        ->assertSee($user->email)
        ->assertSee('daily_bytes');
});
