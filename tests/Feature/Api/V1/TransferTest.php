<?php

use App\Models\File;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/** A sender with files ready to go. */
function senderWithFiles(int $count = 2): array
{
    Storage::fake('s3');
    $user = User::factory()->create();
    $files = collect(range(1, max($count, 0)))->take($count)->map(function (int $i) use ($user) {
        $file = File::factory()->for($user)->create(['name' => "shot-$i.jpg", 'size_bytes' => 100]);
        Storage::disk('s3')->put($file->path, "bytes $i");

        return $file;
    });

    return [$user, $files];
}

describe('sending', function () {
    it('creates a transfer pointing at files, without copying them', function () {
        [$user, $files] = senderWithFiles();

        $response = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'title' => 'Winter Noel — final selects',
            'file_ids' => $files->pluck('ulid')->all(),
            'recipients' => ['client@example.test'],
        ])->assertCreated();

        expect($response->json('data.files_count'))->toBe(2)
            ->and($response->json('data.size_bytes'))->toBe(200)
            ->and($response->json('data.url'))->toContain('/t/')
            ->and($response->json('data.has_password'))->toBeFalse()
            // Pointing, not copying: the library still holds exactly two.
            ->and(File::count())->toBe(2);
    });

    it('refuses files that are not yours', function () {
        [$user] = senderWithFiles(0);
        $theirs = File::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/transfers', [
            'title' => 'Nice try',
            'file_ids' => [$theirs->ulid],
        ])->assertStatus(422);
    });

    it('lists what I sent and what was sent to me, and never mixes them', function () {
        [$sender, $files] = senderWithFiles(1);
        $recipient = User::factory()->create(['email' => 'client@example.test']);

        $this->actingAs($sender)->postJson('/api/v1/transfers', [
            'title' => 'For you',
            'file_ids' => $files->pluck('ulid')->all(),
            'recipients' => ['client@example.test'],
        ])->assertCreated();

        expect($this->actingAs($sender)->getJson('/api/v1/transfers')->json('data'))->toHaveCount(1)
            ->and($this->actingAs($sender)->getJson('/api/v1/transfers/received')->json('data'))->toHaveCount(0)
            ->and($this->actingAs($recipient)->getJson('/api/v1/transfers/received')->json('data'))->toHaveCount(1)
            ->and($this->actingAs($recipient)->getJson('/api/v1/transfers')->json('data'))->toHaveCount(0);
    });

    it('revokes the link without touching the files', function () {
        [$user, $files] = senderWithFiles(1);
        $id = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'title' => 'Oops', 'file_ids' => $files->pluck('ulid')->all(),
        ])->json('data.id');

        $this->actingAs($user)->deleteJson("/api/v1/transfers/{$id}")->assertNoContent();

        // The whole point of pointing rather than copying.
        expect(Transfer::count())->toBe(0)->and(File::count())->toBe(1);
    });

    it('refuses to hard-delete a file a live transfer is handing out', function () {
        [$user, $files] = senderWithFiles(1);
        $this->actingAs($user)->postJson('/api/v1/transfers', [
            'title' => 'Live', 'file_ids' => $files->pluck('ulid')->all(),
        ])->assertCreated();

        /* The FK restricts it, the way a Space does: a link already sent
           must not go hollow because the sender tidied their library. */
        expect(fn () => $files->first()->forceDelete())
            ->toThrow(Illuminate\Database\QueryException::class);
    });
});

describe('the public page', function () {
    it('serves the files with signed sources, and counts the open', function () {
        [$user, $files] = senderWithFiles(2);
        $transfer = Transfer::factory()->for($user)->create();
        $transfer->files()->attach($files->pluck('id'));

        $response = $this->getJson("/api/v1/t/{$transfer->slug}")->assertOk();

        expect($response->json('data.files'))->toHaveCount(2)
            ->and($response->json('data.files.0.src'))->toContain('expir')
            ->and($response->json('data.from.name'))->toBe($user->name)
            ->and($transfer->fresh()->opens)->toBe(1);
    });

    it('leaks nothing through the lock, then opens with the token', function () {
        [$user, $files] = senderWithFiles(1);
        $transfer = Transfer::factory()->for($user)->create([
            'password_hash' => Hash::make('secret'),
        ]);
        $transfer->files()->attach($files->pluck('id'));

        $locked = $this->getJson("/api/v1/t/{$transfer->slug}")->assertStatus(423);
        expect($locked->json())->not->toHaveKey('title');

        $token = $this->postJson("/api/v1/t/{$transfer->slug}/unlock", ['password' => 'secret'])
            ->assertOk()->json('data.token');

        $this->getJson("/api/v1/t/{$transfer->slug}?st={$token}")->assertOk();
    });

    it('says expired before it says locked', function () {
        [$user] = senderWithFiles(0);
        $transfer = Transfer::factory()->for($user)->expired()->create([
            'password_hash' => Hash::make('secret'),
        ]);

        // Asking for a password it will not honour is worse than a 410.
        $this->getJson("/api/v1/t/{$transfer->slug}")->assertStatus(410);
    });

    it('downloads one file behind the same gates, and counts it', function () {
        [$user, $files] = senderWithFiles(1);
        $transfer = Transfer::factory()->for($user)->create();
        $transfer->files()->attach($files->pluck('id'));

        $this->getJson("/api/v1/t/{$transfer->slug}/files/{$files->first()->ulid}/download")
            ->assertOk()
            ->assertJsonPath('data.url', fn (string $u) => str_contains($u, 'expir'));

        expect($transfer->fresh()->downloads)->toBe(1);

        $transfer->forceFill(['password_hash' => Hash::make('x')])->save();
        $this->getJson("/api/v1/t/{$transfer->slug}/files/{$files->first()->ulid}/download")
            ->assertStatus(423);
    });

    it('404s an unknown slug', function () {
        $this->getJson('/api/v1/t/nothing-here')->assertNotFound();
    });
});
