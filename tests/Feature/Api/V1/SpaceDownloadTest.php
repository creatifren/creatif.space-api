<?php

use App\Models\File;
use App\Models\Handle;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

function downloadableSpace(array $settings = []): array
{
    Storage::fake('s3');
    $user = User::factory()->create();
    Handle::factory()->for($user)->create(['name' => 'rani']);
    $space = Space::factory()->for($user)->published()->create([
        'slug' => 'winter-noel',
        'settings' => array_merge(Space::defaultSettings(), $settings),
    ]);
    $file = File::factory()->for($user)->create(['name' => 'shot.jpg']);
    Storage::disk('s3')->put($file->path, 'bytes');
    $item = $space->items()->create(['file_id' => $file->id, 'sort_order' => 0]);

    return [$space, $item];
}

describe('downloading from a published Space', function () {
    it('hands back a signed download URL', function () {
        [, $item] = downloadableSpace();

        $this->getJson("/api/v1/profiles/rani/spaces/winter-noel/items/{$item->ulid}/download")
            ->assertOk()
            ->assertJsonPath('data.url', fn (string $url) => str_contains($url, 'expir'));
    });

    it('refuses when the owner turned downloads off', function () {
        [, $item] = downloadableSpace(['allow_download' => false]);

        /* The toggle used to be drawn and never read: turning it off hid a
           button and withheld nothing. */
        $this->getJson("/api/v1/profiles/rani/spaces/winter-noel/items/{$item->ulid}/download")
            ->assertForbidden();
    });

    it('is behind the password, like the page', function () {
        [$space, $item] = downloadableSpace();
        $space->forceFill(['password_hash' => Hash::make('secret')])->save();

        // A download link that skipped the lock would be a hole beside the door.
        $this->getJson("/api/v1/profiles/rani/spaces/winter-noel/items/{$item->ulid}/download")
            ->assertStatus(423);
    });

    it('is behind expiry, like the page', function () {
        [$space, $item] = downloadableSpace();
        $space->forceFill(['expires_at' => now()->subDay()])->save();

        $this->getJson("/api/v1/profiles/rani/spaces/winter-noel/items/{$item->ulid}/download")
            ->assertStatus(410);
    });

    it('404s for an item that is not in this Space', function () {
        downloadableSpace();

        $this->getJson('/api/v1/profiles/rani/spaces/winter-noel/items/nope/download')
            ->assertNotFound();
    });
});
