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

    it('is signed-in only', function () {
        $this->getJson('/api/v1/me/storage')->assertUnauthorized();
    });
});
