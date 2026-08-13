<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApprovalStatus;
use App\Enums\NoteAuthor;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApprovalResource;
use App\Models\Approval;
use App\Models\Client;
use App\Models\Handle;
use App\Models\Space;
use App\Models\SpaceItem;
use App\Notifications\ApprovalDecided;
use App\Support\SpaceUnlockToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the client does in someone else's Space. Signed in on the `client`
 * guard — a separate identity from the creator who owns the Space.
 */
class ApprovalController extends Controller
{
    /**
     * One decision on one file. Approving snapshots the file's version so
     * a later change can tell that this sign-off is no longer about the
     * bytes that are there now.
     */
    public function store(Request $request, string $handle, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'item_id' => ['required', 'string', 'max:26'],
            'decision' => ['required', 'string', 'in:approve,revise'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'chips' => ['sometimes', 'array', 'max:10'],
            'chips.*' => ['string', 'max:60'],
        ]);

        $space = $this->resolve($request, $handle, $slug);
        $client = $request->user('client');

        // A revision with no note is the one thing we refuse: the owner
        // would have nothing to act on.
        if ($validated['decision'] === 'revise' && trim((string) ($validated['note'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'note' => 'Name what needs changing so they don’t have to guess.',
            ]);
        }

        $item = $space->items->firstWhere('ulid', $validated['item_id']);

        if ($item === null) {
            throw ValidationException::withMessages([
                'item_id' => 'That file is not in this Space.',
            ]);
        }

        $approval = DB::transaction(
            fn () => $this->decide($item, $client, $validated),
        );

        $approval->load(['client', 'notes', 'spaceItem.driveFile']);
        $space->user->notify(new ApprovalDecided($approval, $space));

        return (new ApprovalResource($approval))->response()->setStatusCode(201);
    }

    /**
     * The whole-Space gesture: "Approve All", and both halves of Per Space
     * mode — where this is the only control the client ever sees.
     */
    public function storeAll(Request $request, string $handle, string $slug): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'decision' => ['sometimes', 'string', 'in:approve,revise'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'chips' => ['sometimes', 'array', 'max:10'],
            'chips.*' => ['string', 'max:60'],
        ]);

        $decision = $validated['decision'] ?? 'approve';

        // Same rule as per-file: a revision with no note gives the owner
        // nothing to act on.
        if ($decision === 'revise' && trim((string) ($validated['note'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'note' => 'Name what needs changing so they don’t have to guess.',
            ]);
        }

        $space = $this->resolve($request, $handle, $slug);
        $client = $request->user('client');

        $approvals = DB::transaction(function () use ($space, $client, $decision, $validated) {
            $made = [];
            // The note lands once, on the first file it touches — a Space-wide
            // sentence copied onto 24 rows would read as 24 notes in Insights.
            $noteSpent = false;

            foreach ($space->items as $item) {
                // A file we can no longer reach can't be signed off, and a
                // decision already given is not overwritten by a bulk press.
                if ($item->driveFile->access_lost_at !== null) {
                    continue;
                }

                $existing = Approval::query()
                    ->where('space_item_id', $item->id)
                    ->where('client_id', $client->id)
                    ->first();

                if ($existing !== null && $existing->status !== ApprovalStatus::Pending) {
                    continue;
                }

                $made[] = $this->decide($item, $client, [
                    'decision' => $decision,
                    'note' => $noteSpent ? null : ($validated['note'] ?? null),
                    'chips' => $noteSpent ? null : ($validated['chips'] ?? null),
                ]);
                $noteSpent = true;
            }

            return $made;
        });

        // One gesture, one email — never a message per file.
        if ($approvals !== []) {
            $first = $approvals[0]->load(['client', 'notes', 'spaceItem.driveFile']);
            $space->user->notify(new ApprovalDecided($first, $space, count($approvals)));

            foreach (array_slice($approvals, 1) as $approval) {
                $approval->load(['client', 'notes', 'spaceItem.driveFile']);
            }
        }

        return ApprovalResource::collection($approvals);
    }

    /**
     * Record one decision, creating the row the first time and moving it
     * afterwards. Unique per (item, client) — a person has one opinion on
     * a file at a time, and changing it replaces the old one.
     *
     * @param  array<string, mixed>  $input
     */
    private function decide(SpaceItem $item, Client $client, array $input): Approval
    {
        $approve = $input['decision'] === 'approve';

        $approval = Approval::query()->firstOrCreate(
            ['space_item_id' => $item->id, 'client_id' => $client->id],
        );

        $approval->forceFill([
            'status' => $approve ? ApprovalStatus::Approved : ApprovalStatus::Revision,
            'approved_at' => $approve ? now() : null,
            'cancelled_reason' => null,
            // The snapshot the auto-cancel compares against.
            'version_hash_at_approval' => $approve ? $item->driveFile->version_hash : null,
        ])->save();

        $note = trim((string) ($input['note'] ?? ''));
        if ($note !== '') {
            $approval->notes()->create([
                'author_type' => NoteAuthor::Client,
                'body' => $note,
                'chips' => $input['chips'] ?? null,
            ]);
        }

        return $approval;
    }

    /**
     * The same gate order the public viewer uses — 404 → 410 → 423 — plus
     * the one rule that belongs to this endpoint: a Space that never asked
     * for an approval does not take one.
     */
    private function resolve(Request $request, string $handle, string $slug): Space
    {
        $record = Handle::query()
            ->where('name', Handle::normalize($handle))
            ->whereNotNull('user_id')
            ->with('user')
            ->firstOrFail();

        abort_if($record->user === null, 404);
        abort_if($record->user->status !== UserStatus::Active, 404);

        $space = Space::query()
            ->where('user_id', $record->user->id)
            ->where('slug', $slug)
            ->where('status', 'published')
            ->with(['items.driveFile', 'user'])
            ->first();

        abort_if($space === null, 404);

        if ($space->isExpired()) {
            abort(410);
        }

        if (
            $space->password_hash !== null
            && ! SpaceUnlockToken::verify($space, $request->input('st', $request->query('st')))
        ) {
            abort(423);
        }

        if (! $space->approval_enabled) {
            throw ValidationException::withMessages([
                'space' => 'This Space isn’t asking for an approval.',
            ]);
        }

        return $space;
    }
}
