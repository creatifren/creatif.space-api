<?php

namespace App\Support;

use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

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
     * @return array{week: array{from: string, to: string, timezone: string}, published: int, waiting: int, failed: list<array{post_id: string, caption: string, reason: string, url: string}>, failed_total: int}
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
        ];
    }

    /** @param  array{published: int, waiting: int, failed_total: int}  $digest */
    public static function isEmpty(array $digest): bool
    {
        return $digest['published'] === 0 && $digest['waiting'] === 0 && $digest['failed_total'] === 0;
    }
}
