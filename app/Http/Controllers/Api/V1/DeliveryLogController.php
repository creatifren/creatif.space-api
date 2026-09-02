<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\Space;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Insights → Approval → Delivery: proof of what was sent, opened, taken and
 * signed off, for every Space that was ever published.
 *
 * The screen's own promise sets the rules: "this record only grows; it is
 * never edited or deleted. Spaces you have deleted still live here, forever,
 * on every plan." Three consequences, each visible in the query below.
 *
 *   1. `withTrashed()`. A deleted Space keeps its row (soft deletes), so the
 *      record survives the thing it describes. That is the whole point — the
 *      log is what a creator shows when a client says the work never arrived,
 *      and by then the Space may be long gone.
 *   2. Milestones come from columns on `spaces`, not from `space_events`.
 *      PruneSpaceEvents drops raw events at 90 days, so a log built on them
 *      would quietly forget everything older than a quarter.
 *   3. No plan gate. Analytics is gated on `full_analytics`; this is not.
 *      "On every plan" is the sentence, and evidence that you delivered is
 *      not an upsell.
 *
 * Published Spaces only: a draft was never sent to anyone, so it has no
 * delivery to attest to.
 */
class DeliveryLogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $owner = Workspace::owner($request->user());

        $spaces = Space::query()
            ->withTrashed()
            ->where('user_id', $owner->id)
            ->whereNotNull('published_at')
            ->with(['items.approvals.client'])
            ->withCount('items')
            ->orderByDesc('published_at')
            ->get();

        return response()->json([
            'data' => $spaces->map(fn (Space $space) => [
                'id' => $space->ulid,
                'title' => $space->title,
                'slug' => $space->slug,
                'files' => $space->items_count,
                'deleted' => $space->trashed(),
                'published_at' => $space->published_at?->toIso8601String(),
                'first_opened_at' => $space->first_opened_at?->toIso8601String(),
                'first_downloaded_at' => $space->first_downloaded_at?->toIso8601String(),
                'downloaded_files' => $space->downloaded_files,
                'deleted_at' => $space->deleted_at?->toIso8601String(),
                'marks' => $this->marks($space),
            ])->all(),
        ]);
    }

    /**
     * Who signed off, and whether Google vouched for them.
     *
     * One mark per client rather than per file: a client who approved
     * twenty-four photos delivered one verdict, and twenty-four identical
     * rows would bury it. `verified` is true for every mark here — approving
     * requires signing in with Google, which is exactly what makes this
     * evidence rather than a note the creator typed. The flag stays in the
     * payload because the card renders an unverified variant for the
     * owner-marked case, which arrives the day that feature does.
     *
     * @return list<array<string, mixed>>
     */
    private function marks(Space $space): array
    {
        return $space->items
            ->flatMap->approvals
            ->where('status', ApprovalStatus::Approved)
            ->groupBy('client_id')
            ->map(function ($approvals) {
                /** @var Approval $first */
                $first = $approvals->first();

                return [
                    'verified' => true,
                    'client' => $first->client->displayName(),
                    'email' => $first->client->email,
                    'files' => $approvals->count(),
                    // The last one they signed: the moment the sign-off was
                    // complete, which is the date worth attesting to.
                    'at' => $approvals->max('approved_at')?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }
}
