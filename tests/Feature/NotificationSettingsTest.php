<?php

use App\Enums\NotificationType;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\User;
use App\Notifications\SocialPostDecided;
use App\Notifications\SocialPostFailed;

/*
 * Item 8: GET / PATCH /me/notification-settings — publish_failed,
 * approval_decided, weekly_digest (email; default on), and the emails
 * read the same switches.
 */

describe('GET /me/notification-settings', function () {
    it('has all three on before anything is touched', function () {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/me/notification-settings')
            ->assertOk()
            ->assertExactJson(['data' => ['publish_failed' => true, 'approval_decided' => true, 'weekly_digest' => true]]);
    });

    it('needs a signed-in user', function () {
        $this->getJson('/api/v1/me/notification-settings')->assertUnauthorized();
    });
});

describe('PATCH /me/notification-settings', function () {
    it('turns one off and leaves the others, the bell and the other preferences alone', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['weekly_digest' => false])
            ->assertOk()
            ->assertExactJson(['data' => ['publish_failed' => true, 'approval_decided' => true, 'weekly_digest' => false]]);

        $user = $user->fresh();
        expect($user->wants(NotificationType::WeeklyDigest, 'email'))->toBeFalse()
            ->and($user->wants(NotificationType::WeeklyDigest, 'bell'))->toBeTrue()
            ->and($user->notificationPreferences()->count())->toBe(1);

        // The same switch as Settings → Notifications.
        $row = collect($this->actingAs($user)->getJson('/api/v1/me/notification-preferences')->json('data'))
            ->firstWhere('type', 'digest.weekly');
        expect($row['email_enabled'])->toBeFalse();
    });

    it('turns them back on, several at once', function () {
        $user = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['publish_failed' => false, 'approval_decided' => false])->assertOk();

        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['publish_failed' => true])
            ->assertOk()
            ->assertJsonPath('data.publish_failed', true)
            ->assertJsonPath('data.approval_decided', false);
    });

    it('refuses an empty body, a non-boolean, and ignores unknown names', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', [])->assertUnprocessable();
        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['weekly_digest' => 'sometimes'])
            ->assertUnprocessable()->assertJsonValidationErrors('weekly_digest');
        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['billing' => false])->assertUnprocessable();

        expect($user->notificationPreferences()->count())->toBe(0);
    });

    it('is each user’s own: another user keeps theirs', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->patchJson('/api/v1/me/notification-settings', ['publish_failed' => false])->assertOk();

        $this->actingAs($bob)->getJson('/api/v1/me/notification-settings')->assertJsonPath('data.publish_failed', true);
    });
});

describe('the emails respect them', function () {
    it('sends no publish-failed email when publish_failed is off (the bell stays)', function () {
        $user = User::factory()->create();
        $post = SocialPost::factory()->for($user)->create();

        expect((new SocialPostFailed($post))->via($user))->toContain('mail');

        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['publish_failed' => false])->assertOk();

        expect((new SocialPostFailed($post))->via($user->fresh()))->not->toContain('mail')->toContain('database');
    });

    it('sends no approval email when approval_decided is off', function () {
        $user = User::factory()->create();
        $link = SocialApprovalLink::query()->create(['user_id' => $user->id, 'title' => 'Nov', 'client_name' => 'Andi']);
        $notice = new SocialPostDecided($link, SocialPostDecided::APPROVED, 'Rani');

        expect($notice->via($user))->toContain('mail');

        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['approval_decided' => false])->assertOk();

        expect($notice->via($user->fresh()))->not->toContain('mail');
    });
});

describe('token scopes (MCP)', function () {
    it('reads with read, changes with draft', function () {
        $user = User::factory()->create();
        $key = fn (array $scopes) => $this->actingAs(User::query()->findOrFail($user->id), 'web')
            ->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'scopes' => $scopes])->assertCreated()->json('data.key');
        $read = $key(['read']);
        $draft = $key(['read', 'draft']);
        app('auth')->forgetGuards();

        bearer($this, $read)->getJson('/api/v1/me/notification-settings')->assertOk();
        bearer($this, $read)->patchJson('/api/v1/me/notification-settings', ['weekly_digest' => false])->assertForbidden();
        bearer($this, $draft)->patchJson('/api/v1/me/notification-settings', ['weekly_digest' => false])
            ->assertOk()->assertJsonPath('data.weekly_digest', false);
    });
});
