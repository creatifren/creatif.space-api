<?php

use App\Models\Plan;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;

/*
 * /a/{token} and /plan/{token} say whether the owner's plan removes
 * branding (the same feature public Spaces read), so the app can hide its
 * footer slogan on those pages.
 */

function brandingOwner(?string $plan): User
{
    $user = User::factory()->create();
    if ($plan !== null) {
        Subscription::factory()->for($user)->create(['plan_id' => Plan::query()->where('key', $plan)->value('id')]);
    }

    return $user;
}

function approvalTokenFor(User $user): string
{
    $link = SocialApprovalLink::create(['user_id' => $user->id, 'title' => 'Desember', 'client_name' => 'Andi']);
    $link->posts()->attach(SocialPost::factory()->for($user)->create(['approval' => 'awaiting'])->id);

    return $link->token;
}

function planTokenFor(User $user): string
{
    $url = test()->actingAs($user)->postJson('/api/v1/social-plan-links', [
        'title' => 'November',
        'starts_at' => '2026-11-01T00:00:00Z',
        'ends_at' => '2026-11-30T23:59:59Z',
    ])->assertCreated()->json('data.url');
    app('auth')->forgetGuards();

    return basename((string) $url);
}

it('reports branding_removed on the public approval page from the owner\'s plan', function (?string $plan, bool $removed) {
    $token = approvalTokenFor(brandingOwner($plan));

    $this->getJson("/api/v1/a/{$token}")
        ->assertOk()
        ->assertJsonPath('data.branding_removed', $removed);
})->with([
    'free' => [null, false],
    'max' => ['max', true],
]);

it('reports branding_removed on the public plan page from the owner\'s plan', function (?string $plan, bool $removed) {
    $token = planTokenFor(brandingOwner($plan));

    $this->getJson("/api/v1/plan/{$token}")
        ->assertOk()
        ->assertJsonPath('data.branding_removed', $removed);
})->with([
    'free' => [null, false],
    'max' => ['max', true],
]);

it('follows the plan feature itself, not the plan name', function () {
    $owner = brandingOwner('max');
    $max = Plan::query()->where('key', 'max')->firstOrFail();
    $max->update(['features' => [...$max->features, 'branding_removed' => false]]);

    $this->getJson('/api/v1/a/'.approvalTokenFor($owner))->assertJsonPath('data.branding_removed', false);
    $this->getJson('/api/v1/plan/'.planTokenFor($owner))->assertJsonPath('data.branding_removed', false);
});
