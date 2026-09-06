<?php

use App\Enums\NoteAuthor;
use App\Enums\NotificationType;
use App\Models\Approval;
use App\Models\Client;
use App\Models\File;
use App\Models\FileRequest;
use App\Models\FileRequestSubmission;
use App\Models\NotificationPreference;
use App\Models\Space;
use App\Models\SpaceItem;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\ApprovalDecided;
use App\Notifications\SpaceOpened;

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

        expect((new SpaceOpened($space))->via($user))
            ->toBe(['mail', 'database'])
            ->and((new ApprovalDecided($approval, $space))->via($user))
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
        $item = SpaceItem::factory()->for($space)->create([
            'file_id' => File::factory()->for($user)->create(['name' => 'winter-noel-07.jpg'])->id,
        ]);
        $approval = Approval::factory()->for($item, 'spaceItem')->approved()->create();
        $approval->load(['client', 'notes', 'spaceItem.file']);

        $user->notify(new ApprovalDecided($approval, $space, 2));

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

describe('needs you', function () {
    it('is empty when nothing is waiting', function () {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/me/attention')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('lists a client note nobody has answered, and drops it once replied to', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->create(['title' => 'Winter Noel']);
        $item = SpaceItem::factory()->for($space)->create();
        $approval = Approval::factory()->for($item, 'spaceItem')->revision()->create();
        $approval->notes()->create([
            'author_type' => NoteAuthor::Client,
            'body' => 'Could the third one be warmer?',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/me/attention')
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'approval.unanswered')
            ->assertJsonPath('data.0.detail', 'Winter Noel');

        $this->actingAs($user)
            ->postJson("/api/v1/approvals/{$approval->ulid}/reply", ['body' => 'Re-cropped.']);

        // Answered is done — it must not keep asking.
        $this->actingAs($user)
            ->getJson('/api/v1/me/attention')
            ->assertJsonCount(0, 'data');
    });

    it('ignores a revision with no note at all', function () {
        $user = User::factory()->create();
        $item = SpaceItem::factory()->for(Space::factory()->for($user))->create();
        Approval::factory()->for($item, 'spaceItem')->revision()->create();

        // Nobody said anything, so there is nothing to reply to.
        $this->actingAs($user)
            ->getJson('/api/v1/me/attention')
            ->assertJsonCount(0, 'data');
    });

    it('names an empty request about to close, but not one that got files', function () {
        $user = User::factory()->create();
        FileRequest::factory()->for($user)->create([
            'title' => 'Model releases',
            'expires_at' => now()->addDays(2),
        ]);
        $answered = FileRequest::factory()->for($user)->create([
            'expires_at' => now()->addDays(2),
        ]);
        FileRequestSubmission::factory()->for($answered)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/me/attention')->assertOk();

        // A link that collected something is finished, whatever the date says.
        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.kind'))->toBe('request.expiring')
            ->and($response->json('data.0.title'))->toContain('Model releases');
    });

    it('leaves alone what is not close enough to act on', function () {
        $user = User::factory()->create();
        FileRequest::factory()->for($user)->create(['expires_at' => now()->addDays(29)]);

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
        Transfer::factory()->for($user)->create(['expires_at' => now()->addDays(5)]);
        FileRequest::factory()->for($user)->create(['expires_at' => now()->addDay()]);

        $this->actingAs($user)
            ->getJson('/api/v1/me/attention')
            ->assertJsonPath('data.0.kind', 'request.expiring')
            ->assertJsonPath('data.1.kind', 'transfer.unopened');
    });

    it('never shows another account’s work', function () {
        $user = User::factory()->create();
        FileRequest::factory()->create(['expires_at' => now()->addDay()]);
        Transfer::factory()->create(['expires_at' => now()->addDay()]);

        $this->actingAs($user)
            ->getJson('/api/v1/me/attention')
            ->assertJsonCount(0, 'data');
    });
});
