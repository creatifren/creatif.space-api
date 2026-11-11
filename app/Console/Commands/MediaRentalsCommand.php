<?php

namespace App\Console\Commands;

use App\Support\Media\StorageRental;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Storage rentals: still active (should be none after the Media v2
 * migration) and those it ended. Read-only.
 */
class MediaRentalsCommand extends Command
{
    protected $signature = 'media:rentals';

    protected $description = 'List storage rentals (active and ended by Media v2)';

    public function handle(): int
    {
        $active = StorageRental::active();
        $this->info('Active: '.count($active));
        $this->table(['User', 'Blocks', 'Next renewal (removed)'], array_map('array_values', $active));

        $ended = DB::table('users')->whereNotNull('storage_rental_ended_at')->orderBy('id')
            ->get(['ulid', 'storage_blocks', 'storage_rental_ended_at']);
        $this->info('Ended: '.$ended->count());
        $this->table(['User', 'Blocks', 'Ended at'], $ended->map(fn ($u) => [$u->ulid, $u->storage_blocks, $u->storage_rental_ended_at])->all());

        return self::SUCCESS;
    }
}
