<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApprovalStatus;
use App\Enums\FileRequestStatus;
use App\Enums\NoteAuthor;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Approval;
use App\Models\File;
use App\Models\FileRequest;
use App\Models\Transfer;
use App\Models\User;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The bell, the Needs Attention strip, and the five switches behind them.
 */
class NotificationController extends Controller
{
    /**
     * How near a deadline has to be before it is worth interrupting over.
     * A month of warning is not attention, it is wallpaper.
     */
    private const SOON_DAYS = 7;

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
            'meta' => [
                'unread' => $user->unreadNotifications()->count(),
                'week' => $this->thisWeek($user),
            ],
        ]);
    }

    /**
     * The "THIS WEEK" card: how many of each thing I did in the last seven
     * days, keyed by action.
     *
     * Seven days whatever range the list is showing — the card says THIS
     * WEEK, and a card that quietly meant "this month" because a picker was
     * moved would be worse than no card.
     *
     * Counted rather than enumerated, and only over the ledger: a
     * notification is somebody else's doing and has no business in a
     * summary of mine. Actions with no writer yet simply do not appear,
     * which is the honest shape — the screen renders the keys it gets
     * rather than a fixed six.
     *
     * @return array<string, int>
     */
    private function thisWeek(User $user): array
    {
        /** @var array<string, int> $counts */
        $counts = Activity::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('action, COUNT(*) as total')
            ->groupBy('action')
            ->pluck('total', 'action')
            ->map(fn ($total) => (int) $total)
            ->all();

        return $counts;
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
     * Home → Needs Attention, and the bell's NEEDS YOU popover.
     *
     * Every row here is something waiting on *me*, which is what separates
     * it from both other lists: a notification is news, a ledger line is
     * history, and this is a to-do. So each row has to name an action I can
     * still take — nothing is listed once it is too late to act.
     *
     * The old alerts (version-voided approvals, lost Drive access) really
     * did stop being possible when files moved to our storage. These four
     * replace them, and all four come out of data that already exists.
     */
    public function attention(Request $request): JsonResponse
    {
        $user = Workspace::owner($request->user());
        $rows = [];

        /* A client asked for a revision and nobody has answered. The same
           condition reply() guards on: a Revision approval whose notes
           carry no Owner reply. This is the one row where somebody is
           actually waiting on a person rather than a date. */
        $unanswered = Approval::query()
            ->where('status', ApprovalStatus::Revision)
            ->whereHas('spaceItem.space', fn ($q) => $q->where('user_id', $user->id))
            ->whereDoesntHave('notes', fn ($q) => $q->where('author_type', NoteAuthor::Owner))
            ->whereHas('notes')
            ->with(['spaceItem.space:id,ulid,title,slug', 'client:id,name'])
            ->latest('updated_at')
            ->limit(10)
            ->get();

        foreach ($unanswered as $approval) {
            $space = $approval->spaceItem->space;
            $rows[] = [
                'id' => 'ap'.$approval->ulid,
                'kind' => 'approval.unanswered',
                'title' => $approval->client?->name === null
                    ? 'A revision was asked for'
                    : "{$approval->client->name} asked for a revision",
                'detail' => $space->title,
                'subject' => ['type' => 'space', 'id' => $space->ulid],
                'when' => $approval->updated_at,
            ];
        }

        /* Links about to close with nothing collected yet. An empty request
           expiring is a thing somebody meant to chase; one that already
           has files is finished, whatever the date says. */
        $expiring = FileRequest::query()
            ->where('user_id', $user->id)
            ->where('status', FileRequestStatus::Open)
            ->whereBetween('expires_at', [now(), now()->addDays(self::SOON_DAYS)])
            ->whereDoesntHave('submissions')
            ->orderBy('expires_at')
            ->limit(10)
            ->get();

        foreach ($expiring as $fileRequest) {
            $rows[] = [
                'id' => 'fr'.$fileRequest->ulid,
                'kind' => 'request.expiring',
                'title' => "Nothing has arrived for “{$fileRequest->title}”",
                'detail' => 'The link closes '.$fileRequest->expires_at->diffForHumans(),
                'subject' => ['type' => 'file_request', 'id' => $fileRequest->ulid],
                'when' => $fileRequest->expires_at,
            ];
        }

        /* A transfer expiring that nobody ever opened. `opens` is counted on
           the page itself, so this needs no aggregation job to be true. */
        $unopened = Transfer::query()
            ->where('user_id', $user->id)
            ->where('opens', 0)
            ->whereBetween('expires_at', [now(), now()->addDays(self::SOON_DAYS)])
            ->orderBy('expires_at')
            ->limit(10)
            ->get();

        foreach ($unopened as $transfer) {
            $rows[] = [
                'id' => 'tr'.$transfer->ulid,
                'kind' => 'transfer.unopened',
                'title' => "“{$transfer->title}” has not been opened",
                'detail' => 'The link expires '.$transfer->expires_at->diffForHumans(),
                'subject' => ['type' => 'transfer', 'id' => $transfer->ulid],
                'when' => $transfer->expires_at,
            ];
        }

        /* The Trash empties itself. One row for the lot rather than one per
           file: the action is the same for all of them, and thirty rows
           saying "restore me" would bury the other three kinds. */
        $purging = File::onlyTrashed()
            ->where('user_id', $user->id)
            ->whereBetween('purge_at', [now(), now()->addDays(self::SOON_DAYS)])
            ->orderBy('purge_at')
            ->get();

        if ($purging->isNotEmpty()) {
            $soonest = $purging->first();
            $rows[] = [
                'id' => 'tp'.$soonest->ulid,
                'kind' => 'trash.purging',
                'title' => $purging->count() === 1
                    ? "{$soonest->name} is about to be deleted for good"
                    : $purging->count().' files are about to be deleted for good',
                'detail' => 'Restore them before '.$soonest->purge_at->isoFormat('D MMMM'),
                'subject' => null,
                'when' => $soonest->purge_at,
            ];
        }

        // Soonest first: this is a list of deadlines, so the nearest one is
        // the one worth reading.
        usort($rows, fn (array $a, array $b) => $a['when'] <=> $b['when']);

        return response()->json(['data' => array_slice($rows, 0, 12)]);
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
