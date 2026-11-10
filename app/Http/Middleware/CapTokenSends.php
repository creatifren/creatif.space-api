<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * At most DAILY_CAP sends a day per user from a bearer token (MCP clients
 * and API keys). The app signs in with the session cookie and is never
 * capped. Only a real send counts: a draft held for approval costs nothing
 * and is not one. The day is the UTC calendar day.
 */
class CapTokenSends
{
    public const DAILY_CAP = 20;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null || $request->user() === null) {
            return $next($request);
        }

        // A held post costs nothing and is not a send.
        if ($request->filled('approval')) {
            return $next($request);
        }

        // Reserve the slot first (atomic), give it back if the send fails:
        // two sends racing at 19 cannot both make it 21.
        $key = self::key($request->user());
        Cache::add($key, 0, now('UTC')->addDay()->startOfDay()->addHour());

        if ((int) Cache::increment($key) > self::DAILY_CAP) {
            Cache::decrement($key);

            return response()->json([
                'message' => 'Connected apps can send '.self::DAILY_CAP.' posts a day. Send this one from creatif.space, or try again tomorrow (UTC).',
                'code' => 'daily_send_cap',
                'cap' => self::DAILY_CAP,
                'resets_at' => now('UTC')->addDay()->startOfDay()->toIso8601String(),
            ], 429);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            Cache::decrement($key);

            throw $e;
        }

        if (! $response->isSuccessful()) {
            Cache::decrement($key);
        }

        return $response;
    }

    /**
     * @return array{cap: int, used: int, remaining: int}
     */
    public static function status(User $user): array
    {
        $used = (int) Cache::get(self::key($user), 0);

        return ['cap' => self::DAILY_CAP, 'used' => $used, 'remaining' => max(0, self::DAILY_CAP - $used)];
    }

    private static function key(User $user): string
    {
        return 'token-sends:'.$user->id.':'.now('UTC')->toDateString();
    }
}
