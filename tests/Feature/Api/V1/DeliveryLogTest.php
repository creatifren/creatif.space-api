<?php

use App\Enums\SpaceEventType;
use App\Models\File;
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
        $space = Space::factory()->for($user)->published()->create();

        foreach (range(1, 3) as $i) {
            $file = File::factory()->for($user)->create();
            SpaceItem::factory()->for($space)->create(['file_id' => $file->id]);
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
