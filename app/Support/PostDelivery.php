<?php

namespace App\Support;

use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * One definition of where a post's delivery stands, shared by the post's
 * badge (`delivery` on SocialPostResource), the Posts filter
 * (GET /social-posts?status=…) and its counts (`meta.counts`), so the three
 * always agree.
 *
 * - published: every destination went out.
 * - partial: at least one destination went out and at least one failed
 *   (the post's status stays "published"). It counts as Failed too: the
 *   failed destinations need the owner.
 * - failed: nothing went out.
 * - pending: still on its way (scheduled, or retrying a destination).
 *
 * Drafts and cancelled posts were never sent: no delivery (null).
 */
final class PostDelivery
{
    public const PUBLISHED = 'published';

    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    public const PENDING = 'pending';

    public const STATES = [self::PUBLISHED, self::PARTIAL, self::FAILED, self::PENDING];

    /** What GET /social-posts?status= accepts: the post statuses plus "partial". */
    public const FILTERS = ['draft', 'scheduled', 'published', 'failed', 'cancelled', self::PARTIAL];

    /**
     * The post's delivery, from its destinations (loaded if they are not).
     *
     * @return array{state: string, published: int, failed: int, pending: int, total: int}|null
     */
    public static function summary(SocialPost $post): ?array
    {
        if (in_array($post->status, [SocialPostStatus::Draft, SocialPostStatus::Cancelled], true)) {
            return null;
        }

        $targets = $post->relationLoaded('targets') ? $post->targets : $post->targets()->get();
        $count = fn (SocialPostTargetStatus $s) => $targets->filter(fn (SocialPostTarget $t) => $t->status === $s)->count();
        $published = $count(SocialPostTargetStatus::Published);
        $failed = $count(SocialPostTargetStatus::Failed);

        return [
            'state' => self::state($post->status, $failed),
            'published' => $published,
            'failed' => $failed,
            'pending' => $targets->count() - $published - $failed,
            'total' => $targets->count(),
        ];
    }

    /**
     * The state from the post's status and its failed destinations. The
     * post's status leads (it is what the filter can query); a failed
     * destination only splits "published" into published and partial.
     */
    public static function state(SocialPostStatus $status, int $failed): string
    {
        return match ($status) {
            SocialPostStatus::Published => $failed > 0 ? self::PARTIAL : self::PUBLISHED,
            SocialPostStatus::Failed => self::FAILED,
            default => self::PENDING,
        };
    }

    /**
     * Narrow a SocialPost query to one Posts filter: a post status, where
     * "failed" also holds partly failed posts, or "partial" alone.
     * "published" keeps every post that went out (partial ones too).
     *
     * @param  EloquentBuilder<SocialPost>  $query
     * @return EloquentBuilder<SocialPost>
     */
    public static function filter(EloquentBuilder $query, string $status): EloquentBuilder
    {
        return match ($status) {
            self::FAILED => $query->where(fn ($q) => $q
                ->where('status', SocialPostStatus::Failed->value)
                ->orWhere(fn ($p) => self::partial($p))),
            self::PARTIAL => $query->where(fn ($q) => self::partial($q)),
            default => $query->where('status', $status),
        };
    }

    /**
     * How many posts each filter returns, over the same query (its other
     * narrowing kept). published and failed both count partial posts:
     * `partial` is that overlap.
     *
     * @param  EloquentBuilder<SocialPost>  $query
     * @return array<string, int>
     */
    public static function counts(EloquentBuilder $query): array
    {
        $byStatus = (clone $query)->reorder()->toBase()
            ->selectRaw('status, count(*) as aggregate')->groupBy('status')
            ->pluck('aggregate', 'status')->map(fn ($n) => (int) $n);
        $partial = self::filter(clone $query, self::PARTIAL)->count();
        $of = fn (SocialPostStatus $s): int => (int) ($byStatus[$s->value] ?? 0);

        return [
            'draft' => $of(SocialPostStatus::Draft),
            'scheduled' => $of(SocialPostStatus::Scheduled),
            'published' => $of(SocialPostStatus::Published),
            'failed' => $of(SocialPostStatus::Failed) + $partial,
            'partial' => $partial,
            'cancelled' => $of(SocialPostStatus::Cancelled),
        ];
    }

    /** @param  EloquentBuilder<SocialPost>  $query */
    private static function partial(EloquentBuilder $query): void
    {
        $query->where('status', SocialPostStatus::Published->value)
            ->whereHas('targets', fn ($t) => $t->where('status', SocialPostTargetStatus::Failed->value));
    }
}
