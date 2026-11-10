<?php

namespace App\Support;

/**
 * The consent page's "It can never …" line, from the scopes being granted:
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
}
