<?php

use App\Enums\NotificationType;
use App\Models\Approval;
use App\Models\Client;
use App\Models\NotificationPreference;
use App\Models\Space;
use App\Models\SpaceItem;
use App\Models\User;
use App\Notifications\ApprovalDecided;
use App\Notifications\ApprovalsVoided;

describe('preferences', function () {
    it('reports every type as on before anything is touched', function () {
        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/me/notification-preferences')
            ->assertOk()
            // Every switchable type. Fase 6 added "files received" —
            // money notifications stay deliberately absent.
            ->assertJsonCount(count(NotificationType::switchable()), 'data');

        expect($response->json('data.0.type'))->toBe('approval.decided')
            ->and($response->json('data.0.email_enabled'))->toBeTrue();
    });

    it('stores a switch the first time it is flipped', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/v1/me/notification-preferences', [
            'type' => 'approval.decided',
            'email_enabled' => false,
        ])->assertNoContent();

        expect($user->notificationPreferences()->count())->toBe(1)
            ->and($user->fresh()->wants(NotificationType::ApprovalDecided, 'email'))->toBeFalse()
            // Only the channel that was named moved.
            ->and($user->fresh()->wants(NotificationType::ApprovalDecided, 'bell'))->toBeTrue();
    });

    it('refuses a type that is not one of the five', function () {
        $this->actingAs(User::factory()->create())
            ->patchJson('/api/v1/me/notification-preferences', [
                'type' => 'approval.invented',
                'email_enabled' => false,
            ])->assertUnprocessable();
    });
});

describe('delivery', function () {
    it('sends on both channels by default', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->create();
        $approval = Approval::factory()->approved()->create();

        expect((new ApprovalsVoided($space, 'a.jpg', 1))->via($user))
            ->toBe(['mail', 'database'])
            ->and((new ApprovalDecided($approval, $space))->via($user))
            ->toBe(['mail', 'database']);
    });

    it('drops the channel the user switched off', function () {
        $user = User::factory()->create();
        NotificationPreference::factory()->for($user)->create([
            'type' => 'approval.cancelled',
            'email_enabled' => false,
        ]);
        $space = Space::factory()->for($user)->create();

        expect((new ApprovalsVoided($space, 'a.jpg', 1))->via($user->fresh()))
            ->toBe(['database']);
    });

    it('sends nothing at all when both are off', function () {
        $user = User::factory()->create();
        NotificationPreference::factory()->for($user)->silenced()->create([
            'type' => 'approval.cancelled',
        ]);
        $space = Space::factory()->for($user)->create();

        expect((new ApprovalsVoided($space, 'a.jpg', 1))->via($user->fresh()))->toBe([]);
    });
});

describe('the bell', function () {
    it('lists notifications with an unread count, then marks them read', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->create(['title' => 'Winter Noel']);

        $user->notify(new ApprovalsVoided($space, 'winter-noel-07.jpg', 2));

        $response = $this->actingAs($user)->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.unread', 1);

        expect($response->json('data.0.data.file_name'))->toBe('winter-noel-07.jpg')
            ->and($response->json('data.0.data.count'))->toBe(2);

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

describe('insights', function () {
    it('lists my Spaces out for review with their tally', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->published()->create([
            'title' => 'Winter Noel',
            'approval_enabled' => true,
        ]);
        $item = SpaceItem::factory()->for($space)->create();
        Approval::factory()->for($item, 'spaceItem')->approved()->create();

        // A Space that never asked for an approval stays out of this list.
        Space::factory()->for($user)->published()->create(['approval_enabled' => false]);

        $this->actingAs($user)->getJson('/api/v1/insights/approvals?scope=sent')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Winter Noel')
            ->assertJsonPath('data.0.approved', 1);
    });

    it('lists what I decided in someone else’s Space', function () {
        $me = User::factory()->create(['email' => 'rani@creatif.space']);
        $client = Client::factory()->for($me)->create(['email' => 'rani@creatif.space']);

        $theirSpace = Space::factory()->published()->create(['title' => 'Ramadan Catalogue']);
        $item = SpaceItem::factory()->for($theirSpace)->create();
        Approval::factory()->for($item, 'spaceItem')->for($client)->approved()->create();

        $this->actingAs($me)->getJson('/api/v1/insights/approvals?scope=given')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Ramadan Catalogue')
            ->assertJsonPath('data.0.approved', 1);
    });

    it('lets the owner reply once, and refuses the second reply', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->create();
        $item = SpaceItem::factory()->for($space)->create();
        $approval = Approval::factory()->for($item, 'spaceItem')->revision()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/approvals/{$approval->ulid}/reply", ['body' => 'Re-cropped and back up.'])
            ->assertNoContent();

        $this->actingAs($user)
            ->postJson("/api/v1/approvals/{$approval->ulid}/reply", ['body' => 'And again.'])
            ->assertUnprocessable();
    });

    it('404s when replying on someone else’s approval', function () {
        $approval = Approval::factory()->revision()->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/approvals/{$approval->ulid}/reply", ['body' => 'Hello?'])
            ->assertNotFound();
    });
});
