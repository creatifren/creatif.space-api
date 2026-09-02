<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FileVersionResource;
use App\Models\File;
use App\Models\FileVersion;
use App\Support\AcceptedUploads;
use App\Support\ApprovalVoider;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * A file's history: what it used to be, and how to put it back.
 *
 * Replacing a file's bytes is the same two-step dance as uploading one —
 * presign, then complete once the browser's PUT has landed — because the
 * bytes never pass through here either. The difference is what happens in
 * between: the current bytes are filed away as a version before the new
 * ones take their place, so nothing is lost and nothing that pointed at the
 * file has to be rewritten.
 */
class FileVersionController extends Controller
{
    /**
     * The history behind a file, newest first. The current bytes are not in
     * here — they are the file.
     */
    public function index(Request $request, File $file): AnonymousResourceCollection
    {
        $user = Workspace::owner($request->user());
        abort_unless($file->user_id === $user->id, 404);

        return FileVersionResource::collection(
            $file->versions()->with('user')->get(),
        );
    }

    /**
     * Phase one of a replacement: reserve the quota the new bytes will need
     * and hand back a presigned PUT. Nothing about the file changes yet.
     */
    public function presign(Request $request, File $file): JsonResponse
    {
        $user = Workspace::owner($request->user());
        abort_unless($file->user_id === $user->id, 404);
        abort_unless(Workspace::canWrite($request->user()), 403);
        // Bytes that never landed have nothing worth keeping a history of.
        abort_unless($file->status === File::STATUS_READY, 409);

        $validated = $request->validate([
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.AcceptedUploads::MAX_BYTES],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        /* The old bytes stay, so a replacement costs the new size on top of
           what is already stored — not the difference. */
        if (! PlanQuota::canStore($user, (int) $validated['size_bytes'])) {
            throw ValidationException::withMessages([
                'size_bytes' => 'Your storage is full — free some space or upgrade your plan.',
            ]);
        }

        /* The incoming bytes go to a fresh key rather than over the file's
           own. If the upload dies half way, the file still serves what it
           always served. */
        $ulid = (string) str()->ulid();
        $path = FileVersion::keyFor($file, $ulid, $file->name);

        ['url' => $url] = Storage::disk($file->disk)->temporaryUploadUrl(
            $path,
            now()->addMinutes(15),
            ['ContentType' => $file->mime_type],
        );

        return response()->json(['data' => [
            'upload_id' => $ulid,
            'upload_url' => $url,
            'expires_at' => now()->addMinutes(15),
        ]], 201);
    }

    /**
     * Phase two: the browser says the PUT landed. Believe the bucket, then
     * swap — the file's current bytes become version N, and the newly
     * uploaded object becomes the file.
     */
    public function complete(Request $request, File $file): JsonResponse
    {
        $user = Workspace::owner($request->user());
        abort_unless($file->user_id === $user->id, 404);
        abort_unless(Workspace::canWrite($request->user()), 403);
        abort_unless($file->status === File::STATUS_READY, 409);

        $validated = $request->validate([
            'upload_id' => ['required', 'string', 'max:26'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'width' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'height' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $disk = Storage::disk($file->disk);
        $incoming = FileVersion::keyFor($file, $validated['upload_id'], $file->name);

        if (! $disk->exists($incoming)) {
            throw ValidationException::withMessages([
                'file' => 'The upload never arrived — try again.',
            ]);
        }

        $realSize = $disk->size($incoming);

        if (! PlanQuota::canStore($user, $realSize)) {
            $disk->delete($incoming);

            throw ValidationException::withMessages([
                'file' => 'The upload is larger than the space you have left.',
            ]);
        }

        /* The file keeps its own path: everything that ever linked to it —
           a published Space, a client's open tab — keeps working. So the
           OLD bytes are the ones that move, copied to the version's key
           before the new ones overwrite the original. */
        $archived = FileVersion::keyFor($file, (string) str()->ulid(), $file->name);
        $disk->copy($file->path, $archived);
        $disk->copy($incoming, $file->path);
        $disk->delete($incoming);

        $voided = DB::transaction(function () use ($file, $user, $validated, $archived, $realSize, $disk) {
            $file->versions()->create([
                'number' => $file->currentVersionNumber(),
                'disk' => $file->disk,
                'path' => $archived,
                'size_bytes' => $file->size_bytes,
                'checksum' => $file->checksum,
                'width' => $file->width,
                'height' => $file->height,
                'exif' => $file->exif,
                'user_id' => $user->id,
                'note' => $validated['note'] ?? null,
            ]);

            $file->update([
                'size_bytes' => $realSize,
                'width' => $validated['width'] ?? null,
                'height' => $validated['height'] ?? null,
                // The old EXIF described the old bytes.
                'exif' => null,
                'checksum' => null,
            ]);

            /* A client approved what they were shown, and that is no longer
               what is on screen. Their decision goes back to pending. */
            return ApprovalVoider::forFile($file);
        });

        return response()->json([
            'data' => [
                'versions' => $file->versions()->count(),
                'approvals_voided' => $voided,
            ],
        ]);
    }

    /**
     * Put an old version back. The bytes being replaced are themselves
     * filed away first, so restoring is undoable too — nothing about this
     * is a one-way door.
     */
    public function restore(Request $request, File $file, FileVersion $version): JsonResponse
    {
        $user = Workspace::owner($request->user());
        abort_unless($file->user_id === $user->id, 404);
        abort_unless($version->file_id === $file->id, 404);
        abort_unless(Workspace::canWrite($request->user()), 403);

        $disk = Storage::disk($file->disk);

        if (! $disk->exists($version->path)) {
            throw ValidationException::withMessages([
                'version' => 'Those bytes are no longer in storage.',
            ]);
        }

        $size = $disk->size($version->path);
        $archived = FileVersion::keyFor($file, (string) str()->ulid(), $file->name);

        $disk->copy($file->path, $archived);
        $disk->copy($version->path, $file->path);

        $voided = DB::transaction(function () use ($file, $user, $version, $archived, $size) {
            $file->versions()->create([
                'number' => $file->currentVersionNumber(),
                'disk' => $file->disk,
                'path' => $archived,
                'size_bytes' => $file->size_bytes,
                'checksum' => $file->checksum,
                'width' => $file->width,
                'height' => $file->height,
                'exif' => $file->exif,
                'user_id' => $user->id,
                'note' => 'Replaced by restoring v'.$version->number,
            ]);

            $file->update([
                'size_bytes' => $size,
                'width' => $version->width,
                'height' => $version->height,
                'exif' => $version->exif,
                'checksum' => $version->checksum,
            ]);

            return ApprovalVoider::forFile($file);
        });

        return response()->json([
            'data' => [
                'restored_from' => $version->number,
                'approvals_voided' => $voided,
            ],
        ]);
    }

    /**
     * Throw away an old version for good — the "free up space" action. The
     * current bytes are never reachable from here, so this cannot empty a
     * file.
     */
    public function destroy(Request $request, File $file, FileVersion $version): JsonResponse
    {
        $user = Workspace::owner($request->user());
        abort_unless($file->user_id === $user->id, 404);
        abort_unless($version->file_id === $file->id, 404);
        abort_unless(Workspace::canWrite($request->user()), 403);

        Storage::disk($version->disk)->delete($version->path);
        $version->delete();

        return response()->json(null, 204);
    }
}
