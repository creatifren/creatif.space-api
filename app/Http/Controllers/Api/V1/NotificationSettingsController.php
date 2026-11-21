<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\NotificationChannels;
use App\Support\WeeklyDigest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET / PATCH /api/v1/me/notification-settings — prototype v10.8 (Rizal
 * 21 Nov 2026): the bell gets everything (no switches), email only what
 * needs action, push only the important ones.
 *
 * - problems_email: "Problems that need you" — a post failed, missed or
 *   held, a channel needs attention, Credits or storage running low, kept
 *   files about to go. Stored as the email switch of social.post.failed
 *   (publish_failed is the same switch, older name). Default on.
 *   Billing emails are always on and not listed.
 * - push: the master switch for push on the person's phones (default on).
 * - push_events: approved, changes, post_problem, channel, credits —
 *   each default on (users.social_settings.push_events).
 * - timezone: kept for scheduling (IANA; null resets it).
 *
 * Not /me/notifications: that path is already the bell's list.
 */
class NotificationSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => self::settings($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [
            'problems_email' => ['sometimes', 'boolean'],
            'publish_failed' => ['sometimes', 'boolean'],
            'push' => ['sometimes', 'boolean'],
            'push_events' => ['sometimes', 'array'],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
        ];
        foreach (NotificationChannels::PUSH_EVENTS as $event) {
            $rules["push_events.{$event}"] = ['sometimes', 'boolean'];
        }
        $validated = $request->validate($rules);

        if ($validated === []) {
            return response()->json([
                'message' => 'Send at least one of: problems_email, push, push_events, timezone.',
                'errors' => ['settings' => ['Send at least one setting.']],
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $problems = $validated['problems_email'] ?? $validated['publish_failed'] ?? null;
        if ($problems !== null) {
            $user->notificationPreferences()->updateOrCreate(
                ['type' => NotificationType::SocialPostFailed->value],
                ['email_enabled' => (bool) $problems],
            );
        }
        if (array_key_exists('timezone', $validated)) {
            $user->forceFill(['timezone' => $validated['timezone']]);
        }
        if (array_key_exists('push', $validated)) {
            $user->forceFill(['push_enabled' => (bool) $validated['push']]);
        }
        if (isset($validated['push_events'])) {
            $events = array_intersect_key((array) $validated['push_events'], array_flip(NotificationChannels::PUSH_EVENTS));
            $settings = $user->social_settings ?? [];
            $settings['push_events'] = array_map('boolval', [...$user->pushEvents(), ...$events]);
            $user->forceFill(['social_settings' => $settings]);
        }
        $user->save();

        return response()->json(['data' => self::settings($user->fresh()?->load('notificationPreferences') ?? $user)]);
    }

    /** @return array<string, mixed> */
    public static function settings(User $user): array
    {
        $problems = NotificationChannels::problemsEmail($user);

        return [
            'problems_email' => $problems,
            // Older name of the same switch (MCP clients before v10.8).
            'publish_failed' => $problems,
            'push' => $user->pushEnabled(),
            'push_events' => $user->pushEvents(),
            'timezone' => WeeklyDigest::timezone($user),
        ];
    }
}
