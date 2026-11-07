<?php

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;

it('shares a read-only plan of the range and accounts it was made for', function () {
    $user = User::factory()->create();
    $ig = SocialAccount::factory()->for($user)->create();
    $tt = SocialAccount::factory()->for($user)->create();

    $post = fn (string $at, SocialAccount $a) => tap(
        SocialPost::factory()->for($user)->create(['scheduled_at' => $at, 'status' => 'scheduled']),
        fn ($p) => $p->targets()->create(['social_account_id' => $a->id, 'status' => 'pending']),
    );
    $in = $post('2026-11-10 10:00:00', $ig);
    $post('2026-11-11 10:00:00', $tt);      // other account
    $post('2026-12-05 10:00:00', $ig);      // outside the range

    $url = $this->actingAs($user)->postJson('/api/v1/social-plan-links', [
        'title' => 'November',
        'starts_at' => '2026-11-01T00:00:00Z',
        'ends_at' => '2026-11-30T23:59:59Z',
        'account_ids' => [$ig->ulid],
        'sections' => ['grid', 'feed'],
    ])->assertCreated()->json('data.url');

    $token = basename($url);
    $this->app['auth']->forgetGuards();

    $this->getJson("/api/v1/plan/{$token}")
        ->assertOk()
        ->assertJsonPath('data.title', 'November')
        ->assertJsonPath('data.sections', ['grid', 'feed'])
        ->assertJsonCount(1, 'data.posts')
        ->assertJsonPath('data.posts.0.id', $in->ulid)
        ->assertJsonMissingPath('data.posts.0.provider_post_id');

    $id = $this->actingAs($user)->getJson('/api/v1/social-plan-links')->json('data.0.id');
    $this->actingAs($user)->deleteJson("/api/v1/social-plan-links/{$id}")->assertOk();
    $this->app['auth']->forgetGuards();

    $this->getJson("/api/v1/plan/{$token}")->assertStatus(409);
    $this->getJson('/api/v1/plan/nope')->assertNotFound();
});

it('refuses another user\'s accounts and links', function () {
    $user = User::factory()->create();
    $other = SocialAccount::factory()->for(User::factory()->create())->create();

    $this->actingAs($user)->postJson('/api/v1/social-plan-links', [
        'starts_at' => '2026-11-01', 'ends_at' => '2026-11-30', 'account_ids' => [$other->ulid],
    ])->assertStatus(422);

    $this->actingAs($user)->postJson('/api/v1/social-plan-links', [
        'starts_at' => '2026-11-01', 'ends_at' => '2026-11-30', 'sections' => ['stories'],
    ])->assertStatus(422);

    $link = $this->actingAs($other->user)->postJson('/api/v1/social-plan-links', [
        'starts_at' => '2026-11-01', 'ends_at' => '2026-11-30',
    ])->json('data.id');
    $this->actingAs($user)->deleteJson("/api/v1/social-plan-links/{$link}")->assertForbidden();
});
