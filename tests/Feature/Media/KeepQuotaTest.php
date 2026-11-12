<?php

use App\Enums\SocialPostStatus;
use App\Support\Media\MediaQuota;

/*
 * Rule 2: Keep (paid). Pro 25 GB, Max 50 GB, a trial is Pro. Media on
 * draft/scheduled posts counts toward the same quota. Free has no Keep and
 * a 1 GB total. Refusals: 402 quota_exceeded with feature + next_step.
 */

it('keeps and unkeeps a file, and reports paid usage', function () {
    $user = mediaUser('pro');
    $file = mediaFile($user, ['size_bytes' => 5_000]);

    $this->actingAs($user)->postJson("/api/v1/files/{$file->ulid}/keep")
        ->assertOk()
        ->assertJsonPath('data.is_kept', true)
        ->assertJsonPath('data.auto_delete_at', null);

    // Idempotent.
    $this->actingAs($user)->postJson("/api/v1/files/{$file->ulid}/keep")->assertOk();

    $this->actingAs($user)->getJson('/api/v1/files/usage')
        ->assertOk()
        ->assertExactJson(['data' => ['tier' => 'pro', 'keep_used_bytes' => 5_000, 'keep_quota_bytes' => 25 * MEDIA_GB, 'library_used_bytes' => 5_000]]);

    $this->actingAs($user)->deleteJson("/api/v1/files/{$file->ulid}/keep")
        ->assertOk()
        ->assertJsonPath('data.is_kept', false);

    expect(MediaQuota::keepUsed($user))->toBe(0);
});

it('reports Free usage as used_bytes of a 1 GB quota', function () {
    $user = mediaUser('free');
    mediaFile($user, ['size_bytes' => 7_000]);

    $this->actingAs($user)->getJson('/api/v1/files/usage')
        ->assertOk()
        ->assertExactJson(['data' => ['tier' => 'free', 'used_bytes' => 7_000, 'quota_bytes' => MEDIA_GB]]);
});

it('refuses Keep on Free with upgrade_pro', function () {
    $user = mediaUser('free');
    $file = mediaFile($user);

    $this->actingAs($user)->postJson("/api/v1/files/{$file->ulid}/keep")
        ->assertStatus(402)
        ->assertJson(['reason' => 'quota_exceeded', 'feature' => 'keep_quota', 'next_step' => 'upgrade_pro']);

    expect($file->fresh()->kept_at)->toBeNull();
});

dataset('paid boundaries', [
    'pro' => ['pro', 25, 'upgrade_max'],
    'trial is pro' => ['trial', 25, 'upgrade_max'],
    'max' => ['max', 50, 'contact_sales'],
]);

it('keeps up to the quota exactly and refuses one byte past it', function (string $tier, int $quotaGb, string $next) {
    $user = mediaUser($tier);
    // Uploaded yesterday, so today's (internal) upload budget is untouched.
    $big = mediaFile($user, ['size_bytes' => $quotaGb * MEDIA_GB - 10, 'kept_at' => now(), 'created_at' => now()->subDay()]);
    $fits = mediaFile($user, ['size_bytes' => 10]);
    $over = mediaFile($user, ['size_bytes' => 1]);

    $this->actingAs($user)->postJson("/api/v1/files/{$fits->ulid}/keep")->assertOk();
    expect(MediaQuota::keepUsed($user))->toBe($quotaGb * MEDIA_GB);

    $this->actingAs($user)->postJson("/api/v1/files/{$over->ulid}/keep")
        ->assertStatus(402)
        ->assertJson(['reason' => 'quota_exceeded', 'feature' => 'keep_quota', 'next_step' => $next]);

    // Uploads stop too, at the same quota.
    $this->actingAs($user)->postJson('/api/v1/files/presign', [
        'name' => 'more.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1,
    ])->assertStatus(402)->assertJson(['reason' => 'quota_exceeded', 'feature' => 'keep_quota', 'next_step' => $next]);

    expect($big->fresh()->kept_at)->not->toBeNull();
})->with('paid boundaries');

it('counts media on queued posts toward the Keep quota, once', function () {
    $user = mediaUser('pro');
    $onPost = mediaFile($user, ['size_bytes' => 3_000]);
    $draft = mediaFile($user, ['size_bytes' => 2_000]);
    $published = mediaFile($user, ['size_bytes' => 9_000]);

    postWith($user, [$onPost], SocialPostStatus::Scheduled);
    postWith($user, [$onPost, $draft], SocialPostStatus::Draft);
    postWith($user, [$published], SocialPostStatus::Published);

    expect(MediaQuota::keepUsed($user))->toBe(5_000);

    // Keeping a file already counted adds nothing.
    MediaQuota::keep($user, $onPost);
    expect(MediaQuota::keepUsed($user))->toBe(5_000);
});

it('refuses a Free upload that would pass 1 GB with media_quota / upgrade_pro', function () {
    $user = mediaUser('free');
    mediaFile($user, ['size_bytes' => MEDIA_GB - 100]);

    $this->actingAs($user)->postJson('/api/v1/files/presign', [
        'name' => 'fits.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 100,
    ])->assertCreated();

    $this->actingAs($user)->postJson('/api/v1/files/presign', [
        'name' => 'over.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1,
    ])->assertStatus(402)->assertJson(['reason' => 'quota_exceeded', 'feature' => 'media_quota', 'next_step' => 'upgrade_pro']);
});

it('keeps another workspace\'s file a 404', function () {
    $user = mediaUser('pro');
    $file = mediaFile(mediaUser('pro'));

    $this->actingAs($user)->postJson("/api/v1/files/{$file->ulid}/keep")->assertNotFound();
});
