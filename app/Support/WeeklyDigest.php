<?php

namespace App\Support;

use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\User;
use App\Support\Insight\InsightClients;
use App\Support\Insight\InsightCollector;
use App\Support\Insight\InsightPeriod;
use App\Support\Insight\InsightSummary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a user's Monday email says about last week (Monday 00:00 to
 * Monday 00:00 in their timezone): how many posts went out, how many wait
 * for approval now, and which failed — each with its reason and a link.
 */
final class WeeklyDigest
{
    /** At most this many failures are listed; the rest are counted. */
    public const MAX_FAILED = 10;

    /** The zone the digest runs in: the user's, else their latest Content Plan's, else the app's. */
    public static function timezone(User $user): string
    {
        // Raw read: a user built in memory (not reloaded) may not carry the column.
        $own = $user->getAttributes()['timezone'] ?? null;
        if (is_string($own) && $own !== '') {
            return $own;
        }

        $plan = SocialApprovalLink::query()->where('user_id', $user->id)->whereNotNull('scope_timezone')->latest('id')->value('scope_timezone');

        return is_string($plan) && $plan !== '' ? $plan : (string) config('app.timezone');
    }

    /**
     * @return array{week: array{from: string, to: string, timezone: string}, published: int, waiting: int, failed: list<array{post_id: string, caption: string, reason: string, url: string}>, failed_total: int, insight: array<string, mixed>|null}
     */
    public static function for(User $user, CarbonImmutable $localMonday): array
    {
        $tz = $localMonday->getTimezone()->getName();
        $to = $localMonday->startOfDay();
        $from = $to->subWeek();
        // updated_at holds app-timezone wall time.
        $app = (string) config('app.timezone');
        [$fromApp, $toApp] = [$from->setTimezone($app), $to->setTimezone($app)];

        $published = SocialPost::query()->where('user_id', $user->id)
            ->whereHas('targets', fn (Builder $q) => $q->where('status', SocialPostTargetStatus::Published)
                ->where('updated_at', '>=', $fromApp)->where('updated_at', '<', $toApp))
            ->count();

        $waiting = SocialPost::query()->where('user_id', $user->id)
            ->where('status', SocialPostStatus::Scheduled)
            ->whereIn('approval', [SocialPostApproval::Awaiting->value, SocialPostApproval::Client->value])
            ->count();

        $failedQuery = SocialPost::query()->where('user_id', $user->id)
            ->whereHas('targets', fn (Builder $q) => $q->where('status', SocialPostTargetStatus::Failed)
                ->where('updated_at', '>=', $fromApp)->where('updated_at', '<', $toApp));

        $failed = array_values((clone $failedQuery)->with(['targets' => fn ($q) => $q->where('status', SocialPostTargetStatus::Failed)])
            ->orderByDesc('id')->limit(self::MAX_FAILED)->get()
            ->map(fn (SocialPost $p) => [
                'post_id' => $p->ulid,
                'caption' => str($p->caption)->squish()->limit(60)->toString(),
                'reason' => (string) ($p->targets->first()->fail_reason ?? $p->fail_reason ?? 'It was not published.'),
                'url' => config('app.frontend_url').'/social?post='.$p->ulid,
            ])->all());

        return [
            'week' => ['from' => $from->toDateString(), 'to' => $to->subDay()->toDateString(), 'timezone' => $tz],
            'published' => $published,
            'waiting' => $waiting,
            'failed' => $failed,
            'failed_total' => $failedQuery->count(),
            'insight' => self::insight($user, $from, $to->subDay()),
        ];
    }

    /** Best time to post reads this many days back (as GET /insight/best-times). */
    public const BEST_TIME_DAYS = 90;

    /**
     * Last week in Insight's own numbers (Pro / Max only): reach and
     * engagement with their change against the week before, the week's top
     * post, and the best time to post from the user's own posts. Null on
     * Free, with no channel, or when the networks could not be read — the
     * digest goes out without it, never with a guess.
     *
     * @return array{reach: int|null, reach_change: float|null, engagement: int|null, engagement_change: float|null, top_post: array{network: string, caption: string, url: string|null, engagement: int}|null, best_time: array{label: string, network: string}|null}|null
     */
    public static function insight(User $user, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        try {
            if (! PlanQuota::hasFeature($user, 'insight')) {
                return null;
            }
            $owner = Workspace::owner($user);
            $accounts = InsightClients::accounts($owner);
            if ($accounts->isEmpty()) {
                return null;
            }
            $collector = app(InsightCollector::class);
            $week = InsightSummary::present($collector->collectCached($owner, $accounts,
                InsightPeriod::fromDates($from, $to, InsightPeriod::RANGE)));

            $best = $collector->collectCached($owner, $accounts,
                InsightPeriod::fromDates($to->subDays(self::BEST_TIME_DAYS - 1), $to, InsightPeriod::RANGE));
            /** @var list<array<string, mixed>> $channels */
            $channels = $best['channels'];
            $slots = [];
            foreach ($channels as $c) {
                if (($c['best_times']['status'] ?? null) === 'ok') {
                    $slots[] = ['label' => (string) $c['best_times']['slots'][0]['label'], 'network' => (string) $c['network'], 'avg' => (float) $c['best_times']['slots'][0]['avg_engagement']];
                }
            }
            usort($slots, fn (array $a, array $b) => $b['avg'] <=> $a['avg']);

            $top = $week['top_posts'][0] ?? null;

            return [
                'reach' => $week['totals']['reach'],
                'reach_change' => $week['change_pct']['reach'],
                'engagement' => $week['totals']['engagement'],
                'engagement_change' => $week['change_pct']['engagement'],
                'top_post' => $top === null ? null : [
                    'network' => (string) $top['network'],
                    'caption' => str((string) $top['caption'])->squish()->limit(80)->toString(),
                    'url' => $top['url'],
                    'engagement' => (int) $top['engagement'],
                ],
                'best_time' => $slots === [] ? null : ['label' => $slots[0]['label'], 'network' => $slots[0]['network']],
            ];
        } catch (Throwable $e) {
            Log::warning('weekly_digest.insight_failed', ['user' => $user->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** @param  array{published: int, waiting: int, failed_total: int, insight?: array<string, mixed>|null}  $digest */
    public static function isEmpty(array $digest): bool
    {
        $insight = $digest['insight'] ?? null;

        return $digest['published'] === 0 && $digest['waiting'] === 0 && $digest['failed_total'] === 0
            && ($insight === null || ($insight['reach'] === null && $insight['engagement'] === null));
    }
}
