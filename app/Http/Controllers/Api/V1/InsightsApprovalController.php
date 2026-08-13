<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApprovalStatus;
use App\Enums\NoteAuthor;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\Space;
use App\Support\ApprovalVoider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Insights → Approval. Two halves of the same relationship: what I sent
 * out for review, and what I decided in someone else's Space.
 */
class InsightsApprovalController extends Controller
{
    /**
     * scope=sent → "My Request": my Spaces, counted by status.
     * scope=given → "My Approve": decisions I gave as a client elsewhere.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['sometimes', 'string', 'in:sent,given'],
        ]);

        return response()->json([
            'data' => ($validated['scope'] ?? 'sent') === 'given'
                ? $this->given($request)
                : $this->sent($request),
        ]);
    }

    /**
     * The Spaces I sent out, each with the tally the card shows.
     *
     * @return list<array<string, mixed>>
     */
    private function sent(Request $request): array
    {
        $spaces = $request->user()->spaces()
            ->where('approval_enabled', true)
            ->with(['items.driveFile', 'items.approvals.client'])
            ->latest('updated_at')
            ->get();

        return $spaces->map(function (Space $space) {
            $approvals = $space->items->flatMap->approvals;

            return [
                'id' => $space->ulid,
                'title' => $space->title,
                'slug' => $space->slug,
                'status' => $space->status,
                'total_files' => $space->items->count(),
                'approved' => $approvals->where('status', ApprovalStatus::Approved)->count(),
                'revision' => $approvals->where('status', ApprovalStatus::Revision)->count(),
                'cancelled' => $approvals->where('status', ApprovalStatus::Cancelled)->count(),
                'reviewers' => $approvals->pluck('client')->unique('id')->values()
                    ->map(fn ($client) => [
                        'name' => $client->displayName(),
                        'email' => $client->email,
                        'avatar_url' => $client->avatar_url,
                    ]),
                'last_activity' => $approvals->max('updated_at'),
                'updated_at' => $space->updated_at,
            ];
        })->values()->all();
    }

    /**
     * Decisions this person gave while signed in as a client. The bridge is
     * clients.user_id, set at login when the addresses match.
     *
     * @return list<array<string, mixed>>
     */
    private function given(Request $request): array
    {
        $clientIds = $request->user()->clientIdentities()->pluck('id');

        $approvals = Approval::query()
            ->whereIn('client_id', $clientIds)
            ->whereIn('status', [ApprovalStatus::Approved, ApprovalStatus::Revision])
            ->with(['spaceItem.space.user.handle', 'spaceItem.driveFile', 'notes'])
            ->latest('updated_at')
            ->get();

        return $approvals->groupBy(fn (Approval $a) => $a->spaceItem->space_id)
            ->map(function ($group) {
                $space = $group->first()->spaceItem->space;
                $note = $group->flatMap->notes->firstWhere('author_type', NoteAuthor::Client);

                return [
                    'space_id' => $space->ulid,
                    'title' => $space->title,
                    'owner' => $space->user->name,
                    'owner_handle' => $space->user->handle?->name,
                    'slug' => $space->slug,
                    'files' => $group->count(),
                    'approved' => $group->where('status', ApprovalStatus::Approved)->count(),
                    'revision' => $group->where('status', ApprovalStatus::Revision)->count(),
                    'note' => $note?->body,
                    'when' => $group->max('updated_at'),
                ];
            })->values()->all();
    }

    /**
     * The owner's single reply to a client's note. A second one is refused:
     * a note is a hand-off, not a thread.
     */
    public function reply(Request $request, Approval $approval): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $approval->load(['spaceItem.space', 'notes']);
        abort_unless($approval->spaceItem->space->user_id === $request->user()->id, 404);

        if ($approval->notes->contains('author_type', NoteAuthor::Owner)) {
            throw ValidationException::withMessages([
                'body' => 'You’ve already replied to this note.',
            ]);
        }

        $approval->notes()->create([
            'author_type' => NoteAuthor::Owner,
            'body' => $validated['body'],
        ]);

        return response()->json(null, 204);
    }

    /**
     * "Reset to Pending" — every decision on the Space starts over.
     */
    public function reset(Request $request, Space $space): JsonResponse
    {
        abort_unless($space->user_id === $request->user()->id, 404);

        return response()->json([
            'data' => ['reset' => ApprovalVoider::resetSpace($space)],
        ]);
    }
}
