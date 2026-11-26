<?php

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;

/*
 * MCP update_post pinterest_board_id (26 Nov 2026): a draft's per-channel
 * options (the Pinterest board) change through PATCH, only on a draft.
 */

function pinDraft(string $status = 'draft'): array
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    $pin = SocialAccount::factory()->for($user)->create(['platform' => 'pinterest', 'provider' => 'outstand', 'username' => 'kopi_pins']);
    $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram', 'provider' => 'outstand', 'username' => 'kopisenja']);
    $post = SocialPost::factory()->for($user)->create(['provider' => 'outstand', 'status' => $status]);
    $post->targets()->create(['social_account_id' => $pin->id, 'status' => 'pending', 'configuration' => ['title' => 'Kopi']]);
    $post->targets()->create(['social_account_id' => $ig->id, 'status' => 'pending']);

    return [$user, $post, $pin, $ig];
}

it('sets the Pinterest board on a draft, keeping the channel\'s other options', function () {
    [$user, $post, $pin] = pinDraft();
    $this->actingAs($user)->patchJson("/api/v1/social-posts/{$post->ulid}", ['options' => [$pin->ulid => ['board_id' => 'b_42']]])
        ->assertOk();
    $target = $post->targets()->where('social_account_id', $pin->id)->first();
    expect($target->configuration)->toBe(['title' => 'Kopi', 'board_id' => 'b_42']);
});

it('refuses options for a channel that is not on the post', function () {
    [$user, $post] = pinDraft();
    $other = SocialAccount::factory()->for($user)->create(['platform' => 'pinterest', 'provider' => 'outstand']);
    $this->actingAs($user)->patchJson("/api/v1/social-posts/{$post->ulid}", ['options' => [$other->ulid => ['board_id' => 'b_1']]])
        ->assertStatus(422);
});

it('changes options only on a draft', function () {
    [$user, $post, $pin] = pinDraft('scheduled');
    $this->actingAs($user)->patchJson("/api/v1/social-posts/{$post->ulid}", ['options' => [$pin->ulid => ['board_id' => 'b_42']]])
        ->assertStatus(409)->assertJsonPath('code', 'not_editable');
});
