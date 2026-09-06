<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\Activity;
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
     * The Activity screen: notifications and my own actions, interleaved.
     *
     * Two sources on purpose. A notification is somebody interrupting me
     * and can be marked read; a ledger line is something I did and cannot —
     * "you published Winter Noel" is not a message, it is a fact, and a
     * tick box on it would mean nothing. They share a shape here so the
     * screen renders one list, and each row says which kind it is.
     */
    public function activity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ]);

        $user = $request->user();
        $since = now()->subDays($validated['days'] ?? 30);

        $notifications = $user->notifications()
            ->where('created_at', '>=', $since)
            ->limit(100)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'kind' => 'notification',
                'type' => $n->data['type'] ?? null,
                'summary' => $n->data['line'] ?? $n->data['title'] ?? null,
                'data' => $n->data,
                'read_at' => $n->read_at,
                'created_at' => $n->created_at,
            ]);

        $actions = Activity::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $since)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Activity $a) => [
                'id' => 'a'.$a->id,
                'kind' => 'action',
                'type' => $a->action->value,
                'tool' => $a->action->tool(),
                'summary' => $a->summary,
                'subject' => $a->subject_type === null
                    ? null
                    : ['type' => $a->subject_type, 'id' => $a->subject_id],
                /* Never unread: a thing I did was never news to me. The
                   screen's "mark all read" must not appear to leave rows
                   behind, so these carry a read timestamp of their own. */
                'read_at' => $a->created_at,
                'created_at' => $a->created_at,
            ]);

        $rows = $notifications->concat($actions)
            ->sortByDesc('created_at')
            ->values()
            ->take(100);

        return response()->json([
            'data' => $rows,
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
