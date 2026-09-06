<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Jobs\BuildSpaceArchive;
use App\Models\SpaceArchive;
use App\Http\Resources\PublicSpaceResource;
use App\Models\Handle;
use App\Models\Space;
use App\Support\SpaceUnlockToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * creatif.space/{handle}/{slug} — what a client opens. No auth.
 *
 * Order of gates: existence (404) → expiry (410) → password (423).
 * An expired locked Space says expired, not locked. Private-but-published
 * is 200: private means link-only, not blocked.
 */
class PublicSpaceController extends Controller
{
    public function show(Request $request, string $handle, string $slug): PublicSpaceResource|JsonResponse
    {
        $space = $this->resolve($handle, $slug);

        if ($space->isExpired()) {
            return response()->json([
                'expired' => true,
                'owner_name' => $space->user->name,
            ], 410);
        }

        if (
            $space->password_hash !== null
            && ! SpaceUnlockToken::verify($space, $request->query('st'))
        ) {
            // Nothing about the Space leaks through the lock — not the
            // title, not a thumbnail, not the file count.
            return response()->json([
                'locked' => true,
                'owner_name' => $space->user->name,
            ], 423);
        }

        return new PublicSpaceResource($space);
    }

    /**
     * A download link for one file in a published Space.
     *
     * The same three gates as the page, in the same order — a link that
     * skipped the password would be a hole beside the door. `allow_download`
     * is checked here rather than only drawn in the UI: until now it was a
     * toggle the server never read, so turning it off hid a button and
     * withheld nothing.
     *
     * What this can and cannot do: with the Space open, its images are on
     * the page and a viewer can always save one from the browser. What the
     * gate withholds is the original — full resolution, original filename,
     * and every file that is not an image. That is the difference the
     * toggle actually describes.
     */
    public function download(Request $request, string $handle, string $slug, string $item): JsonResponse
    {
        $space = $this->resolve($handle, $slug);

        if ($space->isExpired()) {
            return response()->json(['expired' => true], 410);
        }

        if (
            $space->password_hash !== null
            && ! SpaceUnlockToken::verify($space, $request->query('st'))
        ) {
            return response()->json(['locked' => true], 423);
        }

        if (($space->settings['allow_download'] ?? true) !== true) {
            return response()->json([
                'message' => 'Downloads are off for this Space.',
            ], 403);
        }

        $record = $space->items()->where('ulid', $item)->with('file')->first();
        abort_if($record?->file === null, 404);

        return response()->json(['data' => ['url' => $record->file->downloadUrl()]]);
    }

    /**
     * "Download all": ask for the Space's zip, and find out where it is.
     *
     * One route for both, because they are the same question asked twice —
     * the first press starts a build, every press after polls it. A request
     * that waited for the zip would time out long before a 500-photo Space
     * finished.
     *
     * Keyed by the Space's content, not its id: publish another photo and
     * the signature changes, so the next visitor gets a fresh build instead
     * of yesterday's archive quietly missing a file.
     */
    public function archive(Request $request, string $handle, string $slug): JsonResponse
    {
        $space = $this->resolve($handle, $slug);

        if ($space->isExpired()) {
            return response()->json(['expired' => true], 410);
        }

        if (
            $space->password_hash !== null
            && ! SpaceUnlockToken::verify($space, $request->query('st'))
        ) {
            return response()->json(['locked' => true], 423);
        }

        if (($space->settings['allow_download'] ?? true) !== true) {
            return response()->json([
                'message' => 'Downloads are off for this Space.',
            ], 403);
        }

        $signature = SpaceArchive::signatureFor($space);

        $archive = SpaceArchive::query()
            ->where('space_id', $space->id)
            ->where('signature', $signature)
            /* A failed build is not reused: whoever presses next should get
               another attempt, not yesterday's error. */
            ->whereIn('status', [SpaceArchive::STATUS_PENDING, SpaceArchive::STATUS_READY])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->first();

        if ($archive === null) {
            $archive = SpaceArchive::create([
                'space_id' => $space->id,
                'signature' => $signature,
                'status' => SpaceArchive::STATUS_PENDING,
            ]);

            BuildSpaceArchive::dispatch($archive);
        }

        return response()->json(['data' => [
            'status' => $archive->status,
            'url' => $archive->status === SpaceArchive::STATUS_READY ? $archive->url() : null,
            'size_bytes' => $archive->size_bytes,
            'reason' => $archive->failure_reason,
        ]]);
    }

    public function unlock(Request $request, string $handle, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'max:255'],
        ]);

        $space = $this->resolve($handle, $slug);

        if ($space->isExpired()) {
            return response()->json(['expired' => true], 410);
        }

        if (
            $space->password_hash === null
            || ! Hash::check($validated['password'], $space->password_hash)
        ) {
            throw ValidationException::withMessages([
                'password' => "That password isn't right — check with {$space->user->name}.",
            ]);
        }

        return response()->json([
            'token' => SpaceUnlockToken::issue($space),
            'data' => (new PublicSpaceResource($space))->toArray($request),
        ]);
    }

    private function resolve(string $handle, string $slug): Space
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
            ->with(['items.file', 'user.profile'])
            ->first();

        abort_if($space === null, 404);

        return $space;
    }
}
