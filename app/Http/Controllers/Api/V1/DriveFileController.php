<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriveFileResource;
use App\Models\DriveFile;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DriveFileController extends Controller
{
    /**
     * The Crefile browser: the user's synced files across all their Drives.
     *
     * Filters mirror the /files screen: ?search= (name), ?type=image|video|
     * pdf|folder, ?folder={provider_folder_id} for traversal, ?account={ulid}.
     * Sort: newest sync first by default, ?sort=name for A-Z.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', 'string', 'in:image,video,pdf,folder'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:128'],
            'account' => ['sometimes', 'nullable', 'string', 'max:26'],
            'sort' => ['sometimes', 'nullable', 'string', 'in:recent,name,size'],
        ]);

        $query = DriveFile::query()
            ->whereHas('account', fn ($q) => $q->where('user_id', Workspace::owner($request->user())->id))
            ->whereNull('trashed_at')
            // spaceItems.space feeds the "in a Space" chip and the filter that
            // asks about it. Eager-loaded, so it is two extra queries for the
            // whole page rather than two per row.
            ->with(['account', 'spaceItems.space']);

        if (($validated['search'] ?? null) !== null && $validated['search'] !== '') {
            $query->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $validated['search']).'%');
        }

        match ($validated['type'] ?? null) {
            'image' => $query->where('mime_type', 'like', 'image/%'),
            'video' => $query->where('mime_type', 'like', 'video/%'),
            'pdf' => $query->where('mime_type', 'application/pdf'),
            'folder' => $query->where('is_folder', true),
            default => null,
        };

        if (($validated['folder'] ?? null) !== null) {
            $query->where('parent_folder_id', $validated['folder']);
        }

        if (($validated['account'] ?? null) !== null) {
            $query->whereHas('account', fn ($q) => $q->where('ulid', $validated['account']));
        }

        match ($validated['sort'] ?? 'recent') {
            'name' => $query->orderBy('name'),
            'size' => $query->orderByDesc('size_bytes'),
            default => $query->orderByDesc('last_synced_at'),
        };

        // Folders first, like every file browser.
        $query->orderByDesc('is_folder');

        return DriveFileResource::collection(
            $query->paginate(60)->appends($request->query()),
        );
    }

    /**
     * Detail drawer: one file with its EXIF panel.
     */
    public function show(Request $request, DriveFile $driveFile): DriveFileResource
    {
        abort_unless(
            $driveFile->account->user_id === Workspace::owner($request->user())->id,
            404,
        );

        return new DriveFileResource(
            $driveFile->load(['account', 'spaceItems.space']),
        );
    }
}
