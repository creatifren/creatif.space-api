<?php

namespace App\Support;

use App\Enums\SocialAccountStatus;
use App\Exceptions\InboxUnsupported;
use App\Exceptions\ProviderRateLimited;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Who sees whose Inbox, and how a provider's refusal reads to the app.
 *
 * Inbox rows belong to the social account's user. The account's owner
 * sees them, and so does an active member of the owner's team — the same
 * reach as the rest of the workspace.
 */
final class Inbox
{
    /**
     * Whose Inbox rows this person may see: their own, and their
     * workspace owner's.
     *
     * @return list<int>
     */
    public static function userIds(User $user): array
    {
        return array_values(array_unique([$user->id, Workspace::owner($user)->id]));
    }

    /**
     * A disconnected channel's DMs and comments are never listed or read,
     * in the app or through MCP (P1 privacy): hidden at once, deleted by
     * InboxPurge.
     */
    public static function onLiveChannel(?SocialAccount $account): bool
    {
        return $account !== null && $account->status !== SocialAccountStatus::Disconnected;
    }

    /**
     * Keeps a conversation or comment query to channels that are not
     * disconnected.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function liveChannels(Builder $query): Builder
    {
        return $query->whereHas('account', fn (Builder $a) => $a->where('status', '!=', SocialAccountStatus::Disconnected->value));
    }

    public static function owns(User $user, int $rowUserId): bool
    {
        return in_array($rowUserId, self::userIds($user), true);
    }

    public static function canWrite(User $user): bool
    {
        return Workspace::canWrite($user);
    }

    /**
     * A provider's "no" as a JSON error the app can act on: the HTTP
     * status (422/403/409 kept), a stable `code`, a sentence to show, and
     * the provider's own code when it gave one. Null when this is not a
     * provider refusal — the caller rethrows.
     */
    public static function error(Throwable $e, ?SocialAccount $account = null, string $context = 'message'): ?JsonResponse
    {
        if ($e instanceof InboxUnsupported) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'inbox_unsupported'], 422);
        }

        if ($e instanceof ProviderRateLimited) {
            return response()->json([
                'message' => "Instagram is asking us to slow down. Try again in {$e->retryAfter} seconds.",
                'code' => 'rate_limited',
                'retry_after' => $e->retryAfter,
            ], 429)->header('Retry-After', (string) $e->retryAfter);
        }

        // The relay did not answer at all (timeout, DNS, reset): the same
        // readable 502 as a relay 5xx, not a bare 500.
        if ($e instanceof ConnectionException) {
            return response()->json([
                'message' => 'Instagram could not be reached just now. Try again shortly.',
                'code' => 'provider_error',
            ], 502);
        }

        if (! $e instanceof RequestException) {
            return null;
        }

        $status = $e->response->status();
        $providerCode = self::providerCode($e);
        $providerMessage = self::providerMessage($e);
        $base = ['provider_code' => $providerCode, 'provider_message' => $providerMessage];

        if ($status === 422) {
            $window = $providerCode !== null && preg_match('/no_inbound|window|24|outside|expired/i', $providerCode);

            return response()->json(($window ? [
                'message' => 'Instagram only allows a reply within 24 hours of their last message.',
                'code' => 'reply_window_closed',
            ] : [
                'message' => $providerMessage ?? 'Instagram refused that '.$context.'.',
                'code' => 'provider_rejected',
            ]) + $base, 422);
        }

        if ($status === 403) {
            // The grant lacks the messages scope (or lost it): reconnect.
            if ($account !== null && $context !== 'comment reply') {
                $account->forceFill(['messages_scope_at' => null])->save();
            }

            return response()->json([
                'message' => 'Reconnect this account to allow '.($context === 'comment reply' ? 'comment replies' : 'messages').'.',
                'code' => 'permission_missing',
                'needs_reconnect' => true,
            ] + $base, 403);
        }

        if ($status === 409) {
            return response()->json([
                'message' => $context === 'private reply'
                    ? 'This comment already has its private reply.'
                    : ($providerMessage ?? 'That conflicts with the conversation’s current state.'),
                'code' => $context === 'private reply' ? 'already_replied' : 'conflict',
            ] + $base, 409);
        }

        if ($status === 404) {
            return response()->json(['message' => 'Instagram no longer has that.', 'code' => 'not_found'] + $base, 404);
        }

        return response()->json([
            'message' => 'Instagram could not be reached just now. Try again shortly.',
            'code' => 'provider_error',
        ] + $base, 502);
    }

    private static function providerCode(RequestException $e): ?string
    {
        $code = $e->response->json('error.code') ?? $e->response->json('code')
            ?? (is_string($e->response->json('error')) ? $e->response->json('error') : null);

        return is_scalar($code) ? (string) $code : null;
    }

    private static function providerMessage(RequestException $e): ?string
    {
        $m = $e->response->json('error.message') ?? $e->response->json('message');

        return is_string($m) && $m !== '' ? $m : null;
    }
}
