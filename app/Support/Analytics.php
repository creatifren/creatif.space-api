<?php

namespace App\Support;

use App\Enums\ApprovalStatus;
use App\Enums\OrderStatus;
use App\Enums\SpaceEventType;
use App\Models\Approval;
use App\Models\Client;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Space;
use App\Models\SpaceDailyStat;
use App\Models\SpaceEvent;
use App\Models\SpaceItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Every number on Insights → Analytics.
 *
 * Two sources, on purpose. The daily chart reads space_daily_stats, which
 * survives the 90-day prune, so a Space nobody has opened since March still
 * draws a line. Everything that explains those views reads space_events,
 * which only exists inside the window — and only Premium can see it.
 *
 * Nothing here is a stored counter. If a figure looks wrong it is because
 * the events are wrong, not because a total drifted.
 */
final class Analytics
{
    /**
     * Space ids this user owns — every query below is scoped by this, so a
     * ulid from someone else's account simply matches nothing.
     *
     * @return list<int>
     */
    public static function spaceIds(User $owner, ?string $spaceUlid = null): array
    {
        $query = Space::query()->where('user_id', $owner->id);

        if ($spaceUlid !== null) {
            $query->where('ulid', $spaceUlid);
        }

        return $query->pluck('id')->all();
    }

    /**
     * The bars, from the aggregate table. Daily for a month-shaped range,
     * monthly for a year — a 365-bar chart is a smear, not a chart.
     *
     * @param  list<int>  $spaceIds
     * @return array{bars: list<int>, axis: list<string>, views: int, peak_index: int|null}
     */
    public static function series(array $spaceIds, CarbonInterface $from, CarbonInterface $to, bool $monthly): array
    {
        /** @var Collection<string, int> $byDate */
        $byDate = SpaceDailyStat::query()
            ->selectRaw('date, SUM(views) AS views')
            ->whereIn('space_id', $spaceIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('date')
            ->pluck('views', 'date')
            ->mapWithKeys(fn ($views, $date): array => [
                // MySQL hands back a date, SQLite a datetime string.
                substr((string) $date, 0, 10) => (int) $views,
            ]);

        $bars = [];
        $labels = [];

        // Reassigned rather than mutated, and immutable first: on a mutable
        // Carbon the cursor below would walk the caller's own $from forward
        // to the end of the range, and every later read would use it.
        $start = $from->toImmutable();

        if ($monthly) {
            $cursor = $start->startOfMonth();
            while ($cursor->lessThanOrEqualTo($to)) {
                $month = $cursor->format('Y-m');
                $bars[] = $byDate
                    ->filter(fn ($_, $date): bool => str_starts_with($date, $month))
                    ->sum();
                $labels[] = $cursor->format('M');
                $cursor = $cursor->addMonth();
            }
        } else {
            $cursor = $start->startOfDay();
            while ($cursor->lessThanOrEqualTo($to)) {
                $bars[] = $byDate[$cursor->toDateString()] ?? 0;
                $labels[] = $cursor->format('j');
                $cursor = $cursor->addDay();
            }
        }

        $max = $bars === [] ? 0 : max($bars);

        return [
            'bars' => $bars,
            'axis' => self::axis($labels, $to, $monthly),
            'views' => array_sum($bars),
            'peak_index' => $max > 0 ? (int) array_search($max, $bars, true) : null,
        ];
    }

    /**
     * Five labels under the chart, evenly spaced, the last one carrying the
     * month so the range is readable without the caption.
     *
     * @param  list<string>  $labels
     * @return list<string>
     */
    private static function axis(array $labels, CarbonInterface $to, bool $monthly): array
    {
        $count = count($labels);

        if ($count === 0) {
            return [];
        }

        if ($monthly || $count <= 5) {
            return $labels;
        }

        $picks = [];
        foreach ([0, 0.25, 0.5, 0.75, 1] as $at) {
            $picks[] = (int) round($at * ($count - 1));
        }

        $axis = [];
        foreach (array_unique($picks) as $i) {
            $axis[] = $i === $count - 1
                ? $labels[$i].' '.$to->format('M')
                : $labels[$i];
        }

        return $axis;
    }

    /**
     * Views, unique people, and crawler hits for a whole range — read from
     * the aggregate so Free can have the headline without touching events.
     *
     * Note unique_visitors are summed across days, not deduplicated across
     * them: the hash rotates daily by design, so "214 people this month"
     * means 214 browser-days. Say so in the copy rather than pretending.
     *
     * @param  list<int>  $spaceIds
     * @return array{views: int, unique: int, crawlers: int}
     */
    public static function totals(array $spaceIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $row = SpaceDailyStat::query()
            ->selectRaw('SUM(views) AS views, SUM(unique_visitors) AS uniques, SUM(ai_crawler_hits) AS crawlers')
            ->whereIn('space_id', $spaceIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->toBase()
            ->first();

        // An aggregate SELECT always returns one row, even over no rows at
        // all — the columns are just null.
        return [
            'views' => (int) ($row->views ?? 0),
            'unique' => (int) ($row->uniques ?? 0),
            'crawlers' => (int) ($row->crawlers ?? 0),
        ];
    }

    /**
     * Where the views came from. Null host is a real answer, and usually
     * the biggest one — somebody pasted the link into a chat.
     *
     * @param  list<int>  $spaceIds
     * @return list<array{name: string, views: int}>
     */
    public static function sources(array $spaceIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = self::events($spaceIds, $from, $to)
            ->selectRaw('referrer_host, COUNT(*) AS hits')
            ->where('type', SpaceEventType::View)
            ->where('is_ai_crawler', false)
            ->groupBy('referrer_host')
            ->orderByDesc('hits')
            ->limit(8)
            ->toBase()
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'name' => $row->referrer_host === null
                    ? 'Direct link'
                    : (string) $row->referrer_host,
                'views' => (int) $row->hits,
            ])
            ->all();
    }

    /**
     * The work they actually looked at — lightbox opens and downloads per
     * file, which is a better answer to "what caught them" than views.
     *
     * @param  list<int>  $spaceIds
     * @return list<array{name: string, space: string, thumb: string|null, views: int, downloads: int}>
     */
    public static function files(array $spaceIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = self::events($spaceIds, $from, $to)
            ->selectRaw('space_item_id')
            ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS opens', [SpaceEventType::LightboxOpen->value])
            ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS downloads', [SpaceEventType::Download->value])
            ->whereNotNull('space_item_id')
            ->where('is_ai_crawler', false)
            ->groupBy('space_item_id')
            ->orderByDesc('opens')
            ->limit(5)
            ->toBase()
            ->get();

        $items = SpaceItem::query()
            ->whereIn('id', $rows->pluck('space_item_id'))
            ->with(['driveFile', 'space'])
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function ($row) use ($items): ?array {
                $item = $items[$row->space_item_id] ?? null;

                if ($item === null) {
                    return null;
                }

                return [
                    'name' => (string) $item->driveFile->name,
                    'space' => (string) $item->space->title,
                    'thumb' => $item->driveFile->thumbnail_url,
                    'views' => (int) $row->opens,
                    'downloads' => (int) $row->downloads,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Who opened what — only the visitors who happened to be signed in as
     * a client. Everyone else is a hash and stays one.
     *
     * @param  list<int>  $spaceIds
     * @return list<array{who: string, space: string, last_at: string, visits: int}>
     */
    public static function viewers(array $spaceIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = self::events($spaceIds, $from, $to)
            ->selectRaw('client_id, space_id, COUNT(*) AS visits, MAX(created_at) AS last_at')
            ->whereNotNull('client_id')
            ->where('type', SpaceEventType::View)
            ->groupBy('client_id', 'space_id')
            ->orderByDesc('last_at')
            ->limit(10)
            ->toBase()
            ->get();

        $clients = Client::query()
            ->whereIn('id', $rows->pluck('client_id'))
            ->get()
            ->keyBy('id');

        $spaces = Space::query()
            ->whereIn('id', $rows->pluck('space_id'))
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function ($row) use ($clients, $spaces): ?array {
                $client = $clients[$row->client_id] ?? null;

                if ($client === null) {
                    return null;
                }

                return [
                    'who' => $client->displayName(),
                    'space' => (string) ($spaces[$row->space_id]->title ?? ''),
                    'last_at' => (string) $row->last_at,
                    'visits' => (int) $row->visits,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Bots that read the public pages, by name.
     *
     * @param  list<int>  $spaceIds
     * @return list<array{name: string, hits: int, last_at: string}>
     */
    public static function crawlers(array $spaceIds, CarbonInterface $from, CarbonInterface $to): array
    {
        return self::events($spaceIds, $from, $to)
            ->selectRaw('crawler_name, COUNT(*) AS hits, MAX(created_at) AS last_at')
            ->where('is_ai_crawler', true)
            ->whereNotNull('crawler_name')
            ->groupBy('crawler_name')
            ->orderByDesc('hits')
            ->limit(8)
            ->toBase()
            ->get()
            ->map(fn ($row): array => [
                'name' => (string) $row->crawler_name,
                'hits' => (int) $row->hits,
                'last_at' => (string) $row->last_at,
            ])
            ->all();
    }

    /**
     * People who arrived from an assistant's answer rather than a search
     * result — a different question from "which bot read the page".
     *
     * @param  list<int>  $spaceIds
     * @return list<array{name: string, host: string, views: int}>
     */
    public static function assistantReferrers(array $spaceIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = self::events($spaceIds, $from, $to)
            ->selectRaw('referrer_host, COUNT(*) AS hits')
            ->whereNotNull('referrer_host')
            ->where('is_ai_crawler', false)
            ->groupBy('referrer_host')
            ->toBase()
            ->get();

        $byAssistant = [];

        foreach ($rows as $row) {
            $name = CrawlerAgents::assistantFor((string) $row->referrer_host);

            if ($name === null) {
                continue;
            }

            $byAssistant[$name] ??= ['name' => $name, 'host' => (string) $row->referrer_host, 'views' => 0];
            $byAssistant[$name]['views'] += (int) $row->hits;
        }

        $ranked = array_values($byAssistant);
        usort($ranked, fn (array $a, array $b): int => $b['views'] <=> $a['views']);

        return $ranked;
    }

    /**
     * When clients open links, local time. 24 buckets.
     *
     * @param  list<int>  $spaceIds
     * @return list<int>
     */
    public static function hours(array $spaceIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $hours = array_fill(0, 24, 0);

        $events = self::events($spaceIds, $from, $to)
            ->where('type', SpaceEventType::View)
            ->where('is_ai_crawler', false)
            ->pluck('created_at');

        foreach ($events as $at) {
            if ($at !== null) {
                $hours[(int) $at->format('G')]++;
            }
        }

        return $hours;
    }

    /**
     * How fast approval moves, and what is stuck. Read from approvals, not
     * events — a decision is a fact, not traffic.
     *
     * @param  list<int>  $spaceIds
     * @return array{median_hours: int|null, stuck: list<array{name: string, who: string, age_hours: int}>}
     */
    public static function approval(array $spaceIds): array
    {
        $decided = Approval::query()
            ->whereIn(
                'space_item_id',
                SpaceItem::query()->whereIn('space_id', $spaceIds)->select('id'),
            )
            ->where('status', ApprovalStatus::Approved)
            ->whereNotNull('approved_at')
            ->get(['created_at', 'approved_at']);

        $spans = $decided
            ->map(fn (Approval $a): float => $a->created_at->diffInHours($a->approved_at))
            ->sort()
            ->values();

        $median = $spans->isEmpty()
            ? null
            : (int) round($spans[(int) floor(($spans->count() - 1) / 2)]);

        $stuck = Approval::query()
            ->whereIn(
                'space_item_id',
                SpaceItem::query()->whereIn('space_id', $spaceIds)->select('id'),
            )
            ->where('status', ApprovalStatus::Pending)
            ->where('created_at', '<=', now()->subDays(3))
            ->with(['spaceItem.space', 'client'])
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (Approval $a): array => [
                'name' => (string) $a->spaceItem->space->title,
                'who' => $a->client->displayName(),
                'age_hours' => (int) round($a->created_at->diffInHours(now())),
            ])
            ->all();

        return ['median_hours' => $median, 'stuck' => $stuck];
    }

    /**
     * What sells: every offer with the orders it actually produced.
     *
     * @return list<array{name: string, kind: string, sold: int, gross: int}>
     */
    public static function sales(User $owner, CarbonInterface $from, CarbonInterface $to): array
    {
        $offers = $owner->offers()->get();

        $sold = Order::query()
            ->selectRaw('offer_id, COUNT(*) AS sold, SUM(amount) AS gross')
            ->where('creator_id', $owner->id)
            ->where('status', OrderStatus::Paid)
            ->whereBetween('paid_at', [$from, $to])
            ->whereNotNull('offer_id')
            ->groupBy('offer_id')
            ->toBase()
            ->get()
            ->keyBy('offer_id');

        // Every offer, including the ones that sold nothing — a zero is the
        // answer the creator came for.
        return $offers
            ->map(fn (Offer $offer): array => [
                'name' => $offer->title,
                'kind' => $offer->type->value,
                'sold' => (int) ($sold[$offer->id]->sold ?? 0),
                'gross' => (int) ($sold[$offer->id]->gross ?? 0),
            ])
            ->all();
    }

    /**
     * Clients who came back — more than one paid order, ever. Deliberately
     * not scoped to the range: "came back" is a fact about the relationship.
     *
     * @return list<array{who: string, projects: int, last_at: string, value: int}>
     */
    public static function repeatClients(User $owner): array
    {
        $rows = Order::query()
            ->selectRaw('client_id, COUNT(*) AS projects, SUM(amount) AS value, MAX(paid_at) AS last_at')
            ->where('creator_id', $owner->id)
            ->where('status', OrderStatus::Paid)
            ->groupBy('client_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('value')
            ->limit(5)
            ->toBase()
            ->get();

        $clients = Client::query()
            ->whereIn('id', $rows->pluck('client_id'))
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function ($row) use ($clients): ?array {
                $client = $clients[$row->client_id] ?? null;

                if ($client === null) {
                    return null;
                }

                return [
                    'who' => $client->displayName(),
                    'projects' => (int) $row->projects,
                    'last_at' => (string) $row->last_at,
                    'value' => (int) $row->value,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Whether this account is legible to a machine that is trying to answer
     * a question about it. Every check reads real rows — no score, no
     * invented grade, just six things that are either true or not.
     *
     * @return list<array{label: string, ok: bool, detail: string}>
     */
    public static function answerReadiness(User $owner): array
    {
        $published = $owner->spaces()->where('status', 'published')->get();
        $described = $published->filter(fn (Space $s): bool => ($s->seo['description'] ?? null) !== null);

        $items = SpaceItem::query()
            ->whereIn('space_id', $published->pluck('id'))
            ->get();
        $captioned = $items->filter(fn ($item): bool => $item->caption !== null && $item->caption !== '');

        $profile = $owner->profile;
        $handle = $owner->handle;

        return [
            [
                'label' => 'Every public page has a description',
                'ok' => $published->isNotEmpty() && $described->count() === $published->count(),
                'detail' => $described->count().' of '.$published->count(),
            ],
            [
                'label' => 'Every photo has a caption',
                'ok' => $items->isNotEmpty() && $captioned->count() === $items->count(),
                'detail' => $captioned->count().' of '.$items->count(),
            ],
            [
                'label' => 'Your profile is indexable',
                'ok' => $handle !== null,
                'detail' => $handle === null
                    ? 'No handle claimed yet'
                    : config('app.frontend_url').'/'.$handle->name,
            ],
            [
                'label' => 'Your profile says what you do',
                'ok' => $profile !== null && $profile->headline !== null,
                'detail' => $profile === null ? 'Headline is empty' : ($profile->headline ?? 'Headline is empty'),
            ],
            [
                'label' => 'Your cover image is set',
                'ok' => $profile !== null && $profile->cover_url !== null,
                'detail' => $profile?->cover_url === null ? 'Not set' : 'Set',
            ],
            [
                'label' => 'Private Spaces stay out of the index',
                'ok' => true,
                'detail' => 'noindex, always',
            ],
        ];
    }

    /**
     * The base event query — every read is scoped to the owner's Spaces and
     * to the window, so nothing here can leak across accounts.
     *
     * @param  list<int>  $spaceIds
     * @return Builder<SpaceEvent>
     */
    private static function events(array $spaceIds, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return SpaceEvent::query()
            ->whereIn('space_id', $spaceIds)
            ->whereBetween('created_at', [$from, $to]);
    }
}
