<?php

namespace App\Support;

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pinterest boards on a post: a Pin cannot go out without one.
 *
 *  - A board named for a Pinterest channel (`options.{account}.board_id`,
 *    `pinterest.board_id` in the MCP tools) must be one of that account's
 *    boards; when the relay cannot list them, it is taken as given.
 *  - The board last chosen on a channel is remembered on it
 *    (`pinterest_default_board_id`) and used for the next Pin that names
 *    none — so neither the app nor a connected app has to ask every time.
 *  - With no board named and none remembered, an account with exactly one
 *    board gets that one; with several, preflight asks (missing_board).
 */
final class PinterestBoards
{
    public function __construct(private readonly SocialProviders $providers) {}

    /**
     * The account's boards, or null when the relay cannot say.
     *
     * @return list<array{id: string, name: string}>|null
     */
    public function of(SocialAccount $account): ?array
    {
        try {
            return $this->providers->named((string) $account->provider)->pinterestBoards($account->provider_account_id);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Each board named for a Pinterest channel is one of its boards.
     *
     * @param  iterable<SocialAccount>  $accounts
     * @param  array<string, array<string, mixed>>  $options  by account ulid
     *
     * @throws ValidationException
     */
    public function validate(iterable $accounts, array $options, string $field = 'options'): void
    {
        foreach ($accounts as $account) {
            $board = $options[$account->ulid]['board_id'] ?? null;
            if ($account->platform !== SocialPlatform::Pinterest || ! is_string($board) || $board === '') {
                continue;
            }

            $boards = $this->of($account);
            if ($boards === null || $boards === []) {
                continue;
            }

            if (! in_array($board, array_column($boards, 'id'), true)) {
                throw ValidationException::withMessages([
                    "{$field}.{$account->ulid}.board_id" => 'That board is not one of this Pinterest account\'s boards. '
                        ."Pick one of its boards (GET /social-accounts/{$account->ulid}/pinterest-boards; list_pinterest_boards in a connected app).",
                ]);
            }
        }
    }

    /**
     * The board named for each Pinterest channel becomes its default.
     *
     * @param  iterable<SocialAccount>  $accounts
     * @param  array<string, array<string, mixed>>  $options  by account ulid
     */
    public static function remember(iterable $accounts, array $options): void
    {
        foreach ($accounts as $account) {
            $board = $options[$account->ulid]['board_id'] ?? null;
            if ($account->platform === SocialPlatform::Pinterest && is_string($board) && $board !== ''
                && $account->pinterest_default_board_id !== $board) {
                $account->forceFill(['pinterest_default_board_id' => $board])->save();
            }
        }
    }

    /**
     * Fill in the board for each Pinterest channel that names none: its
     * remembered board while that is still one of its boards (or the relay
     * cannot say), else its only board.
     *
     * @param  iterable<SocialAccount>  $accounts
     * @param  array<string, array<string, mixed>>  $options  by account ulid
     * @return array<string, array<string, mixed>>
     */
    public function fill(iterable $accounts, array $options): array
    {
        foreach ($accounts as $account) {
            if ($account->platform !== SocialPlatform::Pinterest || ! empty($options[$account->ulid]['board_id'])) {
                continue;
            }

            $boards = $this->of($account);
            $remembered = $account->pinterest_default_board_id;
            $board = match (true) {
                is_string($remembered) && $remembered !== ''
                    && ($boards === null || $boards === [] || in_array($remembered, array_column($boards, 'id'), true)) => $remembered,
                $boards !== null && count($boards) === 1 => $boards[0]['id'],
                default => null,
            };

            if ($board !== null) {
                $options[$account->ulid] = [...($options[$account->ulid] ?? []), 'board_id' => $board];
            }
        }

        return $options;
    }
}
