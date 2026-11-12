<?php

namespace App\Support;

use App\Models\User;

/**
 * Words for the MCP consent page. The "It can never …" line, from the scopes being granted:
 * only what those scopes do NOT allow. Publish brings scheduling and
 * spending Credits with it; approve, client approval; nothing a token
 * holds can delete.
 */
final class McpConsent
{
    /** What each action needs; null = no scope allows it. */
    private const ACTIONS = [
        'publish' => 'publish',
        'schedule' => 'publish',
        'approve' => 'approve',
        'delete' => null,
        'spend Credits' => 'publish',
    ];

    /**
     * @param  iterable<string>  $scopes  the scope ids being granted
     */
    public static function never(iterable $scopes): string
    {
        $granted = [];
        foreach ($scopes as $scope) {
            $granted[$scope] = true;
        }

        $never = [];
        foreach (self::ACTIONS as $action => $needs) {
            if ($needs === null || ! isset($granted[$needs])) {
                $never[] = $action;
            }
        }

        $last = array_pop($never);
        $list = $never === [] ? $last : implode(', ', $never).' or '.$last;

        return "It can never {$list}.";
    }

    /**
     * Enough of an address for its owner to recognise it, not enough for
     * someone looking over their shoulder: the first two letters and the
     * domain, as in "ri***" at gmail.com.
     */
    public static function maskEmail(?string $email): string
    {
        $email = (string) $email;
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        $local = substr($email, 0, $at);
        $keep = mb_strlen($local) > 2 ? 2 : 1;

        return mb_substr($local, 0, $keep).'***'.substr($email, $at);
    }

    /**
     * The workspace a connected app acts in: the person's own, or the team
     * owner's for an active member (see Workspace::owner).
     */
    public static function workspaceName(User $user): string
    {
        $owner = Workspace::owner($user);
        $name = trim((string) $owner->name);

        return $name === '' ? 'Your workspace' : $name;
    }
}
