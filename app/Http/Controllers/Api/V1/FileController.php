<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FileResource;
use App\Http\Resources\FolderResource;
use App\Models\File;
use App\Models\Folder;
use App\Support\AcceptedUploads;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FileController extends Controller
{
    /**
     * The Crefile browser: everything the user has uploaded or imported.
     *
     * Filters mirror the /files screen: ?search= (name), ?type=image|video|
     * pdf. Sort: newest first by default, ?sort=name for A-Z. Failed rows
     * are noise and never listed; pending ones appear so an import in
     * flight is honest about itself.
     *
     * ?folder_id= scopes to one folder (empty value = the root) and adds
     * `meta.folders` (its sub-folders) and `meta.breadcrumb`. Without
     * the key the listing stays global — every other caller wants that.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', 'string', 'in:image,video,pdf'],
            // "Show me only the files I have more than one copy of."
            'duplicates' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'nullable', 'string', 'in:recent,name,size'],
            // Home wants `meta.total` and nothing else; without this it pays
            // for 60 rows and their eager-loaded Spaces to read one integer.
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'folder_id' => ['sometimes', 'nullable', 'string', 'max:26'],
        ]);

        $user = Workspace::owner($request->user());

        $inFolder = $request->has('folder_id');
        $folder = $inFolder ? Folder::ownedBy($user, $validated['folder_id'] ?? null) : null;

        $query = File::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', File::STATUS_FAILED)
            // spaceItems.space feeds the "in a Space" chip and the filter that
            // asks about it. Eager-loaded, so it is two extra queries for the
            // whole page rather than two per row.
            ->with(['spaceItems.space'])
            // Counted, not loaded: the row only needs the number.
            ->withCount('versions');

        if ($inFolder) {
            // where(col, null) compiles to IS NULL — the root.
            $query->where('folder_id', $folder?->id);
        }

        if (($validated['search'] ?? null) !== null && $validated['search'] !== '') {
            $query->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $validated['search']).'%');
        }

        match ($validated['type'] ?? null) {
            'image' => $query->where('mime_type', 'like', 'image/%'),
            'video' => $query->where('mime_type', 'like', 'video/%'),
            'pdf' => $query->where('mime_type', 'application/pdf'),
            default => null,
        };

        if ($validated['duplicates'] ?? false) {
            /* Every file whose bytes appear more than once in this account.
               Both copies are shown, not just the extras — the screen is for
               deciding which to keep, and hiding one of a pair would decide
               for the person. Files with no checksum cannot be compared and
               are absent rather than guessed at. */
            $query->whereNotNull('checksum')
                ->whereIn('checksum', File::query()
                    ->where('user_id', $user->id)
                    ->whereNotNull('checksum')
                    ->groupBy('checksum')
                    ->havingRaw('COUNT(*) > 1')
                    ->select('checksum'));
        }

        match ($validated['sort'] ?? 'recent') {
            'name' => $query->orderBy('name'),
            'size' => $query->orderByDesc('size_bytes'),
            default => $query->latest('id'),
        };

        $perPage = $validated['per_page'] ?? 60;

        $meta = [
            'storage_used' => PlanQuota::storageUsed($user),
            'storage_limit' => PlanQuota::storageLimit($user),
        ];

        if ($inFolder) {
            $meta['folders'] = FolderResource::collection(
                Folder::query()
                    ->where('user_id', $user->id)
                    ->where('parent_id', $folder?->id)
                    ->withCount(['files', 'children'])
                    ->orderBy('name')
                    ->get(),
            );
            // Not `path`: the paginator already owns that key in meta.
            $meta['breadcrumb'] = $folder?->ancestorsAndSelf() ?? [];
        }

        return FileResource::collection($query->paginate($perPage)->appends($request->query()))
            ->additional(['meta' => $meta])
            ->response();
    }

    /**
     * Detail drawer: one file with its EXIF panel.
     */
    public function show(Request $request, File $file): FileResource
    {
        abort_unless($file->user_id === Workspace::owner($request->user())->id, 404);

        return new FileResource(
            $file->load('spaceItems.space')->loadCount('versions'),
        );
    }

    /**
     * Phase one of a direct upload: validate, reserve quota with a pending
     * row, and hand the browser a presigned PUT so the bytes go straight
     * to R2 without passing through here.
     */
    public function presign(Request $request): JsonResponse
    {
        abort_unless(Workspace::canWrite($request->user()), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'mime_type' => ['required', 'string', 'in:'.implode(',', AcceptedUploads::MIMES)],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.AcceptedUploads::MAX_BYTES],
            'folder_id' => ['sometimes', 'nullable', 'string', 'max:26'],
        ]);

        $user = Workspace::owner($request->user());
        $folder = Folder::ownedBy($user, $validated['folder_id'] ?? null);

        if (! PlanQuota::canStore($user, (int) $validated['size_bytes'])) {
            throw ValidationException::withMessages([
                'size_bytes' => 'Your storage is full — free some space or upgrade your plan.',
            ]);
        }

        $ulid = (string) str()->ulid();

        $file = File::create([
            'user_id' => $user->id,
            'folder_id' => $folder?->id,
            'ulid' => $ulid,
            'path' => File::keyFor($user, $ulid, $validated['name']),
            'name' => $validated['name'],
            'mime_type' => $validated['mime_type'],
            'size_bytes' => (int) $validated['size_bytes'],
            'status' => File::STATUS_PENDING,
            'source' => 'upload',
        ]);

        // ContentType is pinned into the signature: the browser must send
        // exactly the declared mime or R2 refuses the PUT.
        ['url' => $url] = Storage::disk('s3')->temporaryUploadUrl(
            $file->path,
            now()->addMinutes(15),
            ['ContentType' => $validated['mime_type']],
        );

        return response()->json(['data' => [
            'id' => $file->ulid,
            'upload_url' => $url,
            'expires_at' => now()->addMinutes(15),
        ]], 201);
    }

    /**
     * Phase two: the browser says the PUT finished. Believe R2, not the
     * browser — the object must exist, and its real size wins over the
     * declared one (re-checked against quota, since a lie the other way
     * would smuggle bytes past presign).
     */
    public function complete(Request $request, File $file): FileResource|JsonResponse
    {
        $user = Workspace::owner($request->user());
        abort_unless($file->user_id === $user->id, 404);
        abort_unless($file->status === File::STATUS_PENDING, 409);

        $validated = $request->validate([
            // The browser decodes the image itself — free dimensions,
            // no byte round-trip on our side.
            'width' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'height' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $disk = Storage::disk($file->disk);

        if (! $disk->exists($file->path)) {
            throw ValidationException::withMessages([
                'file' => 'The upload never arrived — try again.',
            ]);
        }

        $realSize = $disk->size($file->path);
        $declared = (int) $file->size_bytes;

        if ($realSize > $declared && ! PlanQuota::canStore($user, $realSize - $declared)) {
            $disk->delete($file->path);
            $file->update(['status' => File::STATUS_FAILED]);

            throw ValidationException::withMessages([
                'file' => 'The upload is larger than the space you have left.',
            ]);
        }

        $file->update([
            'size_bytes' => $realSize,
            /* The object's MD5, read from R2 rather than trusted from the
               browser — it is what "these two files are the same bytes"
               rests on. Null for a multipart upload, whose ETag is not an
               MD5 (see File::md5FromEtag). */
            'checksum' => File::md5FromEtag($disk->checksum($file->path)),
            'width' => $validated['width'] ?? null,
            'height' => $validated['height'] ?? null,
            'status' => File::STATUS_READY,
        ]);

        \App\Notifications\StorageAlmostFull::checkAndSend($user);

        return new FileResource($file->refresh());
    }

    /**
     * Move a file to the Trash. The object stays in the bucket and keeps
     * counting against the quota; PurgeTrashedFiles takes both after
     * File::TRASH_DAYS, or the owner empties the bin sooner.
     *
     * Still refused while a Space shows it: soft delete would leave a
     * published page with a hole in it until somebody noticed and restored.
     * Remove it from the Space first — that is a decision, not an accident.
     */
    public function destroy(Request $request, File $file): JsonResponse
    {
        $user = Workspace::owner($request->user());
        abort_unless($file->user_id === $user->id, 404);
        abort_unless(Workspace::canWrite($request->user()), 403);

        if ($file->spaceItems()->exists()) {
            return response()->json([
                'message' => 'This file is used in a Space — remove it there first.',
            ], 409);
        }

        $file->forceFill(['purge_at' => now()->addDays(File::TRASH_DAYS)])->save();
        $file->delete();

        return response()->json(null, 204);
    }

    /**
     * What is in the Trash, soonest to go first — the order the screen
     * reads in, and the only one where "Going soon" means anything.
     */
    public function trash(Request $request): AnonymousResourceCollection
    {
        $user = Workspace::owner($request->user());

        return FileResource::collection(
            File::onlyTrashed()
                ->where('user_id', $user->id)
                ->with('folder')
                ->orderBy('purge_at')
                ->get(),
        );
    }

    /**
     * Put one back. `purge_at` is cleared with it: a file restored and
     * deleted again gets a fresh window, not the remains of the old one.
     */
    public function restore(Request $request, string $ulid): JsonResponse
    {
        $user = Workspace::owner($request->user());
        abort_unless(Workspace::canWrite($request->user()), 403);

        $file = File::onlyTrashed()
            ->where('user_id', $user->id)
            ->where('ulid', $ulid)
            ->firstOrFail();

        $file->restore();
        $file->forceFill(['purge_at' => null])->save();

        return response()->json(['data' => new FileResource($file->fresh())]);
    }

    /**
     * Delete for good, now. The one thing on that screen with no undo, so
     * it is a separate route from destroy() rather than a flag on it.
     */
    public function forceDestroy(Request $request, string $ulid): JsonResponse
    {
        $user = Workspace::owner($request->user());
        abort_unless(Workspace::canWrite($request->user()), 403);

        $file = File::onlyTrashed()
            ->where('user_id', $user->id)
            ->where('ulid', $ulid)
            ->firstOrFail();

        static::purge($file);

        return response()->json(null, 204);
    }

    /**
     * Empty the bin. Same finality as forceDestroy, one row at a time so a
     * single unreachable object cannot strand the rest.
     */
    public function emptyTrash(Request $request): JsonResponse
    {
        $user = Workspace::owner($request->user());
        abort_unless(Workspace::canWrite($request->user()), 403);

        File::onlyTrashed()
            ->where('user_id', $user->id)
            ->each(fn (File $file) => static::purge($file));

        return response()->json(null, 204);
    }

    /**
     * Object first, then the row, then the versions' objects. Shared with
     * PurgeTrashedFiles so the manual and scheduled paths cannot drift.
     */
    public static function purge(File $file): void
    {
        foreach ($file->versions as $version) {
            Storage::disk($file->disk)->delete($version->path);
        }

        Storage::disk($file->disk)->delete($file->path);
        $file->forceDelete();
    }
}
