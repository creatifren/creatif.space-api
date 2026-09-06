<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FileRequestStatus;
use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicFileRequestResource;
use App\Jobs\UploadSubmissionFiles;
use App\Models\FileRequest;
use App\Support\SpaceUnlockToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The link a stranger opens. No account, no login — that is the point of a
 * File Request, and it is also why every limit here is enforced rather
 * than trusted.
 *
 * Gate order mirrors the Space viewer: 404 unknown → 410 expired → 409
 * closed → 423 locked. Closed is not 404: somebody who was invited
 * deserves "this is closed now", not "you mistyped the link". And the lock
 * comes last for the same reason it does there — a dead link must not ask
 * for a password it was never going to honour.
 */
class PublicFileRequestController extends Controller
{
    public function show(Request $request, string $slug): JsonResponse
    {
        $fileRequest = $this->resolve($slug);

        if ($this->isLocked($fileRequest, $request->query('st'))) {
            /* Nothing leaks through the lock — not the title, not the note,
               not the limits. Same rule as a locked Space or Transfer. */
            return response()->json([
                'locked' => true,
                'owner_name' => $fileRequest->user->name,
            ], 423);
        }

        return response()->json([
            'data' => (new PublicFileRequestResource($fileRequest))
                ->toArray($request),
        ]);
    }

    /**
     * Trade the password for a token. The sender has no session — they have
     * no account by design — so the signed token is the state.
     */
    public function unlock(Request $request, string $slug): JsonResponse
    {
        $fileRequest = $this->resolve($slug);
        $validated = $request->validate([
            'password' => ['required', 'string', 'max:255'],
        ]);

        if (
            $fileRequest->password_hash === null
            || ! Hash::check($validated['password'], $fileRequest->password_hash)
        ) {
            return response()->json([
                'message' => "That password isn't right — check with {$fileRequest->user->name}.",
            ], 422);
        }

        return response()->json(['data' => [
            'token' => SpaceUnlockToken::issueFor($fileRequest->ulid),
        ]]);
    }

    /**
     * Take a delivery. Answers 202, not 201: the files are staged here and
     * pushed to storage by a job, because somebody uploading from a phone
     * must not hold the connection open through a storage round-trip.
     */
    public function store(Request $request, string $slug): JsonResponse
    {
        $fileRequest = $this->resolve($slug);

        /* The same lock as the page. A submit route that skipped it would
           be a hole beside the door: the form is trivially reconstructed
           from the slug alone. */
        if ($this->isLocked($fileRequest, $request->input('st', $request->query('st')))) {
            return response()->json(['locked' => true], 423);
        }

        $validated = $request->validate([
            'sender_name' => ['required', 'string', 'max:120'],
            // Validated as an email, never verified — this is a drop-box
            // link, not an account.
            'sender_email' => ['required', 'email', 'max:255'],
            'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'files' => ['required', 'array', 'min:1', 'max:'.$fileRequest->max_files],
            'files.*' => [
                'file',
                'max:'.($fileRequest->max_mb * 1024),
                'mimetypes:'.implode(',', \App\Support\AcceptedUploads::MIMES),
            ],
        ]);

        /** @var list<UploadedFile> $files */
        $files = $request->file('files');

        /* Resolved outside the closure: the transaction only captures what
           it is handed, and `$request` is not one of those things. */
        $senderId = $request->user()?->id;

        $submission = DB::transaction(function () use ($fileRequest, $validated, $senderId) {
            $submission = $fileRequest->submissions()->create([
                /* Recorded only when the sender happened to be signed in —
                   the route needs no account and never will. That is the
                   one case where "I sent this" is a fact rather than a
                   claim, which is why the (unverified) sender_email is not
                   used for it. */
                'user_id' => $senderId,
                'sender_name' => $validated['sender_name'],
                'sender_email' => $validated['sender_email'],
                'message' => $validated['message'] ?? null,
                'files' => [],
            ]);

            // A link that went further than its owner meant closes itself.
            if ($fileRequest->submissions()->count() >= FileRequest::SUBMISSION_CEILING) {
                $fileRequest->forceFill(['status' => FileRequestStatus::Closed])->save();
            }

            return $submission;
        });

        $staged = [];

        foreach ($files as $file) {
            // The sender's filename is display metadata and nothing else:
            // the stored path is a name we chose, so a crafted filename
            // cannot reach outside the staging directory.
            $staged[] = [
                'path' => $file->storeAs(
                    'submissions/'.$submission->ulid,
                    (string) Str::uuid(),
                    'local',
                ),
                'name' => Str::limit(
                    $file->getClientOriginalName() ?: 'file',
                    200,
                    '',
                ),
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
            ];
        }

        UploadSubmissionFiles::dispatch($submission, $staged);

        return response()->json([
            'data' => [
                'id' => $submission->ulid,
                'files' => count($staged),
                'status' => SubmissionStatus::Uploading->value,
            ],
        ], 202);
    }

    /**
     * Locked to this holder: there is a password, and they have not traded
     * it for a token yet.
     */
    private function isLocked(FileRequest $fileRequest, ?string $token): bool
    {
        return $fileRequest->password_hash !== null
            && ! SpaceUnlockToken::verifyFor($fileRequest->ulid, $token);
    }

    private function resolve(string $slug): FileRequest
    {
        $fileRequest = FileRequest::query()
            ->where('slug', $slug)
            ->with('user')
            ->first();

        abort_if($fileRequest === null, 404);
        abort_if($fileRequest->isExpired(), 410);
        abort_unless($fileRequest->isOpen(), 409);

        return $fileRequest;
    }
}
