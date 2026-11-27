<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts sends a day per user from a bearer token (MCP clients and API
 * keys), for our own numbers only — never a limit (Rizal 27 Nov: MCP is
 * unlimited like the app; Credits are what a send is checked against). A
 * draft held for approval is not a send. The day is the UTC calendar day.
 */
class CapTokenSends
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null || $request->user() === null) {
            return $next($request);
        }

        // A held post costs nothing and is not a send.
        if ($request->filled('approval')) {
            return $next($request);
        }

        $key = self::key($request->user());
        Cache::add($key, 0, now('UTC')->addDay()->startOfDay()->addHour());
        Cache::increment($key);

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

    /** Count $n sends at once (a batch); release() takes them back. */
    public static function reserve(User $user, int $n): bool
    {
        $key = self::key($user);
        Cache::add($key, 0, now('UTC')->addDay()->startOfDay()->addHour());
        Cache::increment($key, $n);

        return true;
    }

    public static function release(User $user, int $n): void
    {
        Cache::decrement(self::key($user), $n);
    }

    /** @return array{used: int} today's bearer sends (internal numbers). */
    public static function status(User $user): array
    {
        return ['used' => (int) Cache::get(self::key($user), 0)];
    }

    private static function key(User $user): string
    {
        return 'token-sends:'.$user->id.':'.now('UTC')->toDateString();
    }
}
