<?php

namespace App\Exceptions;

use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * One or more accounts are not usable through the current relay — held by
 * another one (Post for Me before the Outstand switch) or disconnected — and
 * have to be reconnected. Rendered as a 422 with a machine code so the app
 * can send the user to Connections instead of showing a generic error.
 */
class AccountReconnectRequired extends RuntimeException
{
    public const CODE = 'account_reconnect_required';

    /** @param  list<SocialAccount>  $accounts */
    public function __construct(public readonly array $accounts, private readonly string $field = 'account_ids')
    {
        $labels = implode(', ', array_map(fn (SocialAccount $a) => $a->label(), $accounts));

        parent::__construct(count($accounts) === 1
            ? "This account needs to be reconnected: {$labels}"
            : "These accounts need to be reconnected: {$labels}");
    }

    /**
     * Refuse when any of these is not usable.
     *
     * @param  iterable<SocialAccount>  $accounts
     */
    public static function unlessUsable(iterable $accounts, string $field = 'account_ids'): void
    {
        $stale = [];
        foreach ($accounts as $account) {
            if (! $account->isUsable()) {
                $stale[] = $account;
            }
        }

        if ($stale !== []) {
            throw new self($stale, $field);
        }
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'accounts' => array_map(fn (SocialAccount $a) => [
                'id' => $a->ulid,
                'platform' => $a->platform,
                'username' => $a->username,
            ], $this->accounts),
            'errors' => [$this->field => [$this->getMessage()]],
        ], 422);
    }
}
