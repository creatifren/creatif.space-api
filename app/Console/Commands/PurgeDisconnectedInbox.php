<?php

namespace App\Console\Commands;

use App\Support\InboxPurge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Deletes the Engage data (DMs, their messages, comments) of every
 * disconnected channel that still holds some — rows a bulk status update
 * or a racing webhook left behind. Disconnecting a channel deletes its
 * data at once (InboxPurge); this is the daily sweep behind it.
 */
class PurgeDisconnectedInbox extends Command
{
    protected $signature = 'inbox:purge-disconnected';

    protected $description = 'Delete the DMs and comments of disconnected channels';

    public function handle(): int
    {
        $done = InboxPurge::disconnected();
        Log::info('inbox.purged_disconnected', $done);
        $this->info(sprintf(
            'Purged %d channel(s): %d conversation(s), %d message(s), %d comment(s).',
            $done['accounts'], $done['conversations'], $done['messages'], $done['comments'],
        ));

        return self::SUCCESS;
    }
}
