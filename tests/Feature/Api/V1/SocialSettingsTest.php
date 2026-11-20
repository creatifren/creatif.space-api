<?php

use App\Models\SocialPost;
use App\Models\User;

it('starts empty and keeps the sections it was not sent', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/me/social-settings')
        ->assertOk()
        ->assertJsonPath('data.timetable', [])
        ->assertJsonPath('data.views', []);

    $this->actingAs($user)->patchJson('/api/v1/me/social-settings', [
        'timetable' => [['day' => 0, 'time' => '19:00'], ['day' => 4, 'time' => '19:00']],
    ])->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/me/social-settings', [
        'labels' => [['name' => 'Promo', 'color' => 'clay']],
    ])->assertOk()
        ->assertJsonCount(2, 'data.timetable')
        ->assertJsonPath('data.labels.0.name', 'Promo');
});

it('refuses a time that is not HH:MM and a day outside the week', function () {
    $this->actingAs(User::factory()->create())
        ->patchJson('/api/v1/me/social-settings', ['timetable' => [['day' => 7, 'time' => '7pm']]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['timetable.0.day', 'timetable.0.time']);
});

it('carries a renamed label onto the posts that wear it', function () {
    $user = User::factory()->create();
    $post = SocialPost::factory()->for($user)->create(['labels' => ['Promo', 'Launch']]);
    $other = SocialPost::factory()->create(['labels' => ['Promo']]); // someone else's

    $this->actingAs($user)->patchJson('/api/v1/me/social-settings', [
        'labels' => [['name' => 'Sale', 'color' => 'clay'], ['name' => 'Launch', 'color' => 'sea']],
        'renamed' => ['Promo' => 'Sale'],
    ])->assertOk()->assertJsonMissingPath('data.renamed');

    expect($post->fresh()->labels)->toBe(['Sale', 'Launch'])
        ->and($other->fresh()->labels)->toBe(['Promo']);
});

it('labels any post, even a published one, without touching the provider', function () {
    $user = User::factory()->create();
    $post = SocialPost::factory()->for($user)->published()->create();

    $this->actingAs($user)
        ->patchJson("/api/v1/social-posts/{$post->ulid}", ['labels' => ['Promo']])
        ->assertOk()
        ->assertJsonPath('data.labels', ['Promo']);
});

it('v10.1 Post settings: approval_default and remind_approver start on and switch off', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/me/social-settings')
        ->assertOk()
        ->assertJsonPath('data.approval_default', true)
        ->assertJsonPath('data.remind_approver', true);

    $this->actingAs($user)->patchJson('/api/v1/me/social-settings', ['approval_default' => false, 'remind_approver' => false])
        ->assertOk()
        ->assertJsonPath('data.approval_default', false)
        ->assertJsonPath('data.remind_approver', false);

    expect($user->fresh()->social_settings['remind_approver'])->toBeFalse();

    $this->actingAs($user)->patchJson('/api/v1/me/social-settings', ['remind_approver' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['remind_approver']);
});

it('P6: keeps saved replies (max 20, each up to 500 characters)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/me/social-settings')->assertJsonPath('data.saved_replies', []);
    $this->actingAs($user)->patchJson('/api/v1/me/social-settings', ['saved_replies' => ['Thanks so much!', 'We open daily 8:00–22:00.']])
        ->assertOk()->assertJsonPath('data.saved_replies.1', 'We open daily 8:00–22:00.');
    $this->actingAs($user)->patchJson('/api/v1/me/social-settings', ['saved_replies' => array_fill(0, 21, 'x')])
        ->assertUnprocessable();
});

it('v10.2: keeps hashtag sets per client and the First comment switch', function () {
    $user = User::factory()->create();
    $kopi = \App\Models\ClientLabel::create(['user_id' => $user->id, 'name' => 'Kopi Senja']);
    $other = \App\Models\ClientLabel::create(['user_id' => User::factory()->create()->id, 'name' => 'Not mine']);

    $this->actingAs($user)->getJson('/api/v1/me/social-settings')
        ->assertJsonPath('data.hashtag_sets', [])
        ->assertJsonPath('data.first_comment', false);

    $res = $this->actingAs($user)->patchJson('/api/v1/me/social-settings', [
        'first_comment' => true,
        'hashtag_sets' => [
            ['name' => 'Coffee', 'tags' => ['kopisenja', '#kopilokal #baristalife', '#kopisenja'], 'client_id' => $kopi->ulid],
            ['id' => 'hs_keep', 'name' => 'Theirs', 'tags' => ['#x'], 'client_id' => $other->ulid],
        ],
    ])->assertOk();

    $res->assertJsonPath('data.first_comment', true)
        ->assertJsonPath('data.hashtag_sets.0.tags', ['#kopisenja', '#kopilokal', '#baristalife'])
        ->assertJsonPath('data.hashtag_sets.0.client_id', $kopi->ulid)
        ->assertJsonPath('data.hashtag_sets.1.id', 'hs_keep')
        ->assertJsonPath('data.hashtag_sets.1.client_id', null);
    expect($res->json('data.hashtag_sets.0.id'))->toStartWith('hs_');

    $this->actingAs($user)->patchJson('/api/v1/me/social-settings', ['hashtag_sets' => [['name' => 'Empty', 'tags' => [], 'client_id' => null]]])
        ->assertUnprocessable();
});
