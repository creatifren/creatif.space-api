<?php

namespace App\Jobs;

use App\Contracts\SocialProvider;
use App\Enums\SocialPostStatus;
use App\Exceptions\InboxUnsupported;
use App\Exceptions\ProviderRateLimited;
use App\Models\SocialAccount;
use App\Models\SocialConversation;
use App\Models\SocialPost;
use App\Support\InboxStore;
use App\Support\SocialProviders;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Backfill one account's Inbox: its DM threads (and the messages of the
 * ones that moved), and the comments on its recent posts. Runs on the
 * account's first Inbox open and hourly after that (SyncInboxes); the
 * webhooks are the live path, this is the safety net. Quiet: nothing it
 * files rings the bell.
 *
 * Rate-limit aware: when Outstand says wait (429 / X-RateLimit-Remaining
 * 0, with Retry-After or X-RateLimit-Reset), the job puts itself back for
 * exactly that long; everything already filed stays filed.
 */
class SyncInbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Pages of threads per run (50 each). */
    private const CONVERSATION_PAGES = 4;

    /** Recent posts whose comments are read per run. */
    private const POSTS = 20;

    /** How far back a post is still worth reading comments on. */
    private const POST_DAYS = 30;

    public int $tries = 5;

    public int $uniqueFor = 900;

    public function __construct(public int $accountId) {}

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function handle(SocialProviders $providers): void
    {
        $account = SocialAccount::query()->find($this->accountId);

        if ($account === null
            || ! $account->isUsable()
            || ! config('services.inbox.enabled')
            || ! ($account->supportsMessages() || $account->supportsComments())) {
            return;
        }

        $provider = $providers->for($account);
        if (! $provider->supportsInbox()) {
            return;
        }

        try {
            if ($account->supportsMessages()) {
                $this->syncConversations($provider, $account);
            }
            if ($account->supportsComments()) {
                $this->syncComments($provider, $account);
            }
            $account->forceFill(['inbox_synced_at' => now()])->save();
        } catch (ProviderRateLimited $e) {
            $this->job === null ? throw $e : $this->release($e->retryAfter);
        }
    }

    /**
     * Threads, then the messages of each thread that moved since its last
     * read. A 403 means no messages scope: the account is marked for a
     * reconnect and comments still sync.
     */
    private function syncConversations(SocialProvider $provider, SocialAccount $account): void
    {
        $cursor = null;
        $seen = 0;

        try {
            for ($page = 0; $page < self::CONVERSATION_PAGES; $page++) {
                $result = $provider->conversations($account->provider_account_id, $cursor);

                foreach ($result['data'] as $remote) {
                    if (($remote['id'] ?? '') === '') {
                        continue;
                    }
                    $seen++;
                    $before = SocialConversation::query()
                        ->where('provider', $account->provider)
                        ->where('provider_conversation_id', $remote['id'])
                        ->value('last_message_at');
                    $conversation = InboxStore::upsertConversation($account, $remote);

                    if ($conversation->messages_synced_at === null
                        || ($before !== null && $conversation->last_message_at?->greaterThan($before))) {
                        self::syncMessages($provider, $conversation);
                    }
                }

                $cursor = $result['cursor'];
                if ($cursor === null) {
                    break;
                }
            }
        } catch (RequestException $e) {
            if ($e->response->status() !== 403) {
                throw $e;
            }
            $account->forceFill(['messages_scope_at' => null])->save();

            return;
        }

        // Threads came back: the grant reads DMs.
        if ($seen > 0 && $account->messages_scope_at === null) {
            $account->forceFill(['messages_scope_at' => now()])->save();
        }
    }

    /** The newest page of one thread's messages. */
    public static function syncMessages(SocialProvider $provider, SocialConversation $conversation): void
    {
        $result = $provider->messages($conversation->provider_conversation_id);

        // Oldest first, so the thread's "last message" ends on the newest.
        foreach (array_reverse($result['data']) as $remote) {
            InboxStore::upsertMessage($conversation, $remote, countUnread: false);
        }

        $conversation->forceFill(['messages_synced_at' => now()])->save();
    }

    /**
     * Comments on the account's recent posts made through us. Posts made
     * elsewhere are not known to Outstand by id, so they come in by webhook
     * only.
     */
    private function syncComments(SocialProvider $provider, SocialAccount $account): void
    {
        $posts = SocialPost::query()
            ->where('provider', $account->provider)
            ->whereNotNull('provider_post_id')
            ->where('status', SocialPostStatus::Published)
            ->where('updated_at', '>=', now()->subDays(self::POST_DAYS))
            ->whereHas('targets', fn ($q) => $q->where('social_account_id', $account->id))
            ->latest('id')
            ->limit(self::POSTS)
            ->pluck('provider_post_id');

        foreach ($posts as $postId) {
            try {
                $comments = $provider->comments((string) $postId, $account->platform->value, (string) $account->username);
            } catch (InboxUnsupported) {
                return;
            } catch (RequestException $e) {
                // One post the platform will not show (deleted, no scope)
                // does not stop the rest.
                Log::info('inbox: comments unavailable', ['post' => $postId, 'status' => $e->response->status()]);

                continue;
            }

            foreach ($comments as $comment) {
                InboxStore::upsertComment($account, $comment, $account->platform->value);
            }
        }
    }
}
