<?php

namespace App\Support;

use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Enums\WalletTransactionType;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * One balance for sends and storage (the Credits comp, 2026-10).
 *
 * The whole price list: a Credit is Rp100 (tax included), a destination
 * that goes out costs 3, a 5 GB block of storage costs 50 a month — 45
 * once the account passes 100 GB, on every block, not only the ones above
 * the line. Credits never expire.
 *
 * Order matters: rent is taken on the first, before anything is sent, so
 * a send never spends money the rent was going to need. A send is taken
 * up front when the post is handed to the provider — 3 a destination —
 * and a destination that never goes out (failed, cancelled) comes back,
 * so the user is only ever out of pocket for what was delivered.
 * Approval, drafts, scheduling and uploads inside rented storage move
 * nothing.
 */
final class Credits
{
    public const RUPIAH = 100;

    public const PER_SEND = 3;

    public const BLOCK_GB = 5;

    public const PER_BLOCK = 50;

    public const PER_BLOCK_BULK = 45;

    /** Past this many plan-plus-rented GB, every block is at the bulk rate. */
    public const BULK_FROM_GB = 100;

    /** Monthly for paid plans (per seat), once at sign-up for Free. */
    public const PREMIUM_MONTHLY = 120;

    public const FREE_WELCOME = 30;

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
     * Take the send price for a post about to reach the provider: 3 per
     * destination. Refuses with a validation error the composer can show.
     *
     * @throws ValidationException
     */
    public static function chargeSend(SocialPost $post, int $destinations): void
    {
        $owner = self::owner($post->user);
        $price = $destinations * self::PER_SEND;

        if ($price === 0) {
            return;
        }

        try {
            Wallet::debit(
                $owner,
                WalletTransactionType::CreditsSend,
                $price,
                'social_post',
                $post->id,
                $destinations.' destination'.($destinations === 1 ? '' : 's'),
                Wallet::CREDITS,
            );
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'caption' => "This needs {$price} Credits and the balance has ".self::balance($owner)
                    .' — top up, or send to fewer accounts.',
            ]);
        }
    }

    /**
     * Give back the price of destinations that never went out.
     */
    public static function returnSend(SocialPost $post, int $destinations, string $why): void
    {
        if ($destinations <= 0) {
            return;
        }

        Wallet::credit(
            self::owner($post->user),
            WalletTransactionType::CreditsSendReturn,
            $destinations * self::PER_SEND,
            'social_post',
            $post->id,
            $why,
            Wallet::CREDITS,
        );
    }

    /**
     * Credits that can go back to a bank: only what was bought (a grant was
     * never paid for), less what was already withdrawn, and never more than
     * the balance holds.
     */
    public static function withdrawable(User $user): int
    {
        $owner = self::owner($user);
        $sum = fn (array $types) => (int) WalletTransaction::query()
            ->where('user_id', $owner->id)
            ->where('wallet', Wallet::CREDITS)
            ->whereIn('type', $types)
            ->sum('amount');

        $bought = $sum([WalletTransactionType::CreditsTopUp]);
        $out = $sum([WalletTransactionType::WithdrawalDebit, WalletTransactionType::WithdrawalFailedCredit]);

        return max(0, min(self::balance($owner), $bought + $out));
    }

    /**
     * Grant Credits (plan monthly, Free welcome, an admin gift).
     */
    public static function grant(User $user, int $credits, string $why): void
    {
        Wallet::credit(self::owner($user), WalletTransactionType::CreditsGrant, $credits, null, null, $why, Wallet::CREDITS);
    }

    /**
     * Free's thirty, once — at the moment the username is claimed.
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
     * The first of the month, in this order: rent, then the plan's Credits.
     * Rent that cannot be paid lapses the blocks (storage read-only until it
     * fits; nothing is deleted) — the plan's Credits land afterwards and go
     * to the sends already scheduled.
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

        if ($owner->activeSubscription() !== null) {
            self::grant($owner, self::PREMIUM_MONTHLY * Workspace::seats($owner), now()->format('F').' Credits');
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
            ->withCount(['targets' => fn ($q) => $q->where('status', SocialPostTargetStatus::Pending)])
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
