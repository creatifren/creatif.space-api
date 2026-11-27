<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\RateLimitHeaders;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts sends a day per user from a bearer token (MCP clients and API
 * keys); refuses past a cap only when one is set (services.mcp.daily_send_cap,
 * MCP_DAILY_SEND_CAP). **No cap by default** (Rizal 27 Nov: "jangan ada jatah"
 * — every send is paid in Credits, so the balance is the only limit). The
 * app's cookie session is never counted. A draft held for approval is not a
 * send. The day is the UTC calendar day.
 */
class CapTokenSends
{
    /** The cap, or null for none (the default). */
    public static function cap(): ?int
    {
        $cap = config('services.mcp.daily_send_cap');

        return is_numeric($cap) && (int) $cap > 0 ? (int) $cap : null;
    }

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

        $cap = self::cap();
        if ($cap !== null && (int) Cache::increment($key) > $cap) {
            Cache::decrement($key);

            return response()->json([
                'message' => 'Connected apps can send '.$cap.' posts a day. Send this one from creatif.space, or try again tomorrow (UTC).',
                'code' => 'daily_send_cap',
                'cap' => $cap,
                'resets_at' => now('UTC')->addDay()->startOfDay()->toIso8601String(),
            ], 429, RateLimitHeaders::for($cap, (int) now('UTC')->diffInSeconds(now('UTC')->addDay()->startOfDay(), true)));
        }
        if ($cap === null) {
            Cache::increment($key);
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
     * Reserve $n of today's sends at once (a batch), atomically; false and
     * nothing reserved when they do not all fit. release() gives them back.
     */
    public static function reserve(User $user, int $n): bool
    {
        $key = self::key($user);
        Cache::add($key, 0, now('UTC')->addDay()->startOfDay()->addHour());

        $cap = self::cap();
        if ((int) Cache::increment($key, $n) > ($cap ?? PHP_INT_MAX)) {
            Cache::decrement($key, $n);

            return false;
        }

        return true;
    }

    public static function release(User $user, int $n): void
    {
        Cache::decrement(self::key($user), $n);
    }

    /**
     * cap / remaining are null when there is no cap (the default).
     *
     * @return array{cap: int|null, used: int, remaining: int|null}
     */
    public static function status(User $user): array
    {
        $used = (int) Cache::get(self::key($user), 0);
        $cap = self::cap();

        return ['cap' => $cap, 'used' => $used, 'remaining' => $cap === null ? null : max(0, $cap - $used)];
    }

    private static function key(User $user): string
    {
        return 'token-sends:'.$user->id.':'.now('UTC')->toDateString();
    }
}
