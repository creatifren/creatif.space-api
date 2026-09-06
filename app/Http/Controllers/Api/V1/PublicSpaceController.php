<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
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
