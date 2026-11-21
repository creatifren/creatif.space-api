<?php

use App\Enums\NotificationType;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\User;
use App\Notifications\SocialPostDecided;
use App\Notifications\SocialPostFailed;
use App\Notifications\SpaceOpened;

/*
 * Prototype v10.8 (Rizal 21 Nov 2026): GET / PATCH /me/notification-settings
 * — problems_email ("Problems that need you", default on), push (master),
 * push_events (5, default on), timezone. Bell = everything; email only what
 * needs action; no per-approval emails.
 */

function nsDefaults(array $over = []): array
{
    return array_replace_recursive([
        'problems_email' => true,
        'publish_failed' => true,
        'push' => true,
        'push_events' => ['approved' => true, 'changes' => true, 'post_problem' => true, 'channel' => true, 'credits' => true],
        'timezone' => config('app.timezone'),
    ], $over);
}

describe('GET /me/notification-settings', function () {
    it('has everything on before anything is touched', function () {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/me/notification-settings')
            ->assertOk()
            ->assertExactJson(['data' => nsDefaults()]);
    });

    it('needs a signed-in user', function () {
        $this->getJson('/api/v1/me/notification-settings')->assertUnauthorized();
    });
});

describe('PATCH /me/notification-settings', function () {
    it('turns the problems email off (publish_failed is the same switch)', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['problems_email' => false])
            ->assertOk()
            ->assertExactJson(['data' => nsDefaults(['problems_email' => false, 'publish_failed' => false])]);
        expect($user->fresh()->wants(NotificationType::SocialPostFailed, 'email'))->toBeFalse();

        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['publish_failed' => true])
            ->assertOk()->assertJsonPath('data.problems_email', true);
    });

    it('turns one push event off and leaves the others', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['push_events' => ['approved' => false]])
            ->assertOk()
            ->assertExactJson(['data' => nsDefaults(['push_events' => ['approved' => false]])]);
        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['push_events' => ['credits' => false], 'push' => false])
            ->assertOk()
            ->assertJsonPath('data.push_events.approved', false)
            ->assertJsonPath('data.push_events.credits', false)
            ->assertJsonPath('data.push', false);
        expect($user->fresh()->pushWants('channel'))->toBeFalse('the master switch is off');
    });

    it('refuses an empty body, a non-boolean, and unknown names alone', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', [])->assertUnprocessable();
        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['problems_email' => 'sometimes'])
            ->assertUnprocessable()->assertJsonValidationErrors('problems_email');
        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['billing' => false])->assertUnprocessable();

        expect($user->notificationPreferences()->count())->toBe(0);
    });

    it('is each user’s own: another user keeps theirs', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->patchJson('/api/v1/me/notification-settings', ['problems_email' => false])->assertOk();

        $this->actingAs($bob)->getJson('/api/v1/me/notification-settings')->assertJsonPath('data.problems_email', true);
    });

    it('sets a valid IANA zone, refuses another, and null resets it', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['timezone' => 'Indian/Maldives'])
            ->assertOk()->assertJsonPath('data.timezone', 'Indian/Maldives');
        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['timezone' => 'Mars/Olympus'])
            ->assertUnprocessable()->assertJsonValidationErrors('timezone');
        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['timezone' => null])
            ->assertOk()->assertJsonPath('data.timezone', config('app.timezone'));
    });
});

describe('v10.8: who gets an email', function () {
    it('a failed post: bell always, email while problems_email is on', function () {
        $user = User::factory()->create();
        $post = SocialPost::factory()->for($user)->create();

        expect((new SocialPostFailed($post))->via($user))->toContain('mail')->toContain('database');

        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['problems_email' => false])->assertOk();

        expect((new SocialPostFailed($post))->via($user->fresh()))->not->toContain('mail')->toContain('database');
    });

    it('approved / changes requested: bell only, never an email', function () {
        $user = User::factory()->create();
        $link = SocialApprovalLink::query()->create(['user_id' => $user->id, 'title' => 'Nov', 'client_name' => 'Andi']);

        foreach ([SocialPostDecided::APPROVED, SocialPostDecided::REVISE] as $d) {
            expect((new SocialPostDecided($link, $d, 'Rani'))->via($user))->toBe(['database']);
        }
        expect((new SocialPostDecided($link, SocialPostDecided::BLOCKED, 'Rani'))->via($user))->toContain('mail');
    });

    it('a Space opened: bell only', function () {
        $user = User::factory()->create();
        $space = \App\Models\Space::factory()->for($user)->create();

        expect((new SpaceOpened($space))->via($user))->toBe(['database']);
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
        bearer($this, $read)->patchJson('/api/v1/me/notification-settings', ['problems_email' => false])->assertForbidden();
        bearer($this, $draft)->patchJson('/api/v1/me/notification-settings', ['problems_email' => false])
            ->assertOk()->assertJsonPath('data.problems_email', false);
    });
});
