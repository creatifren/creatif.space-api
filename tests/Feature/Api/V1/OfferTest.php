<?php

use App\Models\Offer;
use App\Models\Order;
use App\Models\Space;
use App\Models\User;

describe('managing offers', function () {
    it('creates one against a Space you own', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->create();

        $this->actingAs($user)->postJson('/api/v1/offers', [
            'type' => 'product',
            'title' => 'Preset Senja — 12 LUT',
            'price' => 149_000,
            'space_id' => $space->ulid,
            'details' => ['licence' => 'Personal use', 'delivery' => 'Automatic, on payment'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Preset Senja — 12 LUT')
            ->assertJsonPath('data.space_id', $space->ulid)
            ->assertJsonPath('data.buyable', true)
            ->assertJsonPath('data.details.licence', 'Personal use');
    });

    it('creates one with no Space — the profile’s Hire list', function () {
        $this->actingAs(User::factory()->create())->postJson('/api/v1/offers', [
            'type' => 'service',
            'title' => 'Portfolio shoot',
            'price' => 3_500_000,
            'price_from' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.space_id', null)
            // "Starting at" is an opening line, not a checkout.
            ->assertJsonPath('data.buyable', false);
    });

    it('refuses to hang an offer on someone else’s Space', function () {
        $theirs = Space::factory()->create();

        $this->actingAs(User::factory()->create())->postJson('/api/v1/offers', [
            'type' => 'product',
            'title' => 'Nice try',
            'price' => 10_000,
            'space_id' => $theirs->ulid,
        ])->assertUnprocessable();
    });

    it('lists only my own', function () {
        $user = User::factory()->create();
        Offer::factory()->for($user)->create(['title' => 'Mine']);
        Offer::factory()->create(['title' => 'Theirs']);

        $this->actingAs($user)->getJson('/api/v1/offers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine');
    });

    it('updates and switches one off', function () {
        $user = User::factory()->create();
        $offer = Offer::factory()->for($user)->create(['price' => 149_000]);

        $this->actingAs($user)->patchJson("/api/v1/offers/{$offer->ulid}", [
            'price' => 99_000,
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.price', 99_000)
            ->assertJsonPath('data.is_active', false);
    });

    it('404s on someone else’s offer', function () {
        $offer = Offer::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patchJson("/api/v1/offers/{$offer->ulid}", ['title' => 'Mine now'])
            ->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/v1/offers/{$offer->ulid}")
            ->assertNotFound();
    });

    it('keeps a deleted offer readable by the orders that reference it', function () {
        $user = User::factory()->create();
        $offer = Offer::factory()->for($user)->create(['title' => 'Retired product']);
        $order = Order::factory()->for($user, 'creator')->for($offer)->paid()->create();

        $this->actingAs($user)->deleteJson("/api/v1/offers/{$offer->ulid}")->assertNoContent();

        // Soft deleted: the sale can still say what it was for.
        expect(Offer::query()->count())->toBe(0)
            ->and($order->fresh()->offer->title)->toBe('Retired product');
    });

    it('rejects guests', function () {
        $this->getJson('/api/v1/offers')->assertUnauthorized();
        $this->postJson('/api/v1/offers', [])->assertUnauthorized();
    });

    it('validates the type', function () {
        $this->actingAs(User::factory()->create())->postJson('/api/v1/offers', [
            'type' => 'subscription',
            'title' => 'Not a thing',
        ])->assertUnprocessable();
    });
});
