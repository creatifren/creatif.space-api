<?php

namespace App\Services;

use App\Casts\UtcDateTime;
use App\Contracts\SocialProvider;
use App\Exceptions\InboxUnsupported;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The relay behind the directory reviewer's demo channel (reviewer:setup):
 * it never makes a network call. A post sent to it gets an id and, once
 * due, the result "demo channel, nothing is posted" (DEMO_MARKER, read by
 * PublishFailure as `demo_channel`), so its Credits come back the usual
 * way. Its feed and follower count are fixed sample numbers for Insight.
 */
class DemoSocialProvider implements SocialProvider
{
    public const NAME = 'demo';

    /** What applyResult classifies as `demo_channel` (PublishFailure). */
    public const DEMO_MARKER = '[creatif:demo_channel] demo channel, nothing is posted';

    public const FOLLOWERS = 1240;

    public function name(): string
    {
        return self::NAME;
    }

    public function authUrl(string $platform, string $externalId, array $bluesky = [], ?string $client = null): string
    {
        throw new RuntimeException('The demo channel is seeded by staff; it has no sign-in.');
    }

    public function accountsFor(string $externalId): array
    {
        return [];
    }

    public function account(string $accountId): ?array
    {
        return ['id' => $accountId, 'platform' => 'instagram', 'username' => 'creatif_reviewer_demo', 'status' => 'connected'];
    }

    public function canReassign(): bool
    {
        return false;
    }

    public function assignAccount(string $accountId, string $externalId): void {}

    public function disconnect(string $accountId): void {}

    public function deleteAccount(string $accountId): void {}

    public function createPost(array $payload): array
    {
        return ['id' => 'demo_'.Str::lower((string) Str::ulid()), 'status' => 'scheduled'];
    }

    public function updatePost(string $postId, array $payload): void {}

    public function replaceTargets(string $postId, array $payload): string
    {
        return $postId;
    }

    public function deletePost(string $postId): void {}

    public function deletePublished(string $postId): array
    {
        return [];
    }

    /**
     * Every due demo post's destinations: "nothing is posted". Read from our
     * own rows — this relay keeps no state of its own.
     */
    public function resultsFor(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $results = [];
        $posts = SocialPost::query()
            ->where('provider', self::NAME)
            ->where(fn ($q) => $q->whereIn('provider_post_id', $postIds)
                ->orWhereHas('targets', fn ($t) => $t->whereIn('retry_provider_post_id', $postIds)))
            ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', UtcDateTime::bind(now())))
            ->with('targets.account')
            ->get();

        foreach ($posts as $post) {
            foreach ($post->targets as $target) {
                /** @var SocialPostTarget $target */
                if ($target->account === null) {
                    continue;
                }
                $results[] = [
                    'post_id' => (string) ($target->retry_provider_post_id ?? $post->provider_post_id),
                    'social_account_id' => $target->account->provider_account_id,
                    'success' => false,
                    'error' => self::DEMO_MARKER,
                ];
            }
        }

        return $results;
    }

    /**
     * Twelve sample posts over the last 84 days with fixed numbers, newest
     * first, so Insight has something to show. Captions are made up.
     */
    public function feed(string $accountId, ?string $cursor = null, int $limit = 25): array
    {
        $topics = ['Weekend promo', 'New matcha latte', 'Behind the bar', 'Latte art class', 'Morning pastries', 'Rainy-day menu',
            'Meet the team', 'Cold brew is back', 'Customer favourite', 'Saturday market', 'Seasonal beans', 'Thank you, 1k followers'];
        $items = [];
        foreach ($topics as $i => $topic) {
            $reach = 900 + ((($i * 37) % 11) * 140);
            $items[] = [
                'platform_post_id' => 'demo_feed_'.($i + 1),
                'caption' => $topic.' (sample post)',
                'platform_url' => null,
                'posted_at' => now()->utc()->startOfDay()->subDays(3 + $i * 7)->addHours(10)->toIso8601String(),
                'media' => [],
                'provider_post_id' => '',
                'metrics' => [
                    'reach' => $reach,
                    'impressions' => (int) round($reach * 1.4),
                    'likes' => (int) round($reach * 0.06),
                    'comments' => 3 + ($i % 5),
                    'shares' => 1 + ($i % 4),
                    'saved' => 2 + ($i % 6),
                ],
            ];
        }

        return ['data' => $items, 'cursor' => null];
    }

    public function verifyWebhook(Request $request): bool
    {
        return false;
    }

    public function webhookEvents(Request $request): array
    {
        return [];
    }

    public function supportsInbox(): bool
    {
        return false;
    }

    public function followersCount(string $accountId): ?int
    {
        return self::FOLLOWERS;
    }

    public function conversations(string $accountId, ?string $cursor = null, int $limit = 50): array
    {
        throw InboxUnsupported::for('The demo channel', 'direct messages');
    }

    public function conversation(string $conversationId): ?array
    {
        throw InboxUnsupported::for('The demo channel', 'direct messages');
    }

    public function messages(string $conversationId, ?string $cursor = null, int $limit = 50): array
    {
        throw InboxUnsupported::for('The demo channel', 'direct messages');
    }

    public function sendMessage(string $conversationId, array $message): array
    {
        throw InboxUnsupported::for('The demo channel', 'direct messages');
    }

    public function deleteScheduledMessage(string $conversationId, string $messageId): void
    {
        throw InboxUnsupported::for('The demo channel', 'direct messages');
    }

    public function markConversationRead(string $conversationId): void
    {
        throw InboxUnsupported::for('The demo channel', 'direct messages');
    }

    public function privateReply(string $accountId, string $commentId, ?string $authorId, string $content): array
    {
        throw InboxUnsupported::for('The demo channel', 'private replies');
    }

    public function comments(string $postId, string $network, string $accountUsername): array
    {
        throw InboxUnsupported::for('The demo channel', 'reading comments');
    }

    public function replyToComment(string $postId, string $commentId, string $content, string $accountUsername): array
    {
        throw InboxUnsupported::for('The demo channel', 'replying to comments');
    }

    public function pinterestBoards(string $accountId): array
    {
        return [];
    }
}
