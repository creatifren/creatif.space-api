<?php

use App\Enums\NotificationType;
use App\Models\File;
use App\Models\NotificationPreference;
use App\Models\Space;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\SpaceOpened;

describe('preferences', function () {
    it('reports every type as on before anything is touched', function () {
        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/me/notification-preferences')
            ->assertOk()
            // Every switchable type — money notifications stay
            // deliberately absent.
            ->assertJsonCount(count(NotificationType::switchable()), 'data');

        expect($response->json('data.0.type'))->toBe('space.opened')
            ->and($response->json('data.0.email_enabled'))->toBeTrue();
    });

    it('stores a switch the first time it is flipped', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/v1/me/notification-preferences', [
            'type' => 'space.opened',
            'email_enabled' => false,
        ])->assertNoContent();

        expect($user->notificationPreferences()->count())->toBe(1)
            ->and($user->fresh()->wants(NotificationType::SpaceOpened, 'email'))->toBeFalse()
            // Only the channel that was named moved.
            ->and($user->fresh()->wants(NotificationType::SpaceOpened, 'bell'))->toBeTrue();
    });

    it('refuses a type that is not one of the switches', function () {
        $this->actingAs(User::factory()->create())
            ->patchJson('/api/v1/me/notification-preferences', [
                'type' => 'space.invented',
                'email_enabled' => false,
            ])->assertUnprocessable();
    });
});

describe('delivery', function () {
    it('sends on both channels by default', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->create();

        expect((new SpaceOpened($space))->via($user))
            ->toBe(['mail', 'database']);
    });

    it('drops the channel the user switched off', function () {
        $user = User::factory()->create();
        NotificationPreference::factory()->for($user)->create([
            'type' => 'space.opened',
            'email_enabled' => false,
        ]);
        $space = Space::factory()->for($user)->create();

        expect((new SpaceOpened($space))->via($user->fresh()))
            ->toBe(['database']);
    });

    it('sends nothing at all when both are off', function () {
        $user = User::factory()->create();
        NotificationPreference::factory()->for($user)->silenced()->create([
            'type' => 'space.opened',
        ]);
        $space = Space::factory()->for($user)->create();

        expect((new SpaceOpened($space))->via($user->fresh()))->toBe([]);
    });
});

describe('the bell', function () {
    it('lists notifications with an unread count, then marks them read', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->create(['title' => 'Winter Noel']);

        $user->notify(new SpaceOpened($space));

        $response = $this->actingAs($user)->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.unread', 1);

        expect($response->json('data.0.data.space_title'))->toBe('Winter Noel');

        $this->actingAs($user)->postJson('/api/v1/me/notifications/read')->assertNoContent();

        $this->actingAs($user)->getJson('/api/v1/me/notifications')
            ->assertJsonPath('meta.unread', 0);
    });

    it('rejects guests everywhere', function () {
        $this->getJson('/api/v1/me/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/me/attention')->assertUnauthorized();
        $this->getJson('/api/v1/me/notification-preferences')->assertUnauthorized();
    });
});

describe('needs you', function () {
    it('is empty when nothing is waiting', function () {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/me/attention')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('leaves alone what is not close enough to act on', function () {
        $user = User::factory()->create();
        Transfer::factory()->for($user)->create(['expires_at' => now()->addDays(29)]);

        // A month of warning is wallpaper, not attention.
        $this->actingAs($user)
            ->getJson('/api/v1/me/attention')
            ->assertJsonCount(0, 'data');
    });

    it('names a transfer nobody opened, and stays quiet once it is opened', function () {
        $user = User::factory()->create();
        $transfer = Transfer::factory()->for($user)->create([
            'title' => 'Final selects',
            'expires_at' => now()->addDays(3),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/me/attention')
            ->assertJsonPath('data.0.kind', 'transfer.unopened');

        $transfer->increment('opens');

        $this->actingAs($user)
            ->getJson('/api/v1/me/attention')
            ->assertJsonCount(0, 'data');
    });

    it('folds the whole Trash into one row', function () {
        $user = User::factory()->create();
        File::factory()->count(3)->for($user)->create()
            ->each(function (File $file) {
                $file->forceFill(['purge_at' => now()->addDays(2)])->save();
                $file->delete();
            });

        $response = $this->actingAs($user)->getJson('/api/v1/me/attention')->assertOk();

        // Three rows saying "restore me" would bury the other kinds.
        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.title'))->toContain('3 files');
    });

    it('puts the nearest deadline first', function () {
        $user = User::factory()->create();
        $file = File::factory()->for($user)->create();
        $file->forceFill(['purge_at' => now()->addDays(5)])->save();
        $file->delete();
        Transfer::factory()->for($user)->create(['expires_at' => now()->addDay()]);

        $this->actingAs($user)
            ->getJson('/api/v1/me/attention')
            ->assertJsonPath('data.0.kind', 'transfer.unopened')
            ->assertJsonPath('data.1.kind', 'trash.purging');
    });

    it('never shows another account’s work', function () {
        $user = User::factory()->create();
        Transfer::factory()->create(['expires_at' => now()->addDay()]);

        $this->actingAs($user)
            ->getJson('/api/v1/me/attention')
            ->assertJsonCount(0, 'data');
    });
});
