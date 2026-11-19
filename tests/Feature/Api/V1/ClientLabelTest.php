<?php

use App\Models\ClientLabel;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Credits;

/* v7 client labels: clients are labels on social accounts. One account can
   carry several; a post belongs to every label on its accounts. */

function labelOwner(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, 30_000, 'Test');

    return $user;
}

function labelPost(User $user, SocialAccount $account, string $approval = 'awaiting'): SocialPost
{
    $post = SocialPost::factory()->for($user)->create(['provider_post_id' => null, 'approval' => $approval]);
    $post->targets()->create(['social_account_id' => $account->id, 'status' => 'pending']);

    return $post;
}

it('creates, lists, edits and removes a client with its accounts', function () {
    $user = labelOwner();
    $ig = SocialAccount::factory()->for($user)->create(['username' => '@ombakgroup']);
    $fb = SocialAccount::factory()->for($user)->create(['platform' => 'facebook', 'username' => 'Hotel Ombak']);

    $id = $this->actingAs($user)->postJson('/api/v1/client-labels', [
        'name' => 'Hotel Ombak',
        'color' => '#3E6A8A',
        'approver_email' => 'Made@Ombak.test',
        'account_ids' => [$ig->ulid, $fb->ulid],
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Hotel Ombak')
        ->assertJsonPath('data.initials', 'HO')
        ->assertJsonPath('data.approver_email', 'made@ombak.test')
        ->assertJsonPath('data.report_email', true)
        ->assertJsonCount(2, 'data.accounts')
        ->json('data.id');

    $this->actingAs($user)->postJson('/api/v1/client-labels', ['name' => 'Hotel Ombak'])->assertStatus(422);

    $this->actingAs($user)->patchJson("/api/v1/client-labels/{$id}", ['initials' => 'ho', 'account_ids' => [$ig->ulid], 'report_email' => false])
        ->assertOk()
        ->assertJsonPath('data.initials', 'HO')
        ->assertJsonPath('data.report_email', false)
        ->assertJsonCount(1, 'data.accounts');

    $this->actingAs($user)->getJson('/api/v1/client-labels')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($user)->deleteJson("/api/v1/client-labels/{$id}")->assertNoContent();
    expect(ClientLabel::count())->toBe(0)
        ->and(SocialAccount::count())->toBe(2);
});

it('puts several clients on one shared account, and a post there belongs to all of them', function () {
    $user = labelOwner();
    $shared = SocialAccount::factory()->for($user)->create(['username' => '@ombakgroup']);
    $only = SocialAccount::factory()->for($user)->create(['username' => '@kopisenja']);
    $hotel = ClientLabel::create(['user_id' => $user->id, 'name' => 'Hotel Ombak']);
    $lombok = ClientLabel::create(['user_id' => $user->id, 'name' => 'Ombak Lombok']);
    $kopi = ClientLabel::create(['user_id' => $user->id, 'name' => 'Kopi Senja']);

    $this->actingAs($user)->putJson("/api/v1/social-accounts/{$shared->ulid}/client-labels", ['client_ids' => [$hotel->ulid, $lombok->ulid]])
        ->assertOk()
        ->assertJsonPath('data.clients.0.name', 'Hotel Ombak')
        ->assertJsonPath('data.clients.1.name', 'Ombak Lombok');
    $kopi->accounts()->attach($only->id);

    $onShared = labelPost($user, $shared);
    labelPost($user, $only);

    $this->actingAs($user)->getJson('/api/v1/social-posts?client_ids[]='.$lombok->ulid)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $onShared->ulid)
        ->assertJsonPath('data.0.clients.0.name', 'Hotel Ombak')
        ->assertJsonPath('data.0.clients.1.name', 'Ombak Lombok');

    $this->actingAs($user)->getJson("/api/v1/client-labels/{$hotel->ulid}")
        ->assertOk()
        ->assertJsonPath('data.accounts.0.shared', true)
        ->assertJsonPath('data.posts', 1);

    $this->actingAs($user)->getJson('/api/v1/social-accounts')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Kopi Senja']);
});

it('makes an approval link for a client: name and approver come from the label', function () {
    $user = labelOwner();
    $account = SocialAccount::factory()->for($user)->create();
    $label = ClientLabel::create(['user_id' => $user->id, 'name' => 'Kopi Senja', 'approver_email' => 'dewi@kopi.test']);
    $post = labelPost($user, $account);

    $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['client_id' => $label->ulid, 'post_ids' => [$post->ulid]])
        ->assertCreated()
        ->assertJsonPath('data.client_name', 'Kopi Senja')
        ->assertJsonPath('data.client.id', $label->ulid)
        ->assertJsonPath('data.approver_email', 'dewi@kopi.test');
});

it("keeps clients to their owner", function () {
    $user = labelOwner();
    $theirs = ClientLabel::create(['user_id' => User::factory()->create()->id, 'name' => 'Theirs']);
    $account = SocialAccount::factory()->for($user)->create();

    $this->actingAs($user)->getJson("/api/v1/client-labels/{$theirs->ulid}")->assertNotFound();
    $this->actingAs($user)->patchJson("/api/v1/client-labels/{$theirs->ulid}", ['name' => 'Mine'])->assertNotFound();
    $this->actingAs($user)->putJson("/api/v1/social-accounts/{$account->ulid}/client-labels", ['client_ids' => [$theirs->ulid]])->assertStatus(422);
    $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['client_id' => $theirs->ulid, 'post_ids' => ['x'], 'approver_email' => 'a@b.test'])
        ->assertStatus(422)->assertJsonValidationErrors('client_id');
});
