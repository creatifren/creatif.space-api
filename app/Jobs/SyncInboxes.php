<?php

namespace App\Jobs;

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hourly: a SyncInbox for every connected account whose Inbox was opened
 * at least once. Accounts nobody looks at cost no API calls.
 */
class SyncInboxes implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        if (! config('services.inbox.enabled')) {
            return;
        }

        SocialAccount::query()
            ->where('provider', 'outstand')
            ->usable()
            ->whereIn('platform', [SocialPlatform::Instagram, SocialPlatform::Facebook])
            ->whereNotNull('inbox_synced_at')
            ->select('id')
            ->chunkById(200, function ($accounts) {
                foreach ($accounts as $account) {
                    SyncInbox::dispatch($account->id);
                }
            });
    }
}
