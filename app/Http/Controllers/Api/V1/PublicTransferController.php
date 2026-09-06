<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicTransferResource;
use App\Models\Transfer;
use App\Support\SpaceUnlockToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * The page a recipient opens: /t/{slug}.
 *
 * No account needed, by design — the whole point of a link. Gates run in
 * the same order the Space page uses, and for the same reason: an expired
 * locked transfer should say expired rather than ask for a password it
 * will not honour.
 */
class PublicTransferController extends Controller
{
    public function show(Request $request, string $slug): PublicTransferResource|JsonResponse
    {
        $transfer = $this->resolve($slug);

        if ($transfer->isExpired()) {
            return response()->json([
                'expired' => true,
                'sender_name' => $transfer->user->name,
            ], 410);
        }

        if (
            $transfer->password_hash !== null
            && ! SpaceUnlockToken::verifyFor($transfer->ulid, $request->query('st'))
        ) {
            /* Nothing leaks through the lock — not the title, not the file
               count, not a thumbnail. Same rule as a locked Space. */
            return response()->json([
                'locked' => true,
                'sender_name' => $transfer->user->name,
            ], 423);
        }

        /* Counted here rather than derived from an event log: the sender's
           list reads this on every render, and "4 opens" should not depend
           on an aggregation job having run. */
        $transfer->increment('opens');

        return new PublicTransferResource(
            $transfer->load(['files', 'user.handle']),
        );
    }

    public function unlock(Request $request, string $slug): JsonResponse
    {
        $transfer = $this->resolve($slug);
        $validated = $request->validate([
            'password' => ['required', 'string', 'max:255'],
        ]);

        if (
            $transfer->password_hash === null
            || ! Hash::check($validated['password'], $transfer->password_hash)
        ) {
            return response()->json([
                'message' => "That password isn't right — check with {$transfer->user->name}.",
            ], 422);
        }

        return response()->json(['data' => ['token' => SpaceUnlockToken::issueFor($transfer->ulid)]]);
    }

    /**
     * A signed, short-lived download for one file in the transfer. The same
     * two gates as the page: a link that skipped the password would be a
     * hole beside the door.
     */
    public function download(Request $request, string $slug, string $file): JsonResponse
    {
        $transfer = $this->resolve($slug);

        if ($transfer->isExpired()) {
            return response()->json(['expired' => true], 410);
        }

        if (
            $transfer->password_hash !== null
            && ! SpaceUnlockToken::verifyFor($transfer->ulid, $request->query('st'))
        ) {
            return response()->json(['locked' => true], 423);
        }

        $record = $transfer->files()->where('files.ulid', $file)->first();
        abort_if($record === null, 404);

        $transfer->increment('downloads');

        return response()->json(['data' => ['url' => $record->downloadUrl()]]);
    }

    private function resolve(string $slug): Transfer
    {
        $transfer = Transfer::query()
            ->where('slug', $slug)
            ->with('user')
            ->first();

        abort_if($transfer === null, 404);

        return $transfer;
    }
}
