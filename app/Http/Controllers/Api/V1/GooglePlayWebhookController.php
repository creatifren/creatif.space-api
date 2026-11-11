<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\GooglePlayUnavailable;
use App\Exceptions\InvalidGoogleIdToken;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Support\GooglePlayBilling;
use App\Support\GooglePushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * POST /webhooks/google-play — Google Play real-time developer
 * notifications, pushed by Pub/Sub.
 *
 * The bearer is Pub/Sub's Google-signed OIDC token (GooglePushToken); a bad
 * one is a 401. After that every answer is 2xx so Pub/Sub never retries a
 * message forever: the notification is only a nudge — the subscription is
 * re-read from Google and run through GooglePlayBilling's mapping.
 * Deduplicated by Pub/Sub messageId for 7 days.
 */
class GooglePlayWebhookController extends Controller
{
    /** subscriptionNotification.notificationType: SUBSCRIPTION_REVOKED (refunded). */
    private const REVOKED = 12;

    private const SEEN_KEY = 'google-play:rtdn:';

    public function __invoke(Request $request, GooglePushToken $push, GooglePlayBilling $billing): JsonResponse
    {
        $jwt = (string) $request->bearerToken();
        try {
            $push->verify($jwt);
        } catch (InvalidGoogleIdToken $e) {
            Log::warning('google_play.rtdn_unauthorized', ['why' => $e->getMessage()]);

            return response()->json(['message' => 'Unauthorized.'], 401);
        } catch (RuntimeException $e) {
            // Google's keys could not be fetched: unproven, so not accepted;
            // Pub/Sub retries it.
            Log::warning('google_play.rtdn_keys_unavailable', ['why' => $e->getMessage()]);

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $messageId = $request->input('message.messageId', $request->input('message.message_id'));
        $data = json_decode((string) base64_decode((string) $request->input('message.data', ''), true), true);
        if (! is_string($messageId) || $messageId === '' || ! is_array($data)) {
            Log::warning('google_play.rtdn_malformed');

            return $this->ok('ignored');
        }

        $seen = self::SEEN_KEY.hash('sha256', $messageId);
        if (Cache::has($seen)) {
            return $this->ok('duplicate');
        }

        if (($data['packageName'] ?? null) !== config('services.google_play.package_name')) {
            Log::warning('google_play.rtdn_wrong_package');

            return $this->ok('ignored');
        }

        try {
            $outcome = $this->handle($data, $billing);
        } catch (GooglePlayUnavailable $e) {
            // Not marked seen: a redelivery may get through. Still 2xx — the
            // expiry job and the next notification cover what this missed.
            Log::warning('google_play.rtdn_unprocessed', ['message' => $messageId, 'reason' => $e->getMessage()]);

            return $this->ok('deferred');
        }

        Cache::put($seen, true, now()->addDays(7));
        Log::info('google_play.rtdn', ['message' => $messageId, 'outcome' => $outcome]);

        return $this->ok($outcome);
    }

    /** @param  array<string, mixed>  $data */
    private function handle(array $data, GooglePlayBilling $billing): string
    {
        if (isset($data['testNotification'])) {
            return 'test';
        }

        if (is_array($data['voidedPurchaseNotification'] ?? null)) {
            $token = $data['voidedPurchaseNotification']['purchaseToken'] ?? null;
            $row = is_string($token) && $token !== ''
                ? Subscription::query()->where('google_purchase_token_hash', GooglePlayBilling::hash($token))->first()
                : null;
            if ($row === null) {
                return 'unknown_token';
            }
            $billing->expire($row);

            return 'voided';
        }

        $notification = $data['subscriptionNotification'] ?? null;
        $token = is_array($notification) ? ($notification['purchaseToken'] ?? null) : null;
        if (! is_string($token) || $token === '') {
            return 'ignored';
        }

        return $billing->sync($token, (int) ($notification['notificationType'] ?? 0) === self::REVOKED);
    }

    private function ok(string $outcome): JsonResponse
    {
        return response()->json(['message' => 'ok', 'outcome' => $outcome]);
    }
}
