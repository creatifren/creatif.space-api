<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * The routes behind the MCP media housekeeping tools, reached with a bearer
 * token: get_storage_usage → GET /files/usage (read), keep_file /
 * unkeep_file → POST / DELETE /files/{ulid}/keep (draft). Quota and plan
 * rules stay in MediaQuota.
 */

beforeEach(function () {
    ensurePassportKeys();
    Http::fake();
});

function storageToolKey(TestCase $test, User $user, array $scopes = ['read', 'draft']): string
{
    $key = $test->actingAs($user, 'web')
        ->postJson('/api/v1/me/api-keys', ['name' => 'Storage key', 'scopes' => $scopes])
        ->assertCreated()
        ->json('data.key');
    app('auth')->forgetGuards();

    return $key;
}

it('reads usage with a read-only token', function () {
    $user = mediaUser('free');
    mediaFile($user, ['size_bytes' => 7_000]);
    $key = storageToolKey($this, $user, ['read']);

    bearer($this, $key)->getJson('/api/v1/files/usage')
        ->assertOk()
        ->assertExactJson(['data' => ['tier' => 'free', 'used_bytes' => 7_000, 'quota_bytes' => MEDIA_GB]]);
});

it('keeps and unkeeps through a draft token', function () {
    $user = mediaUser('pro');
    $file = mediaFile($user, ['size_bytes' => 5_000]);
    $key = storageToolKey($this, $user);

    bearer($this, $key)->postJson("/api/v1/files/{$file->ulid}/keep")
        ->assertOk()
        ->assertJsonPath('data.is_kept', true);
    bearer($this, $key)->getJson('/api/v1/files/usage')->assertJsonPath('data.keep_used_bytes', 5_000);

    bearer($this, $key)->deleteJson("/api/v1/files/{$file->ulid}/keep")
        ->assertOk()
        ->assertJsonPath('data.is_kept', false);
    expect($file->fresh()->kept_at)->toBeNull();
});

it('needs draft to keep or unkeep', function (string $method) {
    $user = mediaUser('pro');
    $file = mediaFile($user);
    $key = storageToolKey($this, $user, ['read']);

    bearer($this, $key)->json($method, "/api/v1/files/{$file->ulid}/keep")
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope');
    expect($file->fresh()->kept_at)->toBeNull();
})->with(['POST', 'DELETE']);

it('refuses Keep on Free with 402 upgrade_pro, and a file that is not ready with 409', function () {
    $free = mediaUser('free');
    $file = mediaFile($free);
    bearer($this, storageToolKey($this, $free))->postJson("/api/v1/files/{$file->ulid}/keep")
        ->assertStatus(402)
        ->assertJson(['reason' => 'quota_exceeded', 'feature' => 'keep_quota', 'next_step' => 'upgrade_pro']);

    $pro = mediaUser('pro');
    $pending = mediaFile($pro, ['status' => File::STATUS_PENDING]);
    bearer($this, storageToolKey($this, $pro))->postJson("/api/v1/files/{$pending->ulid}/keep")->assertStatus(409);
});

it('answers 404 for another workspace’s file', function () {
    $owner = mediaUser('pro');
    $file = mediaFile($owner);
    $key = storageToolKey($this, mediaUser('pro'));

    bearer($this, $key)->postJson("/api/v1/files/{$file->ulid}/keep")->assertNotFound();
    bearer($this, $key)->deleteJson("/api/v1/files/{$file->ulid}/keep")->assertNotFound();
});
