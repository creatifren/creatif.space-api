<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApprovalCancelReason;
use App\Enums\ApprovalStatus;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\DriveFile;
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
     * Home → Needs Attention. Only what is actually broken: approvals a
     * file change voided, and files we can no longer reach. Empty means
     * the strip disappears, which is the whole point of it.
     */
    public function attention(Request $request): JsonResponse
    {
        $user = $request->user();

        $voided = Approval::query()
            ->where('status', ApprovalStatus::Cancelled)
            ->where('cancelled_reason', ApprovalCancelReason::FileVersionChanged)
            ->whereHas('spaceItem.space', fn ($q) => $q->where('user_id', $user->id))
            ->with(['spaceItem.driveFile', 'spaceItem.space'])
            ->get();

        /** @var list<array<string, mixed>> $items */
        $items = $voided->groupBy(fn (Approval $a) => $a->spaceItem->drive_file_id)
            ->map(fn ($group) => [
                'kind' => 'approval_void',
                'tone' => 'clay',
                'file_name' => $group->first()->spaceItem->driveFile->name,
                'space_title' => $group->first()->spaceItem->space->title,
                'count' => $group->count(),
            ])->values()->all();

        $lost = DriveFile::query()
            ->whereNotNull('access_lost_at')
            ->whereHas('account', fn ($q) => $q->where('user_id', $user->id))
            ->count();

        if ($lost > 0) {
            $items[] = [
                'kind' => 'access_lost',
                'tone' => 'rust',
                'count' => $lost,
            ];
        }

        return response()->json(['data' => $items]);
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
