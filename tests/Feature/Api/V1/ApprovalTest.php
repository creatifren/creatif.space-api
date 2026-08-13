<?php

use App\Models\Client;
use App\Models\DriveAccount;
use App\Models\DriveFile;
use App\Models\Handle;
use App\Models\Space;
use App\Models\SpaceItem;
use App\Models\User;
use App\Notifications\ApprovalDecided;
use Illuminate\Support\Facades\Notification;

function approvalSpace(array $attributes = []): Space
{
    $owner = User::factory()->create(['name' => 'Rani']);
    Handle::factory()->for($owner)->create(['name' => 'rani']);

    $space = Space::factory()->for($owner)->published()->create(array_merge([
        'slug' => 'winter-noel',
        'approval_enabled' => true,
    ], $attributes));

    $account = DriveAccount::factory()->for($owner)->create();

    foreach (['one.jpg', 'two.jpg'] as $index => $name) {
        $file = DriveFile::factory()->for($account, 'account')->create([
            'name' => $name,
            'version_hash' => 'v1-'.$name,
        ]);
        SpaceItem::factory()->for($space)->create([
            'drive_file_id' => $file->id,
            'sort_order' => $index,
        ]);
    }

    return $space->refresh()->load('items.driveFile');
}

describe('client decisions', function () {
    it('records an approval with the file version snapshotted', function () {
        Notification::fake();
        $space = approvalSpace();
        $item = $space->items->first();
        $client = Client::factory()->create();

        $this->actingAs($client, 'client')
            ->postJson('/api/v1/profiles/rani/spaces/winter-noel/approvals', [
                'item_id' => $item->ulid,
                'decision' => 'approve',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.file_name', 'one.jpg');

        $approval = $item->approvals()->first();
        expect($approval->version_hash_at_approval)->toBe('v1-one.jpg')
            ->and($approval->approved_at)->not->toBeNull();

        Notification::assertSentTo($space->user, ApprovalDecided::class);
    });

    it('refuses a revision with no note, and keeps the one that has one', function () {
        Notification::fake();
        $space = approvalSpace();
        $item = $space->items->first();
        $client = Client::factory()->create();

        $this->actingAs($client, 'client')
            ->postJson('/api/v1/profiles/rani/spaces/winter-noel/approvals', [
                'item_id' => $item->ulid,
                'decision' => 'revise',
            ])
            ->assertUnprocessable();

        $this->actingAs($client, 'client')
            ->postJson('/api/v1/profiles/rani/spaces/winter-noel/approvals', [
                'item_id' => $item->ulid,
                'decision' => 'revise',
                'note' => 'The croissant is cut off on the right.',
                'chips' => ['Crop / framing'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'revision')
            ->assertJsonPath('data.note.chips.0', 'Crop / framing');

        expect($item->approvals()->count())->toBe(1);
    });

    it('keeps one decision per client per file, replacing the old one', function () {
        Notification::fake();
        $space = approvalSpace();
        $item = $space->items->first();
        $client = Client::factory()->create();

        foreach (['approve', 'approve'] as $decision) {
            $this->actingAs($client, 'client')
                ->postJson('/api/v1/profiles/rani/spaces/winter-noel/approvals', [
                    'item_id' => $item->ulid,
                    'decision' => $decision,
                ])->assertCreated();
        }

        expect($item->approvals()->count())->toBe(1);
    });

    it('approves everything at once, skipping files we cannot reach', function () {
        Notification::fake();
        $space = approvalSpace();
        $space->items->first()->driveFile->forceFill(['access_lost_at' => now()])->save();
        $client = Client::factory()->create();

        $this->actingAs($client, 'client')
            ->postJson('/api/v1/profiles/rani/spaces/winter-noel/approvals/all')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // One gesture, one email — never a message per file.
        Notification::assertSentToTimes($space->user, ApprovalDecided::class, 1);
    });

    it('asks for a revision on the whole Space, note landing once', function () {
        Notification::fake();
        $space = approvalSpace();
        $client = Client::factory()->create();

        $this->actingAs($client, 'client')
            ->postJson('/api/v1/profiles/rani/spaces/winter-noel/approvals/all')
            ->assertOk();

        // Everything is signed off — a fresh client still gets to disagree.
        $other = Client::factory()->create();
        $this->actingAs($other, 'client')
            ->postJson('/api/v1/profiles/rani/spaces/winter-noel/approvals/all', [
                'decision' => 'revise',
            ])->assertUnprocessable();

        $response = $this->actingAs($other, 'client')
            ->postJson('/api/v1/profiles/rani/spaces/winter-noel/approvals/all', [
                'decision' => 'revise',
                'note' => 'The whole set feels too warm — please cool the grade.',
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'revision');

        // The Space-wide note lands once, not copied onto every file.
        $notes = collect($response->json('data'))->pluck('note')->filter();
        expect($notes)->toHaveCount(1);
    });

    it('rejects a Space that never asked for an approval', function () {
        $space = approvalSpace(['approval_enabled' => false]);

        $this->actingAs(Client::factory()->create(), 'client')
            ->postJson('/api/v1/profiles/rani/spaces/winter-noel/approvals', [
                'item_id' => $space->items->first()->ulid,
                'decision' => 'approve',
            ])
            ->assertUnprocessable();
    });

    it('rejects a file that belongs to another Space', function () {
        Notification::fake();
        approvalSpace();
        $elsewhere = SpaceItem::factory()->create();

        $this->actingAs(Client::factory()->create(), 'client')
            ->postJson('/api/v1/profiles/rani/spaces/winter-noel/approvals', [
                'item_id' => $elsewhere->ulid,
                'decision' => 'approve',
            ])
            ->assertUnprocessable();
    });

    it('rejects visitors who have not signed in', function () {
        $space = approvalSpace();

        $this->postJson('/api/v1/profiles/rani/spaces/winter-noel/approvals', [
            'item_id' => $space->items->first()->ulid,
            'decision' => 'approve',
        ])->assertUnauthorized();
    });

    it('honours the viewer gates: expired wins, locked blocks', function () {
        $client = Client::factory()->create();

        $expired = approvalSpace(['expires_at' => now()->subDay()]);
        $this->actingAs($client, 'client')
            ->postJson('/api/v1/profiles/rani/spaces/winter-noel/approvals', [
                'item_id' => $expired->items->first()->ulid,
                'decision' => 'approve',
            ])->assertStatus(410);
    });
});

describe('the viewer payload', function () {
    it('shows a stranger the approval is live but no marks', function () {
        approvalSpace();

        $response = $this->getJson('/api/v1/profiles/rani/spaces/winter-noel')->assertOk();

        expect($response->json('data.approval.enabled'))->toBeTrue()
            ->and($response->json('data.approval.viewer'))->toBeNull()
            ->and($response->json('data.approval.total'))->toBe(2);
    });

    it('shows the signed-in client their own marks, and nobody else’s', function () {
        Notification::fake();
        $space = approvalSpace();
        $item = $space->items->first();
        $mine = Client::factory()->create(['name' => 'Andi']);
        $other = Client::factory()->create();

        $this->actingAs($other, 'client')
            ->postJson('/api/v1/profiles/rani/spaces/winter-noel/approvals', [
                'item_id' => $item->ulid,
                'decision' => 'approve',
            ])->assertCreated();

        $response = $this->actingAs($mine, 'client')
            ->getJson('/api/v1/profiles/rani/spaces/winter-noel')
            ->assertOk();

        expect($response->json('data.approval.viewer.name'))->toBe('Andi')
            ->and($response->json('data.approval.approved'))->toBe(0);
    });
});
