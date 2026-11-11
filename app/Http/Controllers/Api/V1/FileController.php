<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityAction;
use App\Exceptions\MediaRefused;
use App\Http\Controllers\Controller;
use App\Http\Resources\FileResource;
use App\Http\Resources\FolderResource;
use App\Models\Activity;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Notifications\StorageAlmostFull;
use App\Support\AcceptedUploads;
use App\Support\Media\FileSignature;
use App\Support\Media\MediaLimits;
use App\Support\Media\MediaQuota;
use App\Support\Media\MediaRetention;
use App\Support\Media\MediaUploadGuard;
use App\Support\Media\PresignedUpload;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FileController extends Controller
{
    public const REFUSED_FORMAT = 'This format is not supported. Convert it to JPEG, PNG, WebP, GIF, SVG, PDF, MP4 or MOV and upload that instead.';

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
            // Where a file came from. drive_import stays valid: files copied
            // in before Drive import was removed still carry it.
            'source' => ['sometimes', 'nullable', 'string', 'in:upload,drive_import,request'],
            // "Show me only the files I have more than one copy of."
            'duplicates' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'nullable', 'string', 'in:recent,name,size'],
            // Home wants `meta.total` and nothing else; without this it pays
            // for 60 rows and their eager-loaded Spaces to read one integer.
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'folder_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            // "All subfolders": everything beneath this folder rather than
            // only what sits directly in it.
            'recursive' => ['sometimes', 'boolean'],
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
            ->with('spaceItems.space')
            // Counted, not loaded: the row only needs the number.
            ->withCount('versions');

        /* Recursive at the root needs no clause at all: "this folder and
           everything under it" is the whole library, which is what an
           unscoped listing already returns. Only a named folder narrows. */
        $recursive = (bool) ($validated['recursive'] ?? false);

        if ($inFolder && $recursive && $folder !== null) {
            $query->whereIn('folder_id', $folder->descendantIds());
        } elseif ($inFolder && ! $recursive) {
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

        if (($validated['source'] ?? null) !== null) {
            $query->where('source', $validated['source']);
        }

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

        $page = $query->paginate($perPage)->appends($request->query());
        // One batch for the page's is_kept / auto_delete_at, not a query per row.
        MediaRetention::prime($page->getCollection());
        $meta['usage'] = MediaQuota::usage($user);

        return FileResource::collection($page)
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
     * The owner's own copy, as a download.
     *
     * The public routes have had this since the bucket went private; the
     * owner never did, so the one person who is certainly allowed to keep
     * a file was the one who could only open it in a tab. No gates to
     * re-check here beyond ownership — allow_download is a Space setting,
     * about what a *client* may do with a link they were sent.
     *
     * A pending or failed row has no bytes at the key yet, so it 404s
     * rather than handing out a URL that resolves to nothing.
     */
    public function download(Request $request, File $file): JsonResponse
    {
        abort_unless($file->user_id === Workspace::owner($request->user())->id, 404);
        abort_unless($file->status === File::STATUS_READY, 404);

        return response()->json(['data' => ['url' => $file->downloadUrl()]]);
    }

    /**
     * Phase one of a direct upload: validate, reserve quota with a pending
     * row, and hand the browser a presigned PUT so the bytes go straight
     * to R2 without passing through here.
     *
     * Media v2 checks, in order: the INTERNAL abuse limits, the per-file
     * cap for the tier and kind, then the quota (Free 1 GB total; paid the
     * Keep quota). The PUT is signed for the declared Content-Type and
     * Content-Length, so R2 refuses any other body; complete() checks the
     * real bytes again.
     */
    public function presign(Request $request): JsonResponse
    {
        abort_unless(Workspace::canWrite($request->user()), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'mime_type' => ['required', 'string', 'in:'.implode(',', AcceptedUploads::MIMES)],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'folder_id' => ['sometimes', 'nullable', 'string', 'max:26'],
        ], [
            // Says what to do, not only what is wrong (connected apps pass it on).
            'mime_type.in' => self::REFUSED_FORMAT,
        ]);

        $user = Workspace::owner($request->user());
        $folder = Folder::ownedBy($user, $validated['folder_id'] ?? null);
        $size = (int) $validated['size_bytes'];

        MediaUploadGuard::presign($user, $size);
        self::assertFileSize($user, $validated['mime_type'], $size);
        MediaQuota::assertUploadFits($user, $size);

        $ulid = (string) str()->ulid();

        $file = File::create([
            'user_id' => $user->id,
            'folder_id' => $folder?->id,
            'ulid' => $ulid,
            'path' => File::keyFor($user, $ulid, $validated['name'], $validated['mime_type']),
            'name' => $validated['name'],
            'mime_type' => $validated['mime_type'],
            'size_bytes' => $size,
            'status' => File::STATUS_PENDING,
            'source' => 'upload',
        ]);

        // Content-Type and Content-Length are signed (LockedPutSignature):
        // the browser must send exactly the declared mime and size or R2
        // refuses the PUT. (The SDK's default presign signed neither.)
        $url = PresignedUpload::url('s3', $file->path, $validated['mime_type'], $size, now()->addMinutes(File::PRESIGN_TTL_MINUTES));

        return response()->json(['data' => [
            'id' => $file->ulid,
            'upload_url' => $url,
            // What the PUT must carry (the browser sets Content-Length itself).
            'headers' => ['Content-Type' => $validated['mime_type']],
            'expires_at' => now()->addMinutes(File::PRESIGN_TTL_MINUTES),
        ]], 201);
    }

    /**
     * Phase two: the browser says the PUT finished. Believe R2, not the
     * browser — the object must exist, its real size wins over the declared
     * one (re-checked against the per-file cap and the quota), and its first
     * bytes must be the format it claims (magic bytes, ranged GET). Anything
     * else deletes the object and marks the row failed before the file is
     * ever usable.
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

        $realSize = (int) $disk->size($file->path);
        $declared = (int) $file->size_bytes;
        $reject = function () use ($disk, $file) {
            $disk->delete($file->path);
            $file->update(['status' => File::STATUS_FAILED]);
        };

        try {
            self::assertFileSize($user, $file->mime_type, $realSize);
            if ($realSize > $declared) {
                MediaQuota::assertUploadFits($user, $realSize - $declared);
            }
        } catch (MediaRefused $e) {
            $reject();

            throw $e;
        }

        $detected = FileSignature::verify((string) FileSignature::head($disk, $file->path), $file->mime_type);
        if ($detected === null) {
            $reject();
            MediaUploadGuard::hit($user, 'unsupported_format');

            throw MediaRefused::unsupportedFormat();
        }

        $file->update([
            'size_bytes' => $realSize,
            'mime_type' => $detected,
            /* The object's MD5, read from R2 rather than trusted from the
               browser — it is what "these two files are the same bytes"
               rests on. Null for a multipart upload, whose ETag is not an
               MD5 (see File::md5FromEtag). */
            'checksum' => File::md5FromEtag($disk->checksum($file->path)),
            'width' => $validated['width'] ?? null,
            'height' => $validated['height'] ?? null,
            'status' => File::STATUS_READY,
        ]);

        Activity::log(
            $user,
            ActivityAction::Upload,
            "Uploaded {$file->name}",
            'file',
            $file->ulid,
        );

        StorageAlmostFull::checkAndSend($user);

        return new FileResource($file->refresh());
    }

    /**
     * Mark a file Keep: retention never deletes it. Paid only, within the
     * Keep quota; refused with 402 quota_exceeded otherwise.
     */
    public function keep(Request $request, File $file): FileResource
    {
        $user = Workspace::owner($request->user());
        abort_unless($file->user_id === $user->id, 404);
        abort_unless(Workspace::canWrite($request->user()), 403);
        abort_unless($file->status === File::STATUS_READY, 409);

        MediaQuota::keep($user, $file);

        return new FileResource($file->refresh()->load('spaceItems.space')->loadCount('versions'));
    }

    /** Back under the retention rules. Always allowed. */
    public function unkeep(Request $request, File $file): FileResource
    {
        $user = Workspace::owner($request->user());
        abort_unless($file->user_id === $user->id, 404);
        abort_unless(Workspace::canWrite($request->user()), 403);

        MediaQuota::unkeep($file);

        return new FileResource($file->refresh()->load('spaceItems.space')->loadCount('versions'));
    }

    /**
     * Media usage: paid {keep_used_bytes, keep_quota_bytes}, Free
     * {used_bytes, quota_bytes}.
     */
    public function usage(Request $request): JsonResponse
    {
        return response()->json(['data' => MediaQuota::usage($request->user())]);
    }

    /**
     * The tier's per-file cap for this kind. A plan cap is named in the
     * refusal; the internal technical ceiling never is.
     *
     * @throws MediaRefused
     */
    public static function assertFileSize(User $owner, string $mime, int $bytes): void
    {
        ['bytes' => $max, 'plan_cap' => $planCap] = MediaLimits::perFileMax($owner, $mime);

        if ($bytes > $max) {
            MediaUploadGuard::hit($owner, 'file_too_large');

            throw MediaRefused::tooLarge(
                $planCap ? $max : null,
                FileSignature::family($mime) === 'video' ? MediaLimits::nextStep($owner) : null,
            );
        }
    }

    /**
     * Move files into a folder, or out to the root.
     *
     * Nothing moves in storage: the object key is fixed at upload and a
     * folder is only how the owner sees their library. So this is one
     * UPDATE, and a file that is in a Space or a transfer stays in it —
     * unlike the Trash, there is nothing here to break.
     */
    public function move(Request $request): JsonResponse
    {
        abort_unless(Workspace::canWrite($request->user()), 403);

        $validated = $request->validate([
            'file_ids' => ['required', 'array', 'min:1', 'max:200'],
            'file_ids.*' => ['string', 'max:26'],
            // Null is the root, and has to be sent rather than omitted:
            // "move" with no destination is not a request worth guessing at.
            'folder_id' => ['present', 'nullable', 'string', 'max:26'],
        ]);

        $user = Workspace::owner($request->user());
        $folder = Folder::ownedBy($user, $validated['folder_id']);

        /* Own files only, and resolved before the write: a batch that half
           applies would leave the screen disagreeing with the library. */
        $files = File::query()
            ->where('user_id', $user->id)
            ->whereIn('ulid', $validated['file_ids'])
            ->get();

        if ($files->count() !== count(array_unique($validated['file_ids']))) {
            return response()->json([
                'message' => 'Some of those files are not in your library any more.',
            ], 422);
        }

        File::query()->whereIn('id', $files->pluck('id'))->update([
            'folder_id' => $folder?->id,
        ]);

        $where = $folder === null ? 'the main folder' : $folder->name;
        $count = $files->count();

        Activity::log(
            $user,
            ActivityAction::Move,
            $count === 1
                ? "Moved {$files->first()->name} to {$where}"
                : "Moved {$count} files to {$where}",
            $folder === null ? null : 'folder',
            $folder?->ulid,
        );

        return response()->json(null, 204);
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

        Activity::log($user, ActivityAction::Trash, "Moved {$file->name} to the Trash", 'file', $file->ulid);

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
        // Found first: another workspace's id is 404 even for a viewer.
        $file = File::onlyTrashed()
            ->where('user_id', $user->id)
            ->where('ulid', $ulid)
            ->firstOrFail();
        abort_unless(Workspace::canWrite($request->user()), 403);

        $file->restore();
        $file->forceFill(['purge_at' => null])->save();

        Activity::log($user, ActivityAction::Restore, "Restored {$file->name}", 'file', $file->ulid);

        return response()->json(['data' => new FileResource($file->fresh())]);
    }

    /**
     * Delete for good, now. The one thing on that screen with no undo, so
     * it is a separate route from destroy() rather than a flag on it.
     */
    public function forceDestroy(Request $request, string $ulid): JsonResponse
    {
        $user = Workspace::owner($request->user());
        // Found first: another workspace's id is 404 even for a viewer.
        $file = File::onlyTrashed()
            ->where('user_id', $user->id)
            ->where('ulid', $ulid)
            ->firstOrFail();
        abort_unless(Workspace::canWrite($request->user()), 403);

        Activity::log($user, ActivityAction::Purge, "Deleted {$file->name} for good", 'file', $file->ulid);
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

        $emptied = File::onlyTrashed()->where('user_id', $user->id)->count();

        File::onlyTrashed()
            ->where('user_id', $user->id)
            ->each(fn (File $file) => static::purge($file));

        if ($emptied > 0) {
            Activity::log(
                $user,
                ActivityAction::Purge,
                $emptied === 1 ? 'Emptied the Trash — 1 file' : "Emptied the Trash — {$emptied} files",
            );
        }

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
