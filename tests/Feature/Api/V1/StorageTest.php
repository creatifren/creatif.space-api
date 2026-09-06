<?php

use App\Models\File;
use App\Models\User;

describe('storage summary', function () {
    it('reports used, the plan ceiling, and what old versions are holding', function () {
        $user = User::factory()->create();
        $file = File::factory()->for($user)->create(['size_bytes' => 1_000]);
        File::factory()->for($user)->pending()->create(['size_bytes' => 500]);
        File::factory()->create(); // someone else's — never counted

        $file->versions()->create([
            'number' => 1,
            'path' => 'v/old/'.str()->ulid().'.jpg',
            'size_bytes' => 400,
        ]);

        $this->actingAs($user)->getJson('/api/v1/me/storage')
            ->assertOk()
            /* Versions are inside `used` as well as reclaimable: they are
               real objects in the bucket, which is what makes "free up
               400 bytes" a true sentence rather than an invitation. */
            ->assertJsonPath('data.used', 1_900)
            ->assertJsonPath('data.limit', 2 * 1024 * 1024 * 1024)
            ->assertJsonPath('data.reclaimable.versions', 400);
    });

    it('reports nothing reclaimable on a fresh account', function () {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/me/storage')
            ->assertOk()
            ->assertJsonPath('data.used', 0)
            ->assertJsonPath('data.reclaimable.versions', 0);
    });

    it('counts the copies of a duplicate, never the original', function () {
        $user = User::factory()->create();
        // Three files, same bytes: two of them are waste.
        File::factory()->count(3)->for($user)->create([
            'size_bytes' => 1_000,
            'checksum' => str_repeat('a', 32),
        ]);
        // A pair, and a lone file that shares nothing.
        File::factory()->count(2)->for($user)->create([
            'size_bytes' => 500,
            'checksum' => str_repeat('b', 32),
        ]);
        File::factory()->for($user)->create([
            'size_bytes' => 9_000,
            'checksum' => str_repeat('c', 32),
        ]);

        $this->actingAs($user)->getJson('/api/v1/me/storage')
            ->assertOk()
            ->assertJsonPath('data.reclaimable.duplicates', 2_500);
    });

    it('leaves files with no checksum out of the duplicate count', function () {
        $user = User::factory()->create();
        /* Multipart uploads have no MD5, and pre-backfill rows have none
           yet. Guessing would offer to delete something that is not a
           duplicate. */
        File::factory()->count(3)->for($user)->create([
            'size_bytes' => 1_000,
            'checksum' => null,
        ]);

        $this->actingAs($user)->getJson('/api/v1/me/storage')
            ->assertOk()
            ->assertJsonPath('data.reclaimable.duplicates', 0);
    });

    it('does not count a trashed copy twice', function () {
        $user = User::factory()->create();
        $live = File::factory()->for($user)->create([
            'size_bytes' => 1_000,
            'checksum' => str_repeat('d', 32),
        ]);
        $binned = File::factory()->for($user)->create([
            'size_bytes' => 1_000,
            'checksum' => str_repeat('d', 32),
        ]);
        $binned->delete();

        $response = $this->actingAs($user)->getJson('/api/v1/me/storage')->assertOk();

        // The bin already claims those bytes; duplicates must not re-claim them.
        expect($response->json('data.reclaimable.trash'))->toBe(1_000)
            ->and($response->json('data.reclaimable.duplicates'))->toBe(0)
            ->and($live->fresh())->not->toBeNull();
    });

    it('is signed-in only', function () {
        $this->getJson('/api/v1/me/storage')->assertUnauthorized();
    });
});
