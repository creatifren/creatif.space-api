<?php

use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/*
 * Rate limits on approval links and feedback, enforced (ErrorAlertsAndThrottlesTest
 * pins the numbers): the request past the limit is 429 with retry_after and a
 * Retry-After header, the same envelope everywhere.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    Cache::flush();
    Notification::fake();
    Workspace::forget();
});

/** @return array{User, SocialApprovalLink} */
function rlLink(): array
{
    $owner = User::query()->findOrFail(User::factory()->create()->id);
    Subscription::factory()->for($owner)->create();
    $post = SocialPost::factory()->for($owner)->create(['provider_post_id' => null, 'status' => 'scheduled', 'approval' => 'client', 'scheduled_at' => now()->addDays(2)]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($owner)->create()->id, 'status' => 'pending']);
    $link = SocialApprovalLink::create(['user_id' => $owner->id, 'title' => 'Nov', 'approver_email' => 'rani@client.test']);
    $link->posts()->attach($post->id);

    return [$owner, $link];
}

function rl429($response): void
{
    $response->assertStatus(429)->assertJsonStructure(['message', 'retry_after'])->assertHeader('Retry-After');
}

it('stops the public approval page after 60 a minute', function () {
    [, $link] = rlLink();
    foreach (range(1, 60) as $i) {
        $this->getJson("/api/v1/a/{$link->token}")->assertOk();
    }
    rl429($this->getJson("/api/v1/a/{$link->token}"));
});

it('stops sign-in link emails after 5 a minute', function () {
    [, $link] = rlLink();
    foreach (range(1, 5) as $i) {
        $this->postJson("/api/v1/a/{$link->token}/email-link", ['email' => 'rani@client.test'])->assertStatus(202);
    }
    rl429($this->postJson("/api/v1/a/{$link->token}/email-link", ['email' => 'rani@client.test']));
});

it('stops the owner’s approval-link list after 60 a minute, per user', function () {
    [$owner] = rlLink();
    foreach (range(1, 60) as $i) {
        $this->actingAs($owner)->getJson('/api/v1/social-approval-links')->assertOk();
    }
    rl429($this->actingAs($owner)->getJson('/api/v1/social-approval-links'));

    [$other] = rlLink();
    $this->actingAs($other)->getJson('/api/v1/social-approval-links')->assertOk();
});

it('stops feedback after 10 an hour, and lets it through again after the hour', function () {
    $user = User::query()->findOrFail(User::factory()->create()->id);
    foreach (range(1, 10) as $i) {
        $this->actingAs($user)->postJson('/api/v1/feedback', ['kind' => 'idea', 'message' => "Idea {$i}"])->assertCreated();
    }
    rl429($this->actingAs($user)->postJson('/api/v1/feedback', ['kind' => 'idea', 'message' => 'One more']));

    $this->travel(61)->minutes();
    $this->actingAs($user)->postJson('/api/v1/feedback', ['kind' => 'idea', 'message' => 'Next hour'])->assertCreated();
});
