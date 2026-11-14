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

    /** Why a check failed, in the owner's words — never a vendor's. */
    public const REASONS = [
        'not_found' => 'The network no longer lists this account. Reconnect it to keep posting.',
        'signin_expired' => 'The sign-in expired or was revoked. Reconnect it to keep posting.',
        'disconnected' => 'The channel is disconnected. Reconnect it to post to it.',
        'reconnect_required' => 'It was connected through an older connection that no longer works. Reconnect it to post to it.',
        // A publish to it was refused for its sign-in (PublishFailure::RECONNECT), whatever the relay's record says.
        'publish_unauthorized' => 'The network refused a post from this account because its sign-in no longer works. Reconnect it to keep posting.',
    ];

    /** Failure reasons only a reconnect fixes: the channel reads reconnect_required. */
    public const RECONNECT_REASONS = ['not_found', 'signin_expired', 'publish_unauthorized'];

    /**
     * A publish result is the strongest health check there is: a refusal
     * for the account's sign-in fails the channel (until a passing check or
     * a publish that goes out), a destination that went out makes it ok.
     * Only results the network reported for this account count — never our
     * own call failing — and never the demo channel.
     */
    public static function fromPublish(SocialAccount $account, bool $success, ?string $reasonCode = null): void
    {
        if ($account->is_demo) {
            return;
        }

        if ($success) {
            if ($account->health_status !== self::OK || $account->health_reason !== null) {
                $account->forceFill([
                    'health_status' => self::OK,
                    'health_reason' => null,
                    'last_verified_at' => now(),
                    'health_notified_at' => null,
                ])->save();
            }

            return;
        }

        if (! in_array($reasonCode, PublishFailure::RECONNECT, true)) {
            return;
        }

        $account->forceFill([
            'health_status' => self::FAILED,
            'health_reason' => 'publish_unauthorized',
            'last_verified_at' => now(),
        ])->save();
    }

    /** The channel can be posted to only after the owner reconnects it. */
    public static function reconnectRequired(SocialAccount $account): bool
    {
        if ($account->isLegacy()) {
            return true;
        }
        $shown = self::present($account);

        return $account->isUsable() && $shown['health_status'] === self::FAILED
            && in_array((string) $account->health_reason, self::RECONNECT_REASONS, true);
    }

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
     * @return array{health_status: string|null, health_reason: string|null, last_verified_at: mixed, needs_attention: bool}
     */
    public static function present(SocialAccount $account): array
    {
        if ($account->isUsable()) {
            // Connected again since the last check: that check is about the old sign-in.
            $stale = $account->last_verified_at !== null && $account->connected_at !== null
                && $account->last_verified_at->lt($account->connected_at);
            // Not checked yet (or not since it reconnected): null, not a guess.
            $status = $stale ? null : $account->health_status;
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
