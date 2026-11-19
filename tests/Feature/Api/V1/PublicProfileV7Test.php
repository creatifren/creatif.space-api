<?php

use App\Models\Handle;
use App\Models\Profile;
use App\Models\SocialApprovalLink;
use App\Models\Space;
use App\Models\Subscription;
use App\Models\User;

/* v7 public profile + URLs, and Language & region. */

function profileOwner(bool $paid = true): User
{
    $user = User::factory()->create();
    if ($paid) {
        Subscription::factory()->for($user)->create();
    }
    Handle::factory()->for($user)->create(['name' => 'rizaltester']);

    return $user;
}

it('saves layout, contact, week start and time format, and shows them on the public page', function () {
    $user = profileOwner();
    $this->actingAs($user)->patchJson('/api/v1/me/profile', [
        'layout' => 'grid',
        'contact' => ['primary' => 'whatsapp', 'whatsapp' => '+6281234567890', 'email' => 'hello@example.com'],
        'week_start' => 7,
        'time_format' => '12h',
    ])->assertOk()
        ->assertJsonPath('data.layout', 'grid')
        ->assertJsonPath('data.contact.primary', 'whatsapp')
        ->assertJsonPath('data.week_start', 7);

    Space::factory()->for($user)->published()->create(['visibility' => 'public', 'type' => 'blog', 'slug' => 'news']);
    Space::factory()->for($user)->published()->create(['visibility' => 'link', 'slug' => 'hidden']);
    SocialApprovalLink::create(['user_id' => $user->id, 'title' => 'December plan', 'visibility' => 'public']);
    SocialApprovalLink::create(['user_id' => $user->id, 'title' => 'Not listed']);

    $this->app['auth']->forgetGuards();
    $page = $this->getJson('/api/v1/profiles/rizaltester')->assertOk()
        ->assertJsonPath('data.layout', 'grid')
        ->assertJsonPath('data.contact.primary', 'whatsapp')
        ->json('data.pages');
    expect(collect($page)->pluck('kind')->all())->toContain('blog', 'content_plan')
        ->and(collect($page)->pluck('title')->all())->not->toContain('Not listed');
});

it('connects a custom domain on a paid plan only, and resolves it once verified', function () {
    $free = profileOwner(false);
    $this->actingAs($free)->putJson('/api/v1/me/domain', ['domain' => 'studio.example.com'])->assertStatus(402);

    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Handle::factory()->for($user)->create(['name' => 'studio']);
    $this->actingAs($user)->putJson('/api/v1/me/domain', ['domain' => 'Studio.Example.com'])->assertOk()
        ->assertJsonPath('data.domain', 'studio.example.com')
        ->assertJsonPath('data.verified', false)
        ->assertJsonPath('data.record.value', 'creatif.space');
    $this->actingAs($user)->putJson('/api/v1/me/domain', ['domain' => 'creatif.space'])->assertStatus(422);

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/public/domains/studio.example.com')->assertNotFound();
    Profile::query()->where('user_id', $user->id)->update(['custom_domain_verified_at' => now()]);
    $this->getJson('/api/v1/public/domains/studio.example.com')->assertOk()->assertJsonPath('data.handle', 'studio');
});

it('counts link taps per day', function () {
    $user = profileOwner();
    $this->postJson('/api/v1/profiles/rizaltester/taps', ['target' => 'contact:whatsapp'])->assertNoContent();
    $this->postJson('/api/v1/profiles/rizaltester/taps', ['target' => 'contact:whatsapp'])->assertNoContent();
    $this->postJson('/api/v1/profiles/rizaltester/taps', ['target' => 'bad target!'])->assertStatus(422);

    $this->actingAs($user)->getJson('/api/v1/me/link-taps')->assertOk()
        ->assertJsonPath('data.0.target', 'contact:whatsapp')
        ->assertJsonPath('data.0.taps', 2);
});

it('starts new accounts on the System theme', function () {
    $user = User::factory()->create();
    expect($user->fresh()->theme)->toBe('system');
});
