<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Activity;
use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransferResource;
use App\Models\File;
use App\Models\Transfer;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;

/**
 * Files going out, as a link — the sender's side.
 *
 * A transfer points at library rows rather than copying them: sending the
 * same shoot to three clients costs one set of bytes, and deleting a
 * transfer never removes a file.
 */
class TransferController extends Controller
{
    /** What I have sent. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = Workspace::owner($request->user());

        return TransferResource::collection(
            Transfer::query()
                ->where('user_id', $user->id)
                ->with(['files:id,size_bytes', 'recipients'])
                ->withCount('files')
                ->latest('id')
                ->limit(100)
                ->get(),
        );
    }

    /**
     * What was sent to me.
     *
     * Matched on the account's own email, which the sender typed — so this
     * is a convenience for people who happen to have an account, never a
     * permission. The link works for anyone holding it either way, which is
     * why an unverified address is safe to match on here and would not be
     * safe to gate on.
     */
    public function received(Request $request): AnonymousResourceCollection
    {
        $email = $request->user()->email;

        return TransferResource::collection(
            Transfer::query()
                ->whereHas('recipients', fn ($q) => $q->where('email', $email))
                ->with(['files:id,size_bytes', 'user:id,name'])
                ->withCount('files')
                ->latest('id')
                ->limit(100)
                ->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = Workspace::owner($request->user());
        abort_unless(Workspace::canWrite($request->user()), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'file_ids' => ['required', 'array', 'min:1', 'max:200'],
            'file_ids.*' => ['string', 'max:26'],
            'password' => ['sometimes', 'nullable', 'string', 'min:4', 'max:255'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'recipients' => ['sometimes', 'array', 'max:20'],
            'recipients.*' => ['email', 'max:255'],
        ]);

        /* Own files only, and only ones that are actually there — a
           transfer that half-resolves would give the recipient a page with
           holes in it rather than an error the sender can see. */
        $files = File::query()
            ->where('user_id', $user->id)
            ->where('status', File::STATUS_READY)
            ->whereIn('ulid', $validated['file_ids'])
            ->get();

        if ($files->count() !== count(array_unique($validated['file_ids']))) {
            return response()->json([
                'message' => 'Some of those files are not in your library any more.',
            ], 422);
        }

        $transfer = $user->transfers()->create([
            'title' => $validated['title'],
            'note' => $validated['note'] ?? null,
            // A link with no end date is a drop-box somebody forgets about.
            'expires_at' => $validated['expires_at'] ?? now()->addDays(Transfer::DEFAULT_DAYS),
        ]);

        if (($validated['password'] ?? null) !== null) {
            $transfer->forceFill(['password_hash' => Hash::make($validated['password'])])->save();
        }

        $transfer->files()->attach(
            $files->values()->mapWithKeys(
                fn (File $file, int $i) => [$file->id => ['sort_order' => $i]],
            )->all(),
        );

        foreach (array_unique($validated['recipients'] ?? []) as $email) {
            $transfer->recipients()->create(['email' => $email]);
        }

        Activity::log(
            $user,
            ActivityAction::Transfer,
            "Sent {$files->count()} ".($files->count() === 1 ? 'file' : 'files')." — {$transfer->title}",
            'transfer',
            $transfer->ulid,
        );

        return (new TransferResource(
            $transfer->fresh()->load(['files:id,size_bytes', 'recipients'])->loadCount('files'),
        ))->response()->setStatusCode(201);
    }

    /** Rename, re-date, or lock/unlock. The file list is fixed at creation. */
    public function update(Request $request, Transfer $transfer): TransferResource
    {
        $user = Workspace::owner($request->user());
        abort_unless($transfer->user_id === $user->id, 404);
        abort_unless(Workspace::canWrite($request->user()), 403);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            // Explicit null clears the password; absent leaves it alone.
            'password' => ['sometimes', 'nullable', 'string', 'min:4', 'max:255'],
        ]);

        $transfer->update(array_intersect_key($validated, array_flip(['title', 'note', 'expires_at'])));

        if (array_key_exists('password', $validated)) {
            $transfer->forceFill([
                'password_hash' => $validated['password'] === null
                    ? null
                    : Hash::make($validated['password']),
            ])->save();
        }

        return new TransferResource(
            $transfer->fresh()->load(['files:id,size_bytes', 'recipients'])->loadCount('files'),
        );
    }

    /**
     * Revoke the link. The files stay in the library — that is the whole
     * point of pointing at them rather than copying them.
     */
    public function destroy(Request $request, Transfer $transfer): JsonResponse
    {
        $user = Workspace::owner($request->user());
        abort_unless($transfer->user_id === $user->id, 404);
        abort_unless(Workspace::canWrite($request->user()), 403);

        $transfer->delete();

        return response()->json(null, 204);
    }
}
