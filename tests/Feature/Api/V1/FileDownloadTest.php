<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/** A ready file with bytes actually in the fake bucket. */
function ownedFile(User $user, array $attributes = []): File
{
    $file = File::factory()->for($user)->create($attributes);
    Storage::disk('s3')->put($file->path, 'bytes');

    return $file;
}

describe('downloading your own file', function () {
    it('hands back a signed URL', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $file = ownedFile($user, ['name' => 'winter shot.jpg']);

        /* ponytail: this stops at "signed". The filename lives in a
           Content-Disposition that downloadUrl() signs into the URL, and
           the fake disk drops the option entirely — both methods return
           the same string here, so no test on this disk can tell them
           apart. Covered against R2 by the same helper the two public
           download routes already use. */
        $this->actingAs($user)
            ->getJson("/api/v1/files/{$file->ulid}/download")
            ->assertOk()
            ->assertJsonPath('data.url', fn (string $url) => str_contains($url, 'expir'));
    });

    it('is a 404 for somebody else\'s file, not a 403', function () {
        Storage::fake('s3');
        $file = ownedFile(User::factory()->create());

        /* Same shape as show(): a stranger's library should not be able to
           tell an id that exists from one that does not. */
        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/files/{$file->ulid}/download")
            ->assertNotFound();
    });

    it('refuses a row whose bytes never arrived', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $pending = File::factory()->for($user)->create(['status' => File::STATUS_PENDING]);

        // An upload still in flight has a key with nothing behind it.
        $this->actingAs($user)
            ->getJson("/api/v1/files/{$pending->ulid}/download")
            ->assertNotFound();
    });

    it('needs a signed-in user', function () {
        Storage::fake('s3');
        $file = ownedFile(User::factory()->create());

        $this->getJson("/api/v1/files/{$file->ulid}/download")->assertUnauthorized();
    });
});
