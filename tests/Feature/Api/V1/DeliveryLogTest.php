<?php

use App\Enums\ApprovalStatus;
use App\Enums\SpaceEventType;
use App\Models\Approval;
use App\Models\Client;
use App\Models\DriveAccount;
use App\Models\DriveFile;
use App\Models\Space;
use App\Models\SpaceItem;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * The record a creator shows when a client says the work never arrived. Its
 * promise — "only grows, never edited or deleted, on every plan" — is what
 * most of these assert.
 */
describe('delivery log', function () {
    it('lists published Spaces with their milestones', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->published()->create([
            'title' => 'Winter Noel',
            'published_at' => now()->subDays(10),
            'first_opened_at' => now()->subDays(9),
            'first_downloaded_at' => now()->subDays(8),
            'downloaded_files' => 24,
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/me/deliveries')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Winter Noel')
            ->assertJsonPath('data.0.downloaded_files', 24)
            ->assertJsonPath('data.0.deleted', false);

        // Every milestone the card prints is present and dated.
        foreach (['published_at', 'first_opened_at', 'first_downloaded_at'] as $key) {
            expect($response->json("data.0.{$key}"))->not->toBeNull();
        }

        expect($space->fresh()->trashed())->toBeFalse();
    });

    it('keeps the record after the Space is deleted', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->published()->create([
            'title' => 'Ramadan Campaign',
        ]);

        $space->delete();

        // The whole point: the evidence outlives the thing it describes.
        $this->actingAs($user)
            ->getJson('/api/v1/me/deliveries')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Ramadan Campaign')
            ->assertJsonPath('data.0.deleted', true);
    });

    it('leaves out drafts, which were never sent to anyone', function () {
        $user = User::factory()->create();
        Space::factory()->for($user)->create(['published_at' => null]);

        $this->actingAs($user)
            ->getJson('/api/v1/me/deliveries')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('never shows another account’s deliveries', function () {
        $me = User::factory()->create();
        Space::factory()->published()->create(); // someone else's

        $this->actingAs($me)
            ->getJson('/api/v1/me/deliveries')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('groups approvals into one verified mark per client', function () {
        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create();
        $space = Space::factory()->for($user)->published()->create();
        $client = Client::factory()->create(['email' => 'andi@winternoel.com']);

        // One client signing off three files is one verdict, not three rows.
        foreach (range(1, 3) as $i) {
            $file = DriveFile::factory()->for($account, 'account')->create();
            $item = SpaceItem::factory()->for($space)->create(['drive_file_id' => $file->id]);
            Approval::factory()->for($item, 'spaceItem')->for($client)->create([
                'status' => ApprovalStatus::Approved,
                'approved_at' => now()->subDays(4 - $i),
            ]);
        }

        $this->actingAs($user)
            ->getJson('/api/v1/me/deliveries')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.marks')
            ->assertJsonPath('data.0.marks.0.email', 'andi@winternoel.com')
            ->assertJsonPath('data.0.marks.0.files', 3)
            ->assertJsonPath('data.0.marks.0.verified', true);
    });

    it('counts only files a client actually approved', function () {
        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create();
        $space = Space::factory()->for($user)->published()->create();
        $client = Client::factory()->create();

        $approvedFile = DriveFile::factory()->for($account, 'account')->create();
        $approvedItem = SpaceItem::factory()->for($space)->create(['drive_file_id' => $approvedFile->id]);
        Approval::factory()->for($approvedItem, 'spaceItem')->for($client)->create([
            'status' => ApprovalStatus::Approved,
            'approved_at' => now(),
        ]);

        $pendingFile = DriveFile::factory()->for($account, 'account')->create();
        $pendingItem = SpaceItem::factory()->for($space)->create(['drive_file_id' => $pendingFile->id]);
        Approval::factory()->for($pendingItem, 'spaceItem')->for($client)->create([
            'status' => ApprovalStatus::Pending,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/me/deliveries')
            ->assertOk()
            ->assertJsonPath('data.0.marks.0.files', 1);
    });

    it('is available on Free — evidence is not an upsell', function () {
        $user = User::factory()->create();
        Space::factory()->for($user)->published()->create();

        expect($user->plan()->key->value)->toBe('free');

        $this->actingAs($user)
            ->getJson('/api/v1/me/deliveries')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('requires a session', function () {
        $this->getJson('/api/v1/me/deliveries')->assertUnauthorized();
    });
});

describe('delivery milestones', function () {
    it('stamps the first open, once, and not on a refresh', function () {
        Notification::fake();
        $space = Space::factory()->published()->create(['first_opened_at' => null]);

        $this->postJson("/api/v1/spaces/{$space->ulid}/events", [
            'type' => SpaceEventType::View->value,
        ])->assertNoContent();

        $firstAt = $space->fresh()->first_opened_at;
        expect($firstAt)->not->toBeNull();

        $this->travel(2)->days();
        $this->postJson("/api/v1/spaces/{$space->ulid}/events", [
            'type' => SpaceEventType::View->value,
        ])->assertNoContent();

        // "When was it delivered", not "when was it last looked at".
        expect($space->fresh()->first_opened_at->toIso8601String())
            ->toBe($firstAt->toIso8601String());
    });

    it('stamps the first download with how much was taken', function () {
        Notification::fake();
        $user = User::factory()->create();
        $account = DriveAccount::factory()->for($user)->create();
        $space = Space::factory()->for($user)->published()->create();

        foreach (range(1, 3) as $i) {
            $file = DriveFile::factory()->for($account, 'account')->create();
            SpaceItem::factory()->for($space)->create(['drive_file_id' => $file->id]);
        }

        $this->postJson("/api/v1/spaces/{$space->ulid}/events", [
            'type' => SpaceEventType::Download->value,
        ])->assertNoContent();

        expect($space->fresh()->first_downloaded_at)->not->toBeNull()
            ->and($space->fresh()->downloaded_files)->toBe(3);
    });

    it('does not let a crawler count as a delivery', function () {
        Notification::fake();
        $space = Space::factory()->published()->create(['first_opened_at' => null]);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; GPTBot/1.0)')
            ->postJson("/api/v1/spaces/{$space->ulid}/events", [
                'type' => SpaceEventType::View->value,
            ])->assertNoContent();

        // GPTBot fetching a page is not a client opening it.
        expect($space->fresh()->first_opened_at)->toBeNull();
    });
});
