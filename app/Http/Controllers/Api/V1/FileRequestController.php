<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FileRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\FileRequestResource;
use App\Models\DriveAccount;
use App\Models\FileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * File Request, the owner's side: make a link, watch what arrives, close it.
 *
 * The link itself is answered by PublicFileRequestController — a stranger
 * and an owner see different things, and keeping them apart is what stops
 * the target folder leaking into the public payload.
 */
class FileRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return FileRequestResource::collection(
            $request->user()->fileRequests()
                ->with(['driveAccount', 'submissions'])
                ->withCount('submissions')
                ->latest('id')
                ->limit(50)
                ->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'drive_account_id' => ['required', 'string', 'size:26'],
            'folder_id' => ['required', 'string', 'max:128'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        $user = $request->user();

        $account = DriveAccount::query()
            ->where('user_id', $user->id)
            ->where('ulid', $validated['drive_account_id'])
            ->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'drive_account_id' => 'Connect that Drive again before asking for files.',
            ]);
        }

        $fileRequest = $user->fileRequests()->create([
            'title' => $validated['title'],
            'note' => $validated['note'] ?? null,
            'drive_account_id' => $account->id,
            'target_folder_id' => $validated['folder_id'],
            // A link with no end date is a drop-box somebody forgets about.
            'expires_at' => $validated['expires_at']
                ?? now()->addDays(FileRequest::DEFAULT_DAYS),
        ]);

        return (new FileRequestResource(
            $fileRequest->fresh()->load('driveAccount')->loadCount('submissions'),
        ))->response()->setStatusCode(201);
    }

    /**
     * Retitle, extend, or close. The folder is deliberately not editable:
     * a request that changes destination halfway makes the delivery
     * history a lie about where things went.
     */
    public function update(Request $request, FileRequest $fileRequest): JsonResponse
    {
        $this->mine($request, $fileRequest);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'string', 'in:open,closed'],
        ]);

        $fileRequest->fill(array_intersect_key($validated, array_flip([
            'title', 'note', 'expires_at',
        ])));

        if (isset($validated['status'])) {
            $fileRequest->status = FileRequestStatus::from($validated['status']);
        }

        $fileRequest->save();

        return response()->json([
            'data' => (new FileRequestResource(
                $fileRequest->load(['driveAccount', 'submissions'])->loadCount('submissions'),
            ))->toArray($request),
        ]);
    }

    /**
     * Delete the link. The files already delivered stay in Drive — they
     * were never ours to take back.
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
}
