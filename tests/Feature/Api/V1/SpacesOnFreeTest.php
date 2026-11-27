<?php

use App\Models\Handle;
use App\Models\Space;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\PageReported;
use Illuminate\Support\Facades\Notification;

// v10.21 (Rizal 24 Nov): Spaces on every plan (storage only), Free anti-spam
// (10 new Spaces a day, noindex for 7 days, Report this page), Pro check badge.

function freeOwner(string $handle = 'rani'): User
{
    $user = User::factory()->create();
    Handle::factory()->for($user)->create(['name' => $handle]);

    return $user;
}

it('has no daily limit on Free either (Rizal 27 Nov: storage is the limit)', function () {
    $user = freeOwner();
    Space::factory()->count(12)->for($user)->create();

    $this->actingAs($user)->postJson('/api/v1/spaces', ['title' => 'Thirteenth'])->assertCreated();
    $this->actingAs($user)->getJson('/api/v1/spaces')->assertOk()
        ->assertJsonPath('meta.quota.daily_limit', null)
        ->assertJsonPath('meta.quota.total_limit', null);
});

it('has no daily limit on Pro', function () {
    $user = freeOwner();
    Subscription::factory()->for($user)->create(['status' => 'active']);
    Space::factory()->count(12)->for($user)->create();

    $this->actingAs($user)->postJson('/api/v1/spaces', ['title' => 'Thirteenth'])->assertCreated();
});

it('marks a Free owner\'s new Space noindex for 7 days; paid and older Spaces are indexable', function () {
    $user = freeOwner();
    $new = Space::factory()->for($user)->published()->create(['slug' => 'new-one']);
    $old = Space::factory()->for($user)->published()->create(['slug' => 'old-one', 'created_at' => now()->subDays(8)]);

    $this->getJson('/api/v1/profiles/rani/spaces/new-one')->assertOk()->assertJsonPath('data.noindex', true)->assertJsonPath('data.owner_pro', false);
    $this->getJson('/api/v1/profiles/rani/spaces/old-one')->assertOk()->assertJsonPath('data.noindex', false);

    Subscription::factory()->for($user)->create(['status' => 'active']);
    $this->getJson('/api/v1/profiles/rani/spaces/new-one')->assertOk()->assertJsonPath('data.noindex', false)->assertJsonPath('data.owner_pro', true);
});

it('tells the public profile whether the owner is Pro (check badge)', function () {
    $free = freeOwner('free-one');
    $pro = freeOwner('pro-one');
    Subscription::factory()->for($pro)->create(['status' => 'active']);

    $this->getJson('/api/v1/profiles/free-one')->assertOk()->assertJsonPath('data.owner_pro', false);
    $this->getJson('/api/v1/profiles/pro-one')->assertOk()->assertJsonPath('data.owner_pro', true);
});

it('emails a reported page to the team inbox, without sign-in, and ignores the honeypot', function () {
    Notification::fake();
    freeOwner();

    $this->postJson('/api/v1/public/report-page', ['url' => 'https://creatif.space/rani/winter', 'reason' => 'Spam'])->assertStatus(202);
    Notification::assertSentOnDemand(PageReported::class, fn ($n) => $n->reason === 'Spam' && $n->ownerEmail !== null);

    $this->postJson('/api/v1/public/report-page', ['url' => 'https://example.com/x', 'reason' => 'Spam'])->assertUnprocessable();
    $this->postJson('/api/v1/public/report-page', ['url' => 'https://creatif.space/rani', 'reason' => 'Because'])->assertUnprocessable();

    Notification::fake();
    $this->postJson('/api/v1/public/report-page', ['url' => 'https://creatif.space/rani', 'reason' => 'Spam', 'website' => 'x'])->assertStatus(202);
    Notification::assertNothingSent();
});
