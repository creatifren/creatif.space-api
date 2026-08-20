<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The bell, the Needs Attention strip, and the five switches behind them.
 */
class NotificationController extends Controller
{
    /**
     * The bell list — polled, per the Fase 4 decision (no websockets yet).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $user->notifications()->limit(30)->get()
                ->map(fn ($notification) => [
                    'id' => $notification->id,
                    'data' => $notification->data,
                    'read_at' => $notification->read_at,
                    'created_at' => $notification->created_at,
                ]),
            'meta' => ['unread' => $user->unreadNotifications()->count()],
        ]);
    }

    /**
     * Mark the given ids read, or all of them when none are named.
     */
    public function read(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['sometimes', 'array', 'max:100'],
            'ids.*' => ['string', 'max:36'],
        ]);

        $query = $request->user()->unreadNotifications();

        if (isset($validated['ids'])) {
            $query->whereIn('id', $validated['ids']);
        }

        $query->update(['read_at' => now()]);

        return response()->json(null, 204);
    }

    /**
     * Home → Needs Attention. Files are hosted on our storage now, so the
     * old alerts (version-voided approvals, lost Drive access) can no
     * longer happen. The endpoint stays for the Home strip; today it has
     * nothing to report.
     */
    public function attention(Request $request): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    /**
     * The five switches. Types with no stored row report as on, which is
     * what the absence of a row means.
     */
    public function preferences(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = [];
        foreach (NotificationType::switchable() as $type) {
            $data[] = [
                'type' => $type->value,
                // Same reader the notifications themselves use, so the screen
                // can never disagree with what actually gets sent.
                'email_enabled' => $user->wants($type, 'email'),
                'bell_enabled' => $user->wants($type, 'bell'),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Flip one switch. Writes the row the first time it is touched.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        // Only the switchable five: a billing reminder is not opt-out.
        $types = array_column(NotificationType::switchable(), 'value');

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', $types)],
            'email_enabled' => ['sometimes', 'boolean'],
            'bell_enabled' => ['sometimes', 'boolean'],
        ]);

        $request->user()->notificationPreferences()->updateOrCreate(
            ['type' => $validated['type']],
            array_intersect_key($validated, array_flip(['email_enabled', 'bell_enabled'])),
        );

        return response()->json(null, 204);
    }
}
