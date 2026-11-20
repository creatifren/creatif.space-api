<?php

use App\Models\Handle;
use App\Models\Plan;
use App\Models\SocialApprovalLink;
use App\Models\Space;
use App\Models\Subscription;
use App\Models\User;

/* Footer per plan (backlog #2, Builder B): every public page carries the
   footer; Free = full, Pro/Max = one line, Enterprise = none. The link
   credits the page's owner (?ref=footer&from={username}). */

function footerOwner(?string $plan, string $handle): User
{
    $user = User::factory()->create();
    if ($plan !== null) {
        Subscription::factory()->for($user)->create(['plan_id' => Plan::query()->where('key', $plan)->value('id')]);
    }
    Handle::factory()->for($user)->create(['name' => $handle]);

    return $user;
}

it('draws the footer by plan on the public profile', function (?string $plan, string $kind) {
    footerOwner($plan, 'footerowner');

    $this->getJson('/api/v1/profiles/footerowner')->assertOk()
        ->assertJsonPath('data.footer', $kind)
        ->assertJsonPath('data.footer_url', 'https://creatif.space/?ref=footer&from=footerowner');
})->with([
    'free' => [null, 'full'],
    'pro' => ['pro', 'line'],
    'max' => ['max', 'line'],
    'enterprise' => ['enterprise', 'none'],
]);

it('sends the footer with a public Space', function () {
    $user = footerOwner('pro', 'spaceowner');
    Space::factory()->for($user)->published()->create(['visibility' => 'link', 'slug' => 'kit']);

    $this->getJson('/api/v1/profiles/spaceowner/spaces/kit')->assertOk()
        ->assertJsonPath('data.footer', 'line');
});

it('gives the turned-off link page the footer and the owner contact', function () {
    $user = footerOwner(null, 'linkowner');
    $this->actingAs($user)->patchJson('/api/v1/me/profile', [
        'contact' => ['primary' => 'whatsapp', 'whatsapp' => '+6281234567890', 'email' => 'hello@example.com'],
    ])->assertOk();
    $this->app['auth']->forgetGuards();
    $link = SocialApprovalLink::create(['user_id' => $user->id, 'revoked_at' => now()]);

    $this->getJson("/api/v1/a/{$link->token}")->assertStatus(409)
        ->assertJsonPath('footer', 'full')
        ->assertJsonPath('contact.whatsapp', '+6281234567890')
        ->assertJsonPath('contact.email', 'hello@example.com');
});

it('saves a style preset on every plan, Free included', function () {
    $user = footerOwner(null, 'presetowner');

    $this->actingAs($user)->patchJson('/api/v1/me/profile', ['appearance' => ['preset' => 'studio']])
        ->assertOk()->assertJsonPath('data.appearance.preset', 'studio');
    $this->actingAs($user)->patchJson('/api/v1/me/profile', ['appearance' => ['preset' => 'neon']])
        ->assertStatus(422);
});
