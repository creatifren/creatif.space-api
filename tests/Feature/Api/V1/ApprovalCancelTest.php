<?php

use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\Client;
use App\Models\File;
use App\Models\Space;
use App\Models\SpaceItem;
use App\Models\User;

/**
 * Arrange a signed-off file. Files are immutable once ready, so the only
 * ways a decision dies are the owner's reset and the item leaving the Space.
 *
 * @return array{0: Approval, 1: File, 2: User}
 */
function approvedFile(): array
{
    $owner = User::factory()->create();
    $space = Space::factory()->for($owner)->published()->create(['approval_enabled' => true]);

    $file = File::factory()->for($owner)->create(['name' => 'winter-noel-07.jpg']);

    $item = SpaceItem::factory()->for($space)->create(['file_id' => $file->id]);
    $approval = Approval::factory()->for($item, 'spaceItem')->approved()->create();

    return [$approval, $file, $owner];
}

it('resets a whole Space back to pending when the owner asks', function () {
    [$approval] = approvedFile();
    $space = $approval->spaceItem->space;

    $this->actingAs($space->user)
        ->postJson("/api/v1/spaces/{$space->ulid}/approvals/reset")
        ->assertOk()
        ->assertJsonPath('data.reset', 1);

    expect($approval->refresh()->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->cancelled_reason->value)->toBe('owner_reset')
        ->and($approval->approved_at)->toBeNull();
});

it('resets revisions too, and counts every decision it touched', function () {
    [$approval] = approvedFile();
    $space = $approval->spaceItem->space;

    Approval::factory()
        ->for($approval->spaceItem, 'spaceItem')
        ->for(Client::factory(), 'client')
        ->revision()
        ->create();

    $this->actingAs($space->user)
        ->postJson("/api/v1/spaces/{$space->ulid}/approvals/reset")
        ->assertOk()
        ->assertJsonPath('data.reset', 2);

    expect($approval->spaceItem->approvals()->where('status', ApprovalStatus::Pending)->count())
        ->toBe(2);
});

it('leaves pending decisions alone on reset', function () {
    [$approval] = approvedFile();
    $space = $approval->spaceItem->space;

    $pending = Approval::factory()
        ->for($approval->spaceItem, 'spaceItem')
        ->for(Client::factory(), 'client')
        ->create();

    $this->actingAs($space->user)
        ->postJson("/api/v1/spaces/{$space->ulid}/approvals/reset")
        ->assertOk()
        ->assertJsonPath('data.reset', 1);

    // Untouched: no cancelled_reason stamped onto a decision never given.
    expect($pending->refresh()->cancelled_reason)->toBeNull();
});

it('404s when someone else tries to reset your Space', function () {
    [$approval] = approvedFile();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/v1/spaces/{$approval->spaceItem->space->ulid}/approvals/reset")
        ->assertNotFound();
});

it('cascades approvals when the item leaves the Space', function () {
    [$approval] = approvedFile();

    $approval->spaceItem->delete();

    // Replacing a photo means swapping the item, and the item takes its
    // approvals with it — the DB cascade, not a job.
    expect(Approval::query()->count())->toBe(0);
});

it('always answers an empty Needs Attention strip', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/me/attention')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});
