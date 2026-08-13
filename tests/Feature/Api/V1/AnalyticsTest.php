<?php

use App\Enums\SpaceEventType;
use App\Models\Client;
use App\Models\DriveAccount;
use App\Models\DriveFile;
use App\Models\Plan;
use App\Models\Space;
use App\Models\SpaceDailyStat;
use App\Models\SpaceEvent;
use App\Models\SpaceItem;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

/**
 * A creator on Premium — the plan whose `full_analytics` feature is what
 * every gate below reads.
 */
function premiumCreator(): User
{
    $user = User::factory()->create();

    Subscription::factory()->for($user)->create([
        'plan_id' => Plan::query()->where('key', 'premium')->value('id'),
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
    ]);

    return $user;
}

function photoIn(Space $space): SpaceItem
{
    $account = DriveAccount::factory()->for($space->user)->create();
    $file = DriveFile::factory()->for($account, 'account')->create();

    return $space->items()->create(['drive_file_id' => $file->id, 'sort_order' => 0]);
}

function statsFor(Space $space, int $views, int $unique = 1, ?string $date = null): SpaceDailyStat
{
    return SpaceDailyStat::query()->create([
        'space_id' => $space->id,
        'date' => $date ?? now()->toDateString(),
        'views' => $views,
        'unique_visitors' => $unique,
        'ai_crawler_hits' => 0,
    ]);
}

describe('the free headline', function () {
    it('gives views and a bar per day, and says the plan is not full', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->published()->create();
        statsFor($space, 40, 12);

        $response = $this->actingAs($user)->getJson('/api/v1/me/analytics')->assertOk();

        expect($response->json('data.full'))->toBeFalse()
            ->and($response->json('data.views'))->toBe(40)
            // One bar per day of the month, whatever today is.
            ->and($response->json('data.bars'))->toHaveCount(now()->daysInMonth)
            ->and($response->json('data.axis'))->toHaveCount(5);
    });

    it('ships premium blocks as null, not as missing keys', function () {
        $user = User::factory()->create();

        $data = $this->actingAs($user)->getJson('/api/v1/me/analytics')->json('data');

        // null means "not on your plan"; [] would mean "yours, and empty".
        // The blur-and-upgrade layout depends on telling those apart.
        foreach (['unique', 'sources', 'files', 'viewers', 'crawlers', 'hours', 'sales', 'aeo'] as $key) {
            expect($data)->toHaveKey($key)
                ->and($data[$key])->toBeNull();
        }
    });

    it('refuses a custom range on Free with a sentence, not a 403', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/me/analytics?range=custom&from=2026-01-01&to=2026-02-01')
            ->assertUnprocessable()
            ->assertJsonPath('errors.range.0', 'Picking your own dates is part of Premium.');
    });

    it('counts only this creator’s Spaces', function () {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        statsFor(Space::factory()->for($mine)->published()->create(), 10);
        statsFor(Space::factory()->for($theirs)->published()->create(), 999);

        expect($this->actingAs($mine)->getJson('/api/v1/me/analytics')->json('data.views'))
            ->toBe(10);
    });

    it('scopes to one Space when asked, and to nothing for a ulid that is not theirs', function () {
        $user = User::factory()->create();
        $one = Space::factory()->for($user)->published()->create();
        $two = Space::factory()->for($user)->published()->create();
        statsFor($one, 10);
        statsFor($two, 25);

        $stranger = Space::factory()->published()->create();

        expect($this->actingAs($user)->getJson("/api/v1/me/analytics?space={$one->ulid}")->json('data.views'))
            ->toBe(10)
            ->and($this->actingAs($user)->getJson("/api/v1/me/analytics?space={$stranger->ulid}")->json('data.views'))
            ->toBe(0);
    });

    it('names every Space in the filter, including ones with no traffic yet', function () {
        $user = User::factory()->create();
        Space::factory()->for($user)->create(['title' => 'Draft, never opened']);

        $spaces = $this->actingAs($user)->getJson('/api/v1/me/analytics')->json('data.spaces');

        expect($spaces)->toHaveCount(1)
            ->and($spaces[0]['name'])->toBe('Draft, never opened');
    });
});

describe('the delta sentence', function () {
    it('compares with the window before it', function () {
        $user = User::factory()->create();
        $space = Space::factory()->for($user)->published()->create();

        statsFor($space, 100, 30, now()->startOfMonth()->toDateString());
        statsFor($space, 50, 20, now()->subMonthNoOverflow()->startOfMonth()->toDateString());

        $delta = $this->actingAs($user)->getJson('/api/v1/me/analytics')->json('data.delta');

        expect($delta)->toContain('+100%')
            ->and($delta)->toContain('50 views');
    });

    it('says nothing rather than inventing a percentage from zero', function () {
        $user = User::factory()->create();

        expect($this->actingAs($user)->getJson('/api/v1/me/analytics')->json('data.delta'))
            ->toBeNull();
    });
});

describe('premium', function () {
    it('opens every block, from real events', function () {
        $user = premiumCreator();
        $space = Space::factory()->for($user)->published()->create();
        $client = Client::factory()->create(['name' => 'Andi', 'email' => 'andi@winternoel.com']);

        SpaceEvent::factory()->count(2)->for($space)->from('instagram.com')->create();
        SpaceEvent::factory()->for($space)->create(['client_id' => $client->id]);
        SpaceEvent::factory()->for($space)->crawler('GPTBot')->create();
        SpaceEvent::factory()->for($space)->from('chatgpt.com')->create();
        statsFor($space, 5, 4);

        $data = $this->actingAs($user)->getJson('/api/v1/me/analytics')->json('data');

        expect($data['full'])->toBeTrue()
            ->and($data['unique'])->toBe(4)
            ->and($data['sources'])->toContain(['name' => 'instagram.com', 'views' => 2])
            ->and($data['viewers'][0]['who'])->toBe('Andi')
            ->and($data['crawlers'])->toHaveCount(1)
            ->and($data['crawlers'][0]['name'])->toBe('GPTBot')
            ->and($data['ai_refs'][0]['name'])->toBe('ChatGPT')
            ->and($data['hours'])->toHaveCount(24)
            ->and($data['aeo'])->toHaveCount(6);
    });

    it('calls a null referrer the Direct link, because that is what it is', function () {
        $user = premiumCreator();
        $space = Space::factory()->for($user)->published()->create();
        SpaceEvent::factory()->count(3)->for($space)->create();

        expect($this->actingAs($user)->getJson('/api/v1/me/analytics')->json('data.sources'))
            ->toBe([['name' => 'Direct link', 'views' => 3]]);
    });

    it('ranks files by what was opened, not by what was published', function () {
        $user = premiumCreator();
        $space = Space::factory()->for($user)->published()->create();
        $quiet = photoIn($space);
        $popular = photoIn($space);

        SpaceEvent::factory()->count(4)->for($space)->create([
            'type' => SpaceEventType::LightboxOpen,
            'space_item_id' => $popular->id,
        ]);
        SpaceEvent::factory()->for($space)->create([
            'type' => SpaceEventType::Download,
            'space_item_id' => $popular->id,
        ]);
        SpaceEvent::factory()->for($space)->create([
            'type' => SpaceEventType::LightboxOpen,
            'space_item_id' => $quiet->id,
        ]);

        $data = $this->actingAs($user)->getJson('/api/v1/me/analytics')->json('data');

        expect($data['files'][0]['views'])->toBe(4)
            ->and($data['files'][0]['downloads'])->toBe(1)
            ->and($data['opened'])->toBe(5)
            ->and($data['downloads'])->toBe(1);
    });

    it('accepts a custom range', function () {
        $user = premiumCreator();
        $space = Space::factory()->for($user)->published()->create();
        statsFor($space, 7, 3, now()->subDays(10)->toDateString());

        $response = $this->actingAs($user)->getJson(
            '/api/v1/me/analytics?range=custom&from='.now()->subDays(20)->toDateString()
                .'&to='.now()->toDateString(),
        )->assertOk();

        expect($response->json('data.views'))->toBe(7);
    });

    it('draws a year as months, not as 365 slivers', function () {
        $user = premiumCreator();
        $space = Space::factory()->for($user)->published()->create();
        statsFor($space, 12, 5);

        $response = $this->actingAs($user)->getJson('/api/v1/me/analytics?range=this_year')->assertOk();

        expect($response->json('data.bars'))->toHaveCount(12)
            ->and($response->json('data.axis'))->toHaveCount(12)
            ->and($response->json('data.views'))->toBe(12);
    });
});

describe('access', function () {
    it('needs a signed-in creator', function () {
        $this->getJson('/api/v1/me/analytics')->assertUnauthorized();
    });
});

describe('the clock', function () {
    it('runs on Jakarta time, because that is who the product is for', function () {
        // The hour chart groups by local hour and the caption prints the
        // zone by name. On UTC the two disagree by seven hours while still
        // labelling themselves correctly — wrong in the least visible way.
        expect(config('app.timezone'))->toBe('Asia/Jakarta');
    });

    it('files a view under the hour it happened in Jakarta, not in UTC', function () {
        Notification::fake();

        // Freeze an absolute instant: 14:30 UTC is 21:30 in Jakarta. The
        // beacon records now(), so this is the whole bug in one assertion —
        // on a UTC server the evening visit is filed at 14 and the chart
        // still captions itself "Asia/Jakarta".
        $this->travelTo(Date::parse('2026-08-13 14:30:00', 'UTC'));

        $user = premiumCreator();
        $space = Space::factory()->for($user)->published()->create();

        $this->postJson("/api/v1/spaces/{$space->ulid}/events", ['type' => 'view'])
            ->assertNoContent();

        $hours = $this->actingAs($user)->getJson('/api/v1/me/analytics')->json('data.hours');

        expect($hours[21])->toBe(1)
            ->and($hours[14])->toBe(0);
    });

    it('names the zone it is actually counting in', function () {
        $user = User::factory()->create();

        expect($this->actingAs($user)->getJson('/api/v1/me/analytics')->json('data.range.note'))
            ->toContain('Asia/Jakarta');
    });
});
