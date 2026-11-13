<?php

namespace App\Support;

use App\Exceptions\ChannelCheckUnavailable;
use App\Models\SocialAccount;
use App\Notifications\ChannelNeedsAttention;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Is a channel still working? One live, read-only check through the relay
 * that holds it: the relay's record of the account (does it still exist,
 * is its sign-in still active). Nothing is posted.
 *
 * Run right after a connect or reconnect and once a day for every
 * connected channel (VerifyChannels), and on demand
 * (POST /social-accounts/{id}/verify). A failed check marks the channel
 * as needing attention; the daily round emails the owner once per failure
 * (publish_failed switch). A passing check clears it.
 */
class ChannelHealth
{
    public const OK = 'ok';

    public const FAILED = 'failed';

    /** Not checked yet (connected before checks existed, or just now). */
    public const UNCHECKED = 'unchecked';

    /** Why a check failed, in the owner's words — never a vendor's. */
    public const REASONS = [
        'not_found' => 'The network no longer lists this account. Reconnect it to keep posting.',
        'signin_expired' => 'The sign-in expired or was revoked. Reconnect it to keep posting.',
        'disconnected' => 'The channel is disconnected. Reconnect it to post to it.',
        'reconnect_required' => 'It was connected through an older connection that no longer works. Reconnect it to post to it.',
    ];

    public function __construct(private SocialProviders $providers) {}

    /**
     * Check one connected channel and store the result.
     *
     * @throws ChannelCheckUnavailable the relay did not answer; nothing is stored
     */
    public function check(SocialAccount $account): SocialAccount
    {
        $reason = $this->probe($account);

        $account->forceFill([
            'health_status' => $reason === null ? self::OK : self::FAILED,
            'health_reason' => $reason,
            'last_verified_at' => now(),
            // Working again: the next failure emails again.
            'health_notified_at' => $reason === null ? null : $account->health_notified_at,
        ])->save();

        return $account;
    }

    /**
     * Email the owner that the channel needs attention — once per failure,
     * claimed in one conditional update so two runs cannot both send it.
     */
    public function notifyOnce(SocialAccount $account): bool
    {
        if ($account->health_status !== self::FAILED) {
            return false;
        }

        $claimed = SocialAccount::query()
            ->whereKey($account->id)
            ->whereNull('health_notified_at')
            ->update(['health_notified_at' => now()]) === 1;

        if ($claimed) {
            $account->loadMissing('user')->user->notify(new ChannelNeedsAttention($account));
        }

        return $claimed;
    }

    /**
     * What the channel list shows: a channel that cannot be posted to
     * needs attention whatever the last check said.
     *
     * @return array{health_status: string, health_reason: string|null, last_verified_at: mixed, needs_attention: bool}
     */
    public static function present(SocialAccount $account): array
    {
        if ($account->isUsable()) {
            // Connected again since the last check: that check is about the old sign-in.
            $stale = $account->last_verified_at !== null && $account->connected_at !== null
                && $account->last_verified_at->lt($account->connected_at);
            $status = $stale ? self::UNCHECKED : ($account->health_status ?? self::UNCHECKED);
            $reason = $status === self::FAILED ? (self::REASONS[(string) $account->health_reason] ?? null) : null;
        } else {
            $status = self::FAILED;
            $reason = self::REASONS[$account->isLegacy() ? 'reconnect_required' : 'disconnected'];
        }

        return [
            'health_status' => $status,
            'health_reason' => $reason,
            'last_verified_at' => $account->last_verified_at,
            'needs_attention' => $status === self::FAILED,
        ];
    }

    /** The failure code, or null when the channel works. */
    private function probe(SocialAccount $account): ?string
    {
        try {
            $remote = $this->providers->for($account)->account($account->provider_account_id);
        } catch (RequestException $e) {
            // 404 is already null; anything else is the relay, not the channel.
            throw new ChannelCheckUnavailable('Channel check failed: HTTP '.$e->response->status(), 0, $e);
        } catch (Throwable $e) {
            throw new ChannelCheckUnavailable('Channel check failed: '.$e->getMessage(), 0, $e);
        }

        if ($remote === null) {
            return 'not_found';
        }

        return ($remote['status'] ?? 'connected') === 'connected' ? null : 'signin_expired';
    }
}
