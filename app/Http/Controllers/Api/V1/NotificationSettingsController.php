<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\WeeklyDigest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET / PATCH /api/v1/me/notification-settings — the three emails a user
 * can turn off, as plain switches (MCP get_notification_settings /
 * update_notification_settings):
 *
 * - publish_failed:   "Post missed / failed" — a scheduled post missed its
 *                     time (SocialPostMissed) or failed to publish
 *                     (SocialPostFailed); one email per post
 * - approval_decided: a client approved or asked for changes (SocialPostDecided)
 * - weekly_digest:    Monday's summary of the week (WeeklyDigest)
 *
 * Each is the email switch of that type's row in notification
 * preferences, so this and Settings → Notifications never disagree, and
 * the notifications read the same switch when they send. Default on. The
 * in-app bell is not changed here.
 *
 * timezone is where the weekly digest's Monday 08:00 is (IANA; null
 * resets it to the latest Content Plan's zone, else the app's).
 *
 * Not /me/notifications: that path is already the bell's list.
 */
class NotificationSettingsController extends Controller
{
    /** @var array<string, NotificationType> */
    public const SETTINGS = [
        'publish_failed' => NotificationType::SocialPostFailed,
        'approval_decided' => NotificationType::SocialPostDecision,
        'weekly_digest' => NotificationType::WeeklyDigest,
    ];

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => self::settings($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = array_fill_keys(array_keys(self::SETTINGS), ['sometimes', 'boolean']);
        $rules['timezone'] = ['sometimes', 'nullable', 'string', 'timezone:all'];
        $validated = $request->validate($rules);

        if ($validated === []) {
            return response()->json([
                'message' => 'Send at least one of: '.implode(', ', [...array_keys(self::SETTINGS), 'timezone']).'.',
                'errors' => ['settings' => ['Send at least one setting.']],
            ], 422);
        }

        $user = $request->user();
        if (array_key_exists('timezone', $validated)) {
            $user->forceFill(['timezone' => $validated['timezone']])->save();
            unset($validated['timezone']);
        }
        foreach ($validated as $name => $on) {
            $user->notificationPreferences()->updateOrCreate(
                ['type' => self::SETTINGS[$name]->value],
                ['email_enabled' => (bool) $on],
            );
        }

        return response()->json(['data' => self::settings($user->load('notificationPreferences'))]);
    }

    /** @return array<string, bool|string> */
    public static function settings(User $user): array
    {
        return [
            ...array_map(fn (NotificationType $type) => $user->wants($type, 'email'), self::SETTINGS),
            'timezone' => WeeklyDigest::timezone($user),
        ];
    }
}
