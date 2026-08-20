<?php

use App\Enums\SpaceEventType;
use App\Jobs\AggregateSpaceStats;
use App\Jobs\PruneSpaceEvents;
use App\Models\Client;
use App\Models\File;
use App\Models\Space;
use App\Models\SpaceDailyStat;
use App\Models\SpaceEvent;
use App\Models\SpaceItem;
use App\Notifications\SpaceOpened;
use App\Support\SpaceUnlockToken;
use App\Support\VisitorHash;
use Illuminate\Support\Facades\Notification;

function beaconSpace(array $attributes = []): Space
{
    return Space::factory()->published()->create($attributes);
}

function itemIn(Space $space): SpaceItem
{
    $file = File::factory()->for($space->user)->create();

    return $space->items()->create(['file_id' => $file->id, 'sort_order' => 0]);
}

describe('beacon', function () {
    it('records one row per call, with the visitor’s own referrer', function () {
        Notification::fake();
        $space = beaconSpace();

        $this->withHeader('referer', 'https://www.instagram.com/rani/')
            ->postJson("/api/v1/spaces/{$space->ulid}/events", ['type' => 'view'])
            ->assertNoContent();

        $event = SpaceEvent::query()->sole();

        expect($event->space_id)->toBe($space->id)
            ->and($event->type)->toBe(SpaceEventType::View)
            // www. is stripped so instagram.com is one bucket, not two.
            ->and($event->referrer_host)->toBe('instagram.com')
            ->and($event->is_ai_crawler)->toBeFalse()
            ->and($event->client_id)->toBeNull();
    });

    it('leaves the referrer null when there is none — that is the Direct link bucket', function () {
        Notification::fake();
        $space = beaconSpace();

        $this->postJson("/api/v1/spaces/{$space->ulid}/events", ['type' => 'view']);

        expect(SpaceEvent::query()->sole()->referrer_host)->toBeNull();
    });

    it('flags an AI crawler by name and keeps it out of the view count', function () {
        Notification::fake();
        $space = beaconSpace();

        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)')
            ->postJson("/api/v1/spaces/{$space->ulid}/events", ['type' => 'view']);

        $event = SpaceEvent::query()->sole();
        expect($event->is_ai_crawler)->toBeTrue()
            ->and($event->crawler_name)->toBe('GPTBot');

        (new AggregateSpaceStats)->handle();

        $stat = SpaceDailyStat::query()->sole();
        expect($stat->views)->toBe(0)
            ->and($stat->ai_crawler_hits)->toBe(1);
    });

    it('attaches the client when the visitor happens to be signed in', function () {
        Notification::fake();
        $space = beaconSpace();
        $client = Client::factory()->create();

        $this->actingAs($client, 'client')
            ->postJson("/api/v1/spaces/{$space->ulid}/events", ['type' => 'view']);

        expect(SpaceEvent::query()->sole()->client_id)->toBe($client->id);
    });

    it('links a lightbox open to the item, and ignores an item from elsewhere', function () {
        Notification::fake();
        $space = beaconSpace();
        $item = itemIn($space);
        $elsewhere = itemIn(beaconSpace());

        $this->postJson("/api/v1/spaces/{$space->ulid}/events", [
            'type' => 'lightbox_open',
            'item' => $item->ulid,
        ])->assertNoContent();

        $this->postJson("/api/v1/spaces/{$space->ulid}/events", [
            'type' => 'download',
            'item' => $elsewhere->ulid,
        ])->assertNoContent();

        $events = SpaceEvent::query()->orderBy('id')->get();
        expect($events[0]->space_item_id)->toBe($item->id)
            // Not an error: the beacon never argues with the page it is on.
            ->and($events[1]->space_item_id)->toBeNull();
    });

    it('refuses a type the browser has no business claiming', function () {
        $space = beaconSpace();

        $this->postJson("/api/v1/spaces/{$space->ulid}/events", ['type' => 'approval_action'])
            ->assertUnprocessable();

        expect(SpaceEvent::query()->count())->toBe(0);
    });

    it('records nothing for a draft, an archived, or an expired Space', function () {
        Notification::fake();
        $draft = Space::factory()->create();
        $archived = Space::factory()->archived()->create();
        $expired = Space::factory()->published()->expired()->create();

        foreach ([$draft, $archived, $expired] as $space) {
            $this->postJson("/api/v1/spaces/{$space->ulid}/events", ['type' => 'view'])
                ->assertNotFound();
        }

        expect(SpaceEvent::query()->count())->toBe(0);
    });

    it('refuses a locked Space without an unlock token, and accepts one with', function () {
        Notification::fake();
        $space = beaconSpace();
        $space->forceFill(['password_hash' => bcrypt('secret')])->save();

        $this->postJson("/api/v1/spaces/{$space->ulid}/events", ['type' => 'view'])
            ->assertNotFound();

        $this->postJson("/api/v1/spaces/{$space->ulid}/events", [
            'type' => 'view',
            'st' => SpaceUnlockToken::issue($space),
        ])->assertNoContent();

        expect(SpaceEvent::query()->count())->toBe(1);
    });
});

describe('visitor hash', function () {
    it('is the same browser twice in a day and a different one tomorrow', function () {
        $today = VisitorHash::of('1.2.3.4', 'Firefox', '2026-08-13');
        $again = VisitorHash::of('1.2.3.4', 'Firefox', '2026-08-13');
        $tomorrow = VisitorHash::of('1.2.3.4', 'Firefox', '2026-08-14');

        // The salt rotates daily on purpose: nobody, us included, can join
        // two days of traffic back into one person.
        expect($again)->toBe($today)
            ->and($tomorrow)->not->toBe($today)
            ->and(strlen($today))->toBe(40);
    });
});

describe('space opened notification', function () {
    it('tells the owner once a day, however many times the page is refreshed', function () {
        Notification::fake();
        $space = beaconSpace();

        foreach (range(1, 3) as $ignored) {
            $this->postJson("/api/v1/spaces/{$space->ulid}/events", ['type' => 'view']);
        }

        Notification::assertSentToTimes($space->user, SpaceOpened::class, 1);
    });

    it('stays quiet for crawlers and for the creator looking at their own work', function () {
        Notification::fake();
        $space = beaconSpace();

        $this->withHeader('User-Agent', 'ClaudeBot/1.0')
            ->postJson("/api/v1/spaces/{$space->ulid}/events", ['type' => 'view']);

        // A client identity that belongs to the creator themself.
        $me = Client::factory()->create(['user_id' => $space->user_id]);
        $this->actingAs($me, 'client')
            ->postJson("/api/v1/spaces/{$space->ulid}/events", ['type' => 'view']);

        Notification::assertNothingSent();
    });
});

describe('aggregation', function () {
    it('counts views and people, and corrects rather than doubles on a re-run', function () {
        $space = beaconSpace();

        SpaceEvent::factory()->count(2)->for($space)->create(['visitor_hash' => str_repeat('a', 40)]);
        SpaceEvent::factory()->for($space)->create(['visitor_hash' => str_repeat('b', 40)]);

        (new AggregateSpaceStats)->handle();
        (new AggregateSpaceStats)->handle();

        $stat = SpaceDailyStat::query()->sole();
        expect($stat->views)->toBe(3)
            ->and($stat->unique_visitors)->toBe(2);
    });

    it('backfills yesterday, so a night the queue was down is not a permanent hole', function () {
        $space = beaconSpace();
        SpaceEvent::factory()->for($space)->create(['created_at' => now()->subDay()]);

        (new AggregateSpaceStats)->handle();

        $stat = SpaceDailyStat::query()->sole();
        expect($stat->date->toDateString())->toBe(now()->subDay()->toDateString())
            ->and($stat->views)->toBe(1);
    });

    it('stores the day’s biggest sources', function () {
        $space = beaconSpace();
        SpaceEvent::factory()->count(3)->for($space)->from('instagram.com')->create();
        SpaceEvent::factory()->for($space)->from('google.com')->create();

        (new AggregateSpaceStats)->handle();

        expect(SpaceDailyStat::query()->sole()->top_referrers)->toBe([
            ['host' => 'instagram.com', 'count' => 3],
            ['host' => 'google.com', 'count' => 1],
        ]);
    });
});

describe('prune', function () {
    it('keeps 90 days and drops what is older', function () {
        $space = beaconSpace();
        SpaceEvent::factory()->for($space)->create(['created_at' => now()->subDays(89)]);
        SpaceEvent::factory()->for($space)->create(['created_at' => now()->subDays(91)]);

        (new PruneSpaceEvents)->handle();

        expect(SpaceEvent::query()->count())->toBe(1);
    });
});
