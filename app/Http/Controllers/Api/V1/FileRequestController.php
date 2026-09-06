<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Activity;
use App\Enums\ActivityAction;
use App\Enums\FileRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AskedRequestResource;
use App\Http\Resources\FileRequestResource;
use App\Models\FileRequestSubmission;
use App\Models\FileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;

/**
 * File Request, the owner's side: make a link, watch what arrives, close it.
 *
 * The link itself is answered by PublicFileRequestController — a stranger
 * and an owner see different things, and keeping them apart is what stops
 * the target folder leaking into the public payload.
 */
class FileRequestController extends Controller
{
    /**
     * Shared by store() and update() so the two can never drift into
     * accepting different limits for the same link.
     *
     * `password` is `sometimes`, and an explicit null clears it — the same
     * three-state convention a Transfer uses, and the only one that lets a
     * PATCH of the title leave the lock alone.
     *
     * @var array<string, list<string>>
     */
    private const LIMIT_RULES = [
        'max_files' => ['sometimes', 'integer', 'min:1', 'max:'.FileRequest::MAX_FILES_CEILING],
        'max_mb' => ['sometimes', 'integer', 'min:1', 'max:'.FileRequest::MAX_MB_CEILING],
        'password' => ['sometimes', 'nullable', 'string', 'min:4', 'max:255'],
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        return FileRequestResource::collection(
            $request->user()->fileRequests()
                ->with('submissions')
                ->withCount('submissions')
                ->latest('id')
                ->limit(50)
                ->get(),
        );
    }

    /**
     * "Asked of you": requests I have sent files to.
     *
     * Only submissions made while signed in appear — that is the one case
     * where authorship is a fact rather than a claim. Somebody who dropped
     * files without an account still sent them; they just have no history
     * here, which is the honest outcome of a link that needs no account.
     */
    public function asked(Request $request): AnonymousResourceCollection
    {
        $submissions = FileRequestSubmission::query()
            ->where('user_id', $request->user()->id)
            ->with('fileRequest.user:id,name')
            ->latest('id')
            ->limit(50)
            ->get()
            // A request I sent to twice is one row, showing the latest.
            ->unique('file_request_id')
            ->values();

        return AskedRequestResource::collection($submissions);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            ...self::LIMIT_RULES,
        ]);

        $user = $request->user();

        $fileRequest = $user->fileRequests()->create([
            'title' => $validated['title'],
            'note' => $validated['note'] ?? null,
            // A link with no end date is a drop-box somebody forgets about.
            'expires_at' => $validated['expires_at']
                ?? now()->addDays(FileRequest::DEFAULT_DAYS),
            // Absent means the column defaults — 5 files, 100 MB.
            ...array_intersect_key($validated, array_flip(['max_files', 'max_mb'])),
        ]);

        $this->setPassword($fileRequest, $validated);

        Activity::log(
            $user,
            ActivityAction::RequestCreate,
            "Asked for files — {$fileRequest->title}",
            'file_request',
            $fileRequest->ulid,
        );

        return (new FileRequestResource(
            $fileRequest->fresh()->loadCount('submissions'),
        ))->response()->setStatusCode(201);
    }

    /**
     * Retitle, extend, or close.
     */
    public function update(Request $request, FileRequest $fileRequest): JsonResponse
    {
        $this->mine($request, $fileRequest);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'string', 'in:open,closed'],
            ...self::LIMIT_RULES,
        ]);

        $fileRequest->fill(array_intersect_key($validated, array_flip([
            'title', 'note', 'expires_at', 'max_files', 'max_mb',
        ])));

        if (isset($validated['status'])) {
            $fileRequest->status = FileRequestStatus::from($validated['status']);
        }

        $fileRequest->save();

        $this->setPassword($fileRequest, $validated);

        return response()->json([
            'data' => (new FileRequestResource(
                $fileRequest->load('submissions')->loadCount('submissions'),
            ))->toArray($request),
        ]);
    }

    /**
     * Delete the link. The files already delivered stay in the library —
     * a delivery is not undone by closing the door it came through.
     */
    public function destroy(Request $request, FileRequest $fileRequest): JsonResponse
    {
        $this->mine($request, $fileRequest);

        $fileRequest->delete();

        return response()->json(null, 204);
    }

    /**
     * 404 rather than 403: someone who does not own this request has no
     * business learning that it exists.
     */
    private function mine(Request $request, FileRequest $fileRequest): void
    {
        abort_unless($fileRequest->user_id === $request->user()->id, 404);
    }

    /**
     * Set, clear, or leave the password alone.
     *
     * forceFill because the hash is deliberately not fillable: the only way
     * to write it is through here, where a plain password is hashed on the
     * way in and never stored as typed.
     *
     * @param  array<string, mixed>  $validated
     */
    private function setPassword(FileRequest $fileRequest, array $validated): void
    {
        if (! array_key_exists('password', $validated)) {
            return;
        }

        $fileRequest->forceFill([
            'password_hash' => $validated['password'] === null
                ? null
                : Hash::make($validated['password']),
        ])->save();
    }
}
