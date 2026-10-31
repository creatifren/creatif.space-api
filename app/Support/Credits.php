<?php

namespace App\Support;

use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Enums\WalletTransactionType;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * One balance for sends and storage (the Credits comp, 2026-10).
 *
 * The whole price list: $1 buys 10.000 Credits ($10 = 100.000, the smallest
 * top-up), a publish past the plan's monthly allowance costs 300 ($0.03),
 * a 5 GB block of storage costs 5.000 a month — 4.500 once the account
 * passes 100 GB, on every block, not only the ones above the line.
 * Purchased Credits never expire.
 *
 * A publish is one post to one destination account. The plan's monthly
 * allowance is used first; only publishes past it cost Credits. The
 * allowance resets each period and never rolls over, and never turns into
 * Credits.
 *
 * Order matters: rent is taken on the first, before anything is sent, so
 * a send never spends money the rent was going to need. A send is taken
 * up front when the post is handed to the provider, and a destination that
 * never goes out (failed, cancelled) gives its publish back to where it
 * came from — the allowance or the Credits — so the user is only ever out
 * of pocket for what was delivered. Approval, drafts, scheduling and
 * uploads inside rented storage move nothing.
 */
final class Credits
{
    /** Credits one US dollar buys. */
    public const PER_DOLLAR = 10_000;

    /** One publish past the monthly allowance. */
    public const PER_SEND = 300;

    public const BLOCK_GB = 5;

    public const PER_BLOCK = 5000;

    public const PER_BLOCK_BULK = 4500;

    /** Past this many plan-plus-rented GB, every block is at the bulk rate. */
    public const BULK_FROM_GB = 100;

    /** Once, at sign-up — Free and paid alike. */
    public const FREE_WELCOME = 3000;

    /** Top-up bounds, in whole US dollars — a custom amount must sit inside them. */
    public const TOP_UP_MIN = 10;

    public const TOP_UP_MAX = 1000;

    public static function balance(User $user): int
    {
        return Wallet::balance(self::owner($user), Wallet::CREDITS);
    }

    /**
     * What one month of the given blocks costs, given the plan's own GB.
     */
    public static function rentFor(int $blocks, int $planGb): int
    {
        if ($blocks <= 0) {
            return 0;
        }

        $totalGb = $planGb + $blocks * self::BLOCK_GB;

        return $blocks * ($totalGb > self::BULK_FROM_GB ? self::PER_BLOCK_BULK : self::PER_BLOCK);
    }

    public static function rent(User $user): int
    {
        $owner = self::owner($user);

        return self::rentFor(self::blocks($owner), self::planGb($owner));
    }

    /** Bytes the rented blocks add on top of the plan's storage. */
    public static function rentedBytes(User $user): int
    {
        return self::blocks(self::owner($user)) * self::BLOCK_GB * 1024 * 1024 * 1024;
    }

    /**
     * A model built in memory (factory, firstOrCreate) has no default read
     * back from the database; zero is what the column defaults to.
     */
    public static function blocks(User $owner): int
    {
        return (int) ($owner->getAttributes()['storage_blocks'] ?? 0);
    }

    /**
     * Already spoken for: sends handed over but not yet out, and next
     * month's rent — the two numbers the screen shows as committed.
     *
     * @return array{sends: int, rent: int}
     */
    public static function committed(User $user): array
    {
        return [
            'sends' => self::pendingDestinations($user) * self::PER_SEND,
            'rent' => self::rent($user),
        ];
    }

    /**
     * Pay for a post about to reach the provider: each destination not yet
     * paid for takes one publish from the monthly allowance while it lasts,
     * then 300 Credits. Each target remembers which, so a return goes back
     * to the right place. Refuses with a validation error the composer can
     * show, and then nothing is marked.
     *
     * The owner's row is locked for the count: two sends at once must not
     * both spend the last publish in the allowance.
     *
     * @throws ValidationException
     */
    public static function chargeSend(SocialPost $post): void
    {
        $owner = self::owner($post->user);

        DB::transaction(function () use ($post, $owner) {
            User::query()->whereKey($owner->id)->lockForUpdate()->first();

            $targets = $post->targets()
                ->where('status', SocialPostTargetStatus::Pending)
                ->whereNull('paid_with')
                ->orderBy('id')
                ->get();

            if ($targets->isEmpty()) {
                return;
            }

            $free = min($targets->count(), self::allowanceLeft($owner));
            $paid = $targets->count() - $free;
            $now = now();

            if ($paid > 0) {
                $price = $paid * self::PER_SEND;

                try {
                    Wallet::debit(
                        $owner,
                        WalletTransactionType::CreditsSend,
                        $price,
                        'social_post',
                        $post->id,
                        $paid.' publish'.($paid === 1 ? '' : 'es').' past the allowance',
                        Wallet::CREDITS,
                    );
                } catch (RuntimeException) {
                    throw ValidationException::withMessages([
                        'caption' => "This needs {$price} Credits and the balance has ".self::balance($owner)
                            .' — top up, or send to fewer accounts.',
                    ]);
                }
            }

            $targets->each(fn (SocialPostTarget $target, int $i) => $target->update([
                'paid_with' => $i < $free ? SocialPostTarget::ALLOWANCE : SocialPostTarget::CREDITS,
                'charged_at' => $now,
            ]));
        });
    }

    /**
     * Give back the publishes of destinations that never went out: Credits
     * to the balance, allowance to this period's count (by forgetting it was
     * used). A target already given back, or never charged, gives nothing.
     *
     * @param  iterable<SocialPostTarget>  $targets
     */
    public static function returnSend(SocialPost $post, iterable $targets, string $why): void
    {
        $ids = collect($targets)->pluck('id');
        $owner = self::owner($post->user);

        DB::transaction(function () use ($post, $ids, $owner, $why) {
            User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
            // Fresh state under the same lock as charging. Two failures or a
            // cancel racing a failure must not return a target twice.
            $charged = $post->targets()->whereKey($ids)->whereNotNull('paid_with')->lockForUpdate()->get();
            $credits = $charged->where('paid_with', SocialPostTarget::CREDITS)->count();
            SocialPostTarget::query()->whereKey($charged->pluck('id'))->update(['paid_with' => null, 'charged_at' => null]);

            if ($credits > 0) {
                Wallet::credit(
                    self::owner($post->user),
                    WalletTransactionType::CreditsSendReturn,
                    $credits * self::PER_SEND,
                    'social_post',
                    $post->id,
                    $why,
                    Wallet::CREDITS,
                );
            }
        });
    }

    /**
     * Credits a send of $destinations would take right now: only what the
     * remaining allowance does not cover.
     */
    public static function sendPrice(User $user, int $destinations): int
    {
        return max(0, $destinations - self::allowanceLeft(self::owner($user))) * self::PER_SEND;
    }

    /** Publishes taken from the allowance in the current period. */
    public static function allowanceUsed(User $user): int
    {
        $owner = self::owner($user);

        return SocialPostTarget::query()
            ->where('paid_with', SocialPostTarget::ALLOWANCE)
            ->where('charged_at', '>=', self::allowanceSince($owner))
            ->whereHas('post', fn ($q) => $q->whereIn('user_id', Workspace::memberIds($owner)))
            ->count();
    }

    /** What is left of this period's allowance; a plan without a ceiling never runs out. */
    public static function allowanceLeft(User $user): int
    {
        $limit = PlanQuota::socialPostsLimit($user);

        return $limit === null ? PHP_INT_MAX : max(0, $limit - self::allowanceUsed($user));
    }

    /**
     * When the current allowance period began. It resets monthly on the day
     * of the sign-up (Free) or of the subscription's start (Pro, Max, yearly
     * plans too); a month without that day resets on its last day.
     */
    public static function allowanceSince(User $owner): CarbonImmutable
    {
        $anchor = $owner->activeSubscription()?->current_period_start ?? $owner->created_at;
        $day = $anchor === null ? 1 : $anchor->day;
        $on = fn (CarbonImmutable $month) => $month->setDay(min($day, $month->daysInMonth))->startOfDay();

        $month = CarbonImmutable::now()->startOfMonth();
        $start = $on($month);

        return $start->isFuture() ? $on($month->subMonthNoOverflow()) : $start;
    }

    /**
     * Grant Credits (plan monthly, Free welcome, an admin gift).
     */
    public static function grant(User $user, int $credits, string $why): void
    {
        Wallet::credit(self::owner($user), WalletTransactionType::CreditsGrant, $credits, null, null, $why, Wallet::CREDITS);
    }

    /**
     * Free's welcome Credits, once — at the moment the username is claimed.
     */
    public static function welcome(User $user): void
    {
        $already = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('wallet', Wallet::CREDITS)
            ->where('type', WalletTransactionType::CreditsGrant)
            ->where('note', 'Welcome')
            ->exists();

        if (! $already) {
            self::grant($user, self::FREE_WELCOME, 'Welcome');
        }
    }

    /**
     * Rent blocks now: the first month is taken today, in full.
     *
     * ponytail: not prorated — a block bought on the 28th costs a full month
     * and renews on the 1st. Prorate if anyone asks.
     *
     * @throws ValidationException
     */
    public static function addBlocks(User $user, int $blocks): void
    {
        $owner = self::owner($user);
        $planGb = self::planGb($owner);
        $price = self::rentFor(self::blocks($owner) + $blocks, $planGb) - self::rentFor(self::blocks($owner), $planGb);

        DB::transaction(function () use ($owner, $blocks, $price) {
            try {
                Wallet::debit($owner, WalletTransactionType::CreditsRent, max($price, 1), null, null,
                    "+{$blocks} × ".self::BLOCK_GB.' GB this month', Wallet::CREDITS);
            } catch (RuntimeException) {
                throw ValidationException::withMessages([
                    'blocks' => "That needs {$price} Credits and the balance has ".self::balance($owner).'.',
                ]);
            }

            $owner->forceFill(['storage_blocks' => self::blocks($owner) + $blocks])->save();
        });
    }

    /**
     * Drop blocks from next month on. Nothing comes back for this month —
     * the dialog says so before it is confirmed.
     */
    public static function releaseBlocks(User $user, int $blocks): void
    {
        $owner = self::owner($user);
        $owner->forceFill(['storage_blocks' => max(0, self::blocks($owner) - $blocks)])->save();
    }

    /**
     * The first of the month: rent. Rent that cannot be paid lapses the
     * blocks (storage read-only until it fits; nothing is deleted). Plans
     * add no Credits — they bring a publishing allowance instead.
     *
     * @return 'renewed'|'lapsed'|'none'
     */
    public static function monthly(User $owner): string
    {
        $outcome = 'none';
        $rent = self::rent($owner);

        if ($rent > 0) {
            try {
                Wallet::debit($owner, WalletTransactionType::CreditsRent, $rent, null, null,
                    self::blocks($owner).' × '.self::BLOCK_GB.' GB · '.now()->format('F'), Wallet::CREDITS);
                $outcome = 'renewed';
            } catch (RuntimeException) {
                $owner->forceFill(['storage_blocks' => 0])->save();
                $outcome = 'lapsed';
            }
        }

        return $outcome;
    }

    private static function pendingDestinations(User $user): int
    {
        $owner = self::owner($user);

        return (int) SocialPost::query()
            ->whereIn('user_id', Workspace::memberIds($owner))
            ->where('status', SocialPostStatus::Scheduled)
            ->whereNotNull('provider_post_id')
            // Only what Credits paid for: an allowance publish costs nothing back.
            ->withCount(['targets' => fn ($q) => $q->where('status', SocialPostTargetStatus::Pending)
                ->where('paid_with', SocialPostTarget::CREDITS)])
            ->get()
            ->sum('targets_count');
    }

    private static function planGb(User $owner): int
    {
        $bytes = PlanQuota::planStorageLimit($owner);

        return $bytes === null ? 0 : intdiv($bytes, 1024 * 1024 * 1024);
    }

    /** Credits are pooled at the account owner, like storage. */
    private static function owner(User $user): User
    {
        return Workspace::owner($user);
    }
}
