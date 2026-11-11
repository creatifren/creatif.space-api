<?php

namespace App\Support\Media;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Storage rental (5 GB blocks for 5.000 Credits a month) was removed on
 * 11 Nov 2026. What is left is the record: who was renting, how many
 * blocks, and when it ended. Nothing renews, nothing is charged, nothing is
 * refunded automatically, and no row is deleted.
 */
final class StorageRental
{
    /**
     * Rentals not yet marked ended.
     *
     * @return list<array{user_ulid: string, blocks: int, next_renewal: string}>
     */
    public static function active(): array
    {
        return DB::table('users')
            ->where('storage_blocks', '>', 0)
            ->whereNull('storage_rental_ended_at')
            ->orderBy('id')
            ->get(['ulid', 'storage_blocks'])
            ->map(fn ($u) => [
                'user_ulid' => (string) $u->ulid,
                'blocks' => (int) $u->storage_blocks,
                'next_renewal' => self::nextRenewal(),
            ])
            ->all();
    }

    /**
     * When the removed RenewCredits job would have charged next (it ran on
     * the 1st at 03:20, app time).
     */
    public static function nextRenewal(): string
    {
        $first = now()->startOfMonth()->setTime(3, 20);

        return ($first->isFuture() ? $first : $first->addMonthNoOverflow())->toIso8601String();
    }

    /**
     * Mark every active rental ended, logging each one. Idempotent.
     *
     * @return int how many were ended
     */
    public static function endAll(string $why): int
    {
        $rentals = self::active();

        foreach ($rentals as $rental) {
            Log::info('media.storage_rental_ended', $rental + ['why' => $why]);
        }

        if ($rentals !== []) {
            DB::table('users')
                ->where('storage_blocks', '>', 0)
                ->whereNull('storage_rental_ended_at')
                ->update(['storage_rental_ended_at' => now()]);
        }

        return count($rentals);
    }

    public static function ended(User $user): bool
    {
        return ($user->getAttributes()['storage_rental_ended_at'] ?? null) !== null;
    }
}
