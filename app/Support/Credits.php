<?php

namespace App\Support;

use App\Enums\SocialPlatform;
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
 * top-up), a publish costs 300 ($0.03) — on X 2.000 when the caption has a
 * link (X bills us $0.20 for those) — a 5 GB block of storage costs 5.000 a
 * month, 4.500 once the account passes 100 GB, on every block.
 *
 * Two buckets (owner decision, 2026-11-09):
 * - Monthly Credits (wallet `monthly`): the plan's `credits_monthly`, granted
 *   at the start of each cycle and expired, unused, at its end — no rollover.
 *   Free 3.000, Pro 45.000, Max 150.000: yesterday's 10/150/500 publishes.
 * - Top-up Credits (wallet `credits`): purchases and grants. Never expire.
 * A send spends Monthly first, then Top-up; a destination that never goes
 * out gives each part back to the bucket it came from (Monthly only while
 * its cycle lasts — after that it had expired anyway). Rent is Top-up only.
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

    /**
     * One publish, when config says nothing: `services.social.per_send`
     * holds the live prices (CREDITS_PER_SEND, CREDITS_PER_SEND_X,
     * CREDITS_PER_SEND_X_LINK). Also what a legacy charge without a
     * recorded price is worth.
     */
    public const PER_SEND = 300;

    /** X's price for a caption with a link, when config says nothing. */
    public const PER_SEND_X_LINK = 2000;

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

    /** Top-up Credits: purchased and granted, never expire. */
    public static function balance(User $user): int
    {
        return Wallet::balance(self::owner($user), Wallet::CREDITS);
    }

    /** What is left of this cycle's Monthly Credits (granted on first ask). */
    public static function monthlyBalance(User $user): int
    {
        $owner = self::owner($user);
        self::renewMonthly($owner);

        return Wallet::balance($owner, Wallet::MONTHLY);
    }

    /** Everything a send can spend right now: Monthly plus Top-up. */
    public static function total(User $user): int
    {
        return self::monthlyBalance($user) + self::balance($user);
    }

    /** The plan's Monthly Credits per cycle — per seat, pooled like storage. */
    public static function monthlyAmount(User $owner): int
    {
        $owner = self::owner($owner);
        $per = $owner->plan()->quota('credits_monthly');

        return $per === null ? 0 : max(0, (int) $per) * Workspace::seats($owner);
    }

    /**
     * Start the cycle if it has not been started: what is left of the last
     * cycle's Monthly Credits expires, this cycle's are granted — a ledger
     * row for each. A plan bought mid-cycle tops the cycle up to its own
     * amount (never back down, and never twice). Safe to call on every
     * read; the job calls it daily so expiry shows even for the idle.
     */
    public static function renewMonthly(User $owner): void
    {
        $owner = self::owner($owner);
        $since = self::cycleSince($owner);
        $amount = self::monthlyAmount($owner);

        DB::transaction(function () use ($owner, $since, $amount) {
            User::query()->whereKey($owner->id)->lockForUpdate()->first();

            $grants = WalletTransaction::query()
                ->where('user_id', $owner->id)
                ->where('wallet', Wallet::MONTHLY)
                ->where('type', WalletTransactionType::CreditsMonthly)
                ->where('created_at', '>=', $since);
            $started = (clone $grants)->exists();
            $granted = (int) $grants->sum('amount');

            if (! $started) {
                $left = Wallet::balance($owner, Wallet::MONTHLY);
                if ($left > 0) {
                    Wallet::debit($owner, WalletTransactionType::CreditsExpired, $left, null, null,
                        'Monthly Credits expired · cycle to '.$since->format('j M Y'), Wallet::MONTHLY);
                }
            }

            if ($amount > $granted) {
                $plan = $owner->plan();
                Wallet::credit($owner, WalletTransactionType::CreditsMonthly, $amount - $granted, 'plan', $plan->id,
                    "{$plan->name} Monthly Credits · from ".$since->format('j M Y'), Wallet::MONTHLY);
            }
        });
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
     * Already spoken for: sends handed over but not yet out (Top-up, and
     * Monthly apart), and next month's rent.
     *
     * @return array{sends: int, sends_monthly: int, rent: int}
     */
    public static function committed(User $user): array
    {
        [$topup, $monthly] = self::pendingSendCredits($user);

        return [
            'sends' => $topup,
            'sends_monthly' => $monthly,
            'rent' => self::rent($user),
        ];
    }

    /**
     * Pay for a post about to reach the provider: each destination not yet
     * paid for costs its price (perSend: platform, and on X whether its
     * caption has a link), from Monthly Credits while they last, then from
     * Top-up. Each target remembers both parts, so a return gives back
     * exactly that to each bucket. Refuses with a validation error the
     * composer can show, and then nothing is taken or marked.
     *
     * The owner's row is locked: two sends at once must not both spend the
     * last of the Monthly Credits.
     *
     * @throws ValidationException
     */
    public static function chargeSend(SocialPost $post): void
    {
        $owner = self::owner($post->user);
        self::renewMonthly($owner);

        DB::transaction(function () use ($post, $owner) {
            User::query()->whereKey($owner->id)->lockForUpdate()->first();

            $targets = $post->targets()
                ->where('status', SocialPostTargetStatus::Pending)
                ->whereNull('paid_with')
                ->with('account')
                ->orderBy('id')
                ->get();

            if ($targets->isEmpty()) {
                return;
            }

            $monthlyLeft = Wallet::balance($owner, Wallet::MONTHLY);
            $parts = [];
            foreach ($targets as $target) {
                $price = self::priceFor($post, $target);
                $monthly = min($price, $monthlyLeft);
                $monthlyLeft -= $monthly;
                $parts[] = [$target, $monthly, $price - $monthly];
            }

            $fromMonthly = array_sum(array_column($parts, 1));
            $fromTopup = array_sum(array_column($parts, 2));
            $note = count($parts).' publish'.(count($parts) === 1 ? '' : 'es');

            // Top-up first: it is the one that can refuse, and a refusal must
            // leave the Monthly Credits untouched (the transaction rolls back).
            if ($fromTopup > 0) {
                try {
                    Wallet::debit($owner, WalletTransactionType::CreditsSend, $fromTopup,
                        'social_post', $post->id, $note, Wallet::CREDITS);
                } catch (RuntimeException) {
                    throw ValidationException::withMessages([
                        'caption' => "This needs {$fromTopup} Credits past this month's and the balance has "
                            .self::balance($owner).' — top up, or send to fewer accounts.',
                    ]);
                }
            }

            if ($fromMonthly > 0) {
                Wallet::debit($owner, WalletTransactionType::CreditsSend, $fromMonthly,
                    'social_post', $post->id, $note.' · Monthly Credits', Wallet::MONTHLY);
            }

            $now = now();
            foreach ($parts as [$target, $monthly, $topup]) {
                $target->update([
                    'paid_with' => $monthly > 0 ? SocialPostTarget::MONTHLY : SocialPostTarget::CREDITS,
                    'charged_monthly' => $monthly,
                    'charged_credits' => $topup,
                    'charged_at' => $now,
                ]);
            }
        });
    }

    /**
     * Give back what destinations that never went out paid, each part to
     * its bucket: Top-up always, Monthly only while the cycle it was spent
     * in lasts. A target already given back, or never charged, gives
     * nothing; a legacy allowance publish gives nothing (the allowance is
     * gone), a legacy Credits one gives 300.
     *
     * @param  iterable<SocialPostTarget>  $targets
     */
    public static function returnSend(SocialPost $post, iterable $targets, string $why): void
    {
        $ids = collect($targets)->pluck('id');
        $owner = self::owner($post->user);
        $since = self::cycleSince($owner);

        DB::transaction(function () use ($post, $ids, $owner, $why, $since) {
            User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
            // Fresh state under the same lock as charging. Two failures or a
            // cancel racing a failure must not return a target twice.
            $charged = $post->targets()->whereKey($ids)->whereNotNull('paid_with')->lockForUpdate()->get();

            $topup = (int) $charged->sum(fn (SocialPostTarget $t) => match ($t->paid_with) {
                SocialPostTarget::CREDITS => $t->charged_credits ?? self::PER_SEND,
                SocialPostTarget::MONTHLY => (int) $t->charged_credits,
                default => 0,
            });
            $monthly = (int) $charged
                ->filter(fn (SocialPostTarget $t) => $t->charged_at !== null && $t->charged_at->greaterThanOrEqualTo($since))
                ->sum(fn (SocialPostTarget $t) => (int) $t->charged_monthly);

            SocialPostTarget::query()->whereKey($charged->pluck('id'))->update([
                'paid_with' => null, 'charged_at' => null, 'charged_credits' => null, 'charged_monthly' => null,
            ]);

            if ($topup > 0) {
                Wallet::credit($owner, WalletTransactionType::CreditsSendReturn, $topup,
                    'social_post', $post->id, $why, Wallet::CREDITS);
            }

            if ($monthly > 0) {
                Wallet::credit($owner, WalletTransactionType::CreditsSendReturn, $monthly,
                    'social_post', $post->id, $why, Wallet::MONTHLY);
            }
        });
    }

    /**
     * Top-up Credits a send would take right now: its price past what the
     * Monthly Credits cover — so it compares with balance(). Destinations
     * are a count (each at the default price) or, per destination, its
     * platform or [platform, caption] — what chargeSend will price.
     *
     * @param  int|iterable<SocialPlatform|string|array{0: SocialPlatform|string|null, 1?: string|null}|null>  $destinations
     */
    public static function sendPrice(User $user, int|iterable $destinations): int
    {
        $price = is_int($destinations)
            ? $destinations * self::perSend()
            : (int) collect($destinations)->sum(fn ($d) => is_array($d)
                ? self::perSend($d[0] ?? null, $d[1] ?? null)
                : self::perSend($d));

        return max(0, $price - self::monthlyBalance($user));
    }

    /**
     * One publish on this platform. X costs more when the caption carries
     * a link, because X's API bills us $0.20 for those ($0.015 without).
     */
    public static function perSend(SocialPlatform|string|null $platform = null, ?string $caption = null): int
    {
        $key = $platform instanceof SocialPlatform ? $platform->value : $platform;

        if ($key === 'x' && $caption !== null && self::hasLink($caption)) {
            return self::price('x_link', self::PER_SEND_X_LINK);
        }

        return $key !== null && config("services.social.per_send.{$key}") !== null
            ? self::price($key, self::PER_SEND)
            : self::price('default', self::PER_SEND);
    }

    /** One target's price: its platform, its own caption or the post's. */
    public static function priceFor(SocialPost $post, SocialPostTarget $target): int
    {
        $caption = $target->configuration['caption'] ?? $post->caption;

        return self::perSend($target->account->platform, is_string($caption) ? $caption : null);
    }

    /**
     * Would X count a link in this text? Close to X's own rule: a scheme,
     * a www., or a bare domain on a common TLD (example.com, shop.id/x).
     */
    public static function hasLink(string $text): bool
    {
        return (bool) preg_match(
            '~(?:https?://|\bwww\.)\S|\b[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.(?:com|net|org|io|co|id|me|app|dev|ly|so|link|xyz|ai|gg|tv|shop|store|site|online|info|biz|us|uk|to|be|page|art|space)\b(?:/\S*)?~i',
            $text,
        );
    }

    /**
     * The send price of every platform (X without a link), plus `x_link`,
     * for the screens that show one.
     *
     * @return array<string, int>
     */
    public static function perSendByPlatform(): array
    {
        return collect(SocialPlatform::cases())
            ->mapWithKeys(fn (SocialPlatform $p) => [$p->value => self::perSend($p)])
            ->put('x_link', self::perSend('x', 'https://x.com'))
            ->all();
    }

    /**
     * Legacy: publishes taken from the count-based allowance this cycle.
     * Nothing is charged to it any more, so this reads 0 once a cycle has
     * passed; kept for responses that still show it.
     */
    public static function allowanceUsed(User $user): int
    {
        $owner = self::owner($user);

        return SocialPostTarget::query()
            ->where('paid_with', SocialPostTarget::ALLOWANCE)
            ->where('charged_at', '>=', self::allowanceSince($owner))
            ->whereHas('post', fn ($q) => $q->whereIn('user_id', Workspace::memberIds($owner)))
            ->count();
    }

    /**
     * Sends paid for this cycle (one per destination), by any wallet —
     * a returned send hands its charge back and stops counting.
     */
    public static function sendsThisCycle(User $user): int
    {
        $owner = self::owner($user);

        return SocialPostTarget::query()
            ->whereNotNull('paid_with')
            ->where('charged_at', '>=', self::cycleSince($owner))
            ->whereHas('post', fn ($q) => $q->whereIn('user_id', Workspace::memberIds($owner)))
            ->count();
    }

    /** @deprecated the allowance became Monthly Credits; same cycle. */
    public static function allowanceSince(User $owner): CarbonImmutable
    {
        return self::cycleSince($owner);
    }

    /**
     * When the current Monthly Credits cycle began. It turns monthly on the
     * day of the sign-up (Free) or of the subscription's start (Pro, Max,
     * yearly plans too); a month without that day turns on its last day.
     */
    public static function cycleSince(User $owner): CarbonImmutable
    {
        $anchor = $owner->activeSubscription()?->current_period_start ?? $owner->created_at;
        $day = $anchor === null ? 1 : $anchor->day;
        $on = fn (CarbonImmutable $month) => $month->setDay(min($day, $month->daysInMonth))->startOfDay();

        $month = CarbonImmutable::now()->startOfMonth();
        $start = $on($month);

        return $start->isFuture() ? $on($month->subMonthNoOverflow()) : $start;
    }

    /** When this cycle ends and the next one's Monthly Credits arrive. */
    public static function cycleEnds(User $owner): CarbonImmutable
    {
        $anchor = $owner->activeSubscription()?->current_period_start ?? $owner->created_at;
        $day = $anchor === null ? 1 : $anchor->day;
        $next = self::cycleSince($owner)->startOfMonth()->addMonthNoOverflow();

        return $next->setDay(min($day, $next->daysInMonth))->startOfDay();
    }

    /**
     * Grant Top-up Credits (Free welcome, an admin gift). Never expire.
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
     * blocks (storage read-only until it fits; nothing is deleted). Rent is
     * paid from Top-up Credits only; Monthly Credits are for publishing.
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

    /** One Inbox reply (CREDITS_PER_REPLY); zero means replies are free. */
    public static function perReply(): int
    {
        $price = config('services.inbox.credits_per_reply');

        return is_numeric($price) && (int) $price > 0 ? (int) $price : 0;
    }

    /**
     * Pay for one Inbox reply about to reach the provider, Monthly Credits
     * first, then Top-up — the same order as a send. Returns the parts
     * taken, [Monthly, Top-up], for returnReply when the provider refuses.
     * Free (the default) takes nothing.
     *
     * @return array{0: int, 1: int}
     *
     * @throws ValidationException
     */
    public static function chargeReply(User $user, string $note, string $field = 'content'): array
    {
        $price = self::perReply();
        if ($price === 0) {
            return [0, 0];
        }

        $owner = self::owner($user);
        self::renewMonthly($owner);

        return DB::transaction(function () use ($owner, $price, $note, $field) {
            User::query()->whereKey($owner->id)->lockForUpdate()->first();

            $monthly = min($price, Wallet::balance($owner, Wallet::MONTHLY));
            $topup = $price - $monthly;

            if ($topup > 0) {
                try {
                    Wallet::debit($owner, WalletTransactionType::CreditsReply, $topup, null, null, $note, Wallet::CREDITS);
                } catch (RuntimeException) {
                    throw ValidationException::withMessages([
                        $field => "A reply costs {$price} Credits and the balance has ".self::total($owner).' — top up to reply.',
                    ]);
                }
            }

            if ($monthly > 0) {
                Wallet::debit($owner, WalletTransactionType::CreditsReply, $monthly, null, null, $note.' · Monthly Credits', Wallet::MONTHLY);
            }

            return [$monthly, $topup];
        });
    }

    /**
     * Give back what chargeReply took, each part to its bucket.
     *
     * @param  array{0: int, 1: int}  $parts
     */
    public static function returnReply(User $user, array $parts, string $why): void
    {
        $owner = self::owner($user);
        [$monthly, $topup] = $parts;

        if ($topup > 0) {
            Wallet::credit($owner, WalletTransactionType::CreditsReplyReturn, $topup, null, null, $why, Wallet::CREDITS);
        }
        if ($monthly > 0) {
            Wallet::credit($owner, WalletTransactionType::CreditsReplyReturn, $monthly, null, null, $why, Wallet::MONTHLY);
        }
    }

    /**
     * Credits taken for sends handed over but not yet out: [Top-up, Monthly].
     *
     * @return array{0: int, 1: int}
     */
    private static function pendingSendCredits(User $user): array
    {
        $owner = self::owner($user);

        $targets = SocialPostTarget::query()
            ->where('status', SocialPostTargetStatus::Pending)
            ->whereIn('paid_with', [SocialPostTarget::CREDITS, SocialPostTarget::MONTHLY])
            ->whereHas('post', fn ($q) => $q
                ->whereIn('user_id', Workspace::memberIds($owner))
                ->where('status', SocialPostStatus::Scheduled)
                ->whereNotNull('provider_post_id'))
            ->get(['id', 'paid_with', 'charged_credits', 'charged_monthly']);

        return [
            (int) $targets->sum(fn (SocialPostTarget $t) => $t->charged_credits
                ?? ($t->paid_with === SocialPostTarget::CREDITS ? self::PER_SEND : 0)),
            (int) $targets->sum(fn (SocialPostTarget $t) => (int) $t->charged_monthly),
        ];
    }

    private static function price(string $key, int $fallback): int
    {
        $price = config("services.social.per_send.{$key}");

        return is_numeric($price) && (int) $price >= 0 ? (int) $price : $fallback;
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
