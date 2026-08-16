<?php

use App\Models\DriveAccount;
use App\Models\DriveFile;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
 * The fields the Space Editor writes, proven to survive a save.
 *
 * Caption, alt text, SEO and the address were all drawn as inputs in the
 * editor but rendered as read-only divs, so none of them could be set at all.
 * They travel by three different routes — items[], design.items[], and
 * columns — which is why this checks the round trip rather than the request.
 */

function editorSpace(User $user): Space
{
    $account = DriveAccount::factory()->for($user)->create();
    $space = Space::factory()->for($user)->create(['slug' => 'winter-noel']);

    foreach (['one', 'two'] as $n) {
        $file = DriveFile::factory()->for($account, 'account')->create();
        $space->items()->create([
            'drive_file_id' => $file->id,
            'sort_order' => 0,
            'caption' => null,
        ]);
    }

    // Eager-loaded: this app disables lazy loading.
    return $space->fresh(['items.driveFile']);
}

it('saves captions, alt text, seo and the address', function () {
    $user = User::factory()->create();
    $space = editorSpace($user);
    $items = $space->items;

    $this->actingAs($user)
        ->patchJson("/api/v1/spaces/{$space->ulid}", [
            'slug' => 'winter-noel-2026',
            'seo' => ['title' => 'Winter Noel', 'description' => 'A winter shoot.'],
            'design' => [
                'items' => [
                    $items[0]->ulid => ['alt' => 'A red bicycle', 'size' => 'Medium'],
                    $items[1]->ulid => ['alt' => 'A blue door', 'size' => 'Medium'],
                ],
            ],
            'items' => [
                ['id' => $items[0]->ulid, 'drive_file_id' => $items[0]->driveFile->ulid, 'sort_order' => 0, 'caption' => 'First caption'],
                ['id' => $items[1]->ulid, 'drive_file_id' => $items[1]->driveFile->ulid, 'sort_order' => 1, 'caption' => 'Second caption'],
            ],
        ])
        ->assertOk();

    $fresh = $space->fresh(['items']);

    // The address.
    expect($fresh->slug)->toBe('winter-noel-2026');

    // SEO, read back the way toEditorState reads it.
    expect($fresh->seo['title'])->toBe('Winter Noel')
        ->and($fresh->seo['description'])->toBe('A winter shoot.');

    // Captions ride on the item rows.
    expect($fresh->items->pluck('caption')->sort()->values()->all())
        ->toBe(['First caption', 'Second caption']);

    // Alt text rides in the design document, keyed by the item's ulid.
    $design = data_get($fresh->design, 'items');
    expect($design[$items[0]->ulid]['alt'])->toBe('A red bicycle')
        ->and($design[$items[1]->ulid]['alt'])->toBe('A blue door');
});

it('sets, keeps and clears the password', function () {
    $user = User::factory()->create();
    $space = editorSpace($user);

    // Set.
    $this->actingAs($user)
        ->patchJson("/api/v1/spaces/{$space->ulid}", ['password' => 'winter2026'])
        ->assertOk()
        ->assertJsonPath('data.has_password', true);

    $hash = $space->fresh()->password_hash;
    expect($hash)->not->toBeNull()
        // Stored hashed, never in the clear.
        ->and($hash)->not->toBe('winter2026')
        ->and(Hash::check('winter2026', $hash))->toBeTrue();

    // Omitted: the editor sends no key when the switch is on and the field is
    // blank, and that must leave the existing password exactly as it was.
    $this->actingAs($user)
        ->patchJson("/api/v1/spaces/{$space->ulid}", ['title' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.has_password', true);

    expect($space->fresh()->password_hash)->toBe($hash);

    // Cleared by an explicit null, which is what switching it off sends.
    $this->actingAs($user)
        ->patchJson("/api/v1/spaces/{$space->ulid}", ['password' => null])
        ->assertOk()
        ->assertJsonPath('data.has_password', false);

    expect($space->fresh()->password_hash)->toBeNull();
});

it('sets and clears the link expiry', function () {
    $user = User::factory()->create();
    $space = editorSpace($user);

    // The shape the date input produces: end of the chosen day.
    $this->actingAs($user)
        ->patchJson("/api/v1/spaces/{$space->ulid}", ['expires_at' => '2030-12-31T23:59:59'])
        ->assertOk();

    expect($space->fresh()->expires_at->toDateString())->toBe('2030-12-31')
        ->and($space->fresh()->isExpired())->toBeFalse();

    // A past date really does expire it — this is the gate the public page reads.
    $this->actingAs($user)
        ->patchJson("/api/v1/spaces/{$space->ulid}", ['expires_at' => '2020-01-01T23:59:59'])
        ->assertOk();

    expect($space->fresh()->isExpired())->toBeTrue();

    // Empty means never.
    $this->actingAs($user)
        ->patchJson("/api/v1/spaces/{$space->ulid}", ['expires_at' => null])
        ->assertOk();

    expect($space->fresh()->expires_at)->toBeNull()
        ->and($space->fresh()->isExpired())->toBeFalse();
});

it('refuses an address another space already holds', function () {
    $user = User::factory()->create();
    Space::factory()->for($user)->create(['slug' => 'taken']);
    $space = editorSpace($user);

    // 422 is what the editor turns into "that address is already used".
    $this->actingAs($user)
        ->patchJson("/api/v1/spaces/{$space->ulid}", ['slug' => 'taken'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');

    expect($space->fresh()->slug)->toBe('winter-noel');
});

it('keeps its own address when saved unchanged', function () {
    $user = User::factory()->create();
    $space = editorSpace($user);

    // The editor sends the slug on every save, so re-sending the current one
    // must not trip the uniqueness check against the Space itself.
    $this->actingAs($user)
        ->patchJson("/api/v1/spaces/{$space->ulid}", ['slug' => 'winter-noel'])
        ->assertOk();

    expect($space->fresh()->slug)->toBe('winter-noel');
});
