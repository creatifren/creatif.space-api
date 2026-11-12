<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key on the POSTs that spend or share: send (publish /
 * schedule), retry, and approval-link create. A request with the header
 * runs once per user, route and key for 24 hours; repeating it replays the
 * first response (same status and body, plus Idempotent-Replayed: true)
 * without doing anything again: no second send, charge or link.
 *
 * - Same key, different request (another post, another body): 422
 *   idempotency_key_reused — a key names one request.
 * - Same key while the first is still running: 409
 *   idempotency_in_progress — try again in a moment and get the replay.
 * - Only a 2xx is kept. A refusal (validation, Credits, preview_stale) is
 *   not, so the same key can be sent again once the cause is fixed.
 * - No header: nothing changes.
 *
 * Runs before CapTokenSends, so a replay does not count as a send.
 */
class IdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    public const TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);
        if ($key === null || $request->user() === null) {
            return $next($request);
        }

        if (! is_string($key) || preg_match('/^[\x21-\x7E]{1,255}$/', $key) !== 1) {
            return response()->json([
                'message' => 'Idempotency-Key must be 1–255 visible ASCII characters (a UUID is ideal).',
                'code' => 'invalid_idempotency_key',
            ], 422);
        }

        $slot = 'idem:'.$request->user()->getAuthIdentifier().':'.($request->route()?->getName() ?? $request->path()).':'.hash('sha256', $key);
        $fingerprint = hash('sha256', $request->method().' '.$request->path().' '.self::canonical($request->all()));

        $replay = $this->replay($slot, $fingerprint);
        if ($replay !== null) {
            return $replay;
        }

        $lock = Cache::lock($slot.':lock', 60);
        if (! $lock->get()) {
            return response()->json([
                'message' => 'A request with this Idempotency-Key is still running. Try again in a moment to get its result.',
                'code' => 'idempotency_in_progress',
            ], 409)->header('Retry-After', '2');
        }

        try {
            // It may have finished between the first look and the lock.
            $replay = $this->replay($slot, $fingerprint);
            if ($replay !== null) {
                return $replay;
            }

            $response = $next($request);

            if ($response->isSuccessful()) {
                Cache::put($slot, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'body' => (string) $response->getContent(),
                    'content_type' => (string) $response->headers->get('Content-Type', 'application/json'),
                ], now()->addHours(self::TTL_HOURS));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function replay(string $slot, string $fingerprint): ?Response
    {
        $hit = Cache::get($slot);
        if (! is_array($hit)) {
            return null;
        }

        if (($hit['fingerprint'] ?? null) !== $fingerprint) {
            return response()->json([
                'message' => 'This Idempotency-Key was already used for a different request. Use a new key for a new request.',
                'code' => 'idempotency_key_reused',
            ], 422);
        }

        return response((string) $hit['body'], (int) $hit['status'])
            ->header('Content-Type', (string) $hit['content_type'])
            ->header('Idempotent-Replayed', 'true');
    }

    /** The body with keys sorted at every level: key order is not a different request. */
    private static function canonical(mixed $value): string
    {
        $sort = function (mixed $v) use (&$sort): mixed {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };

        return (string) json_encode($sort($value));
    }
}
