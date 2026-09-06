<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Activity;
use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\FolderResource;
use App\Models\Folder;
use App\Models\User;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    /**
     * "New folder" in the library. Children are listed by GET /files with
     * `folder_id`, so there is no index here.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless(Workspace::canWrite($request->user()), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'string', 'max:26'],
        ]);

        $user = Workspace::owner($request->user());
        $parent = Folder::ownedBy($user, $validated['parent_id'] ?? null);

        $folder = Folder::create([
            'user_id' => $user->id,
            'parent_id' => $parent?->id,
            'name' => trim($validated['name']),
        ]);

        Activity::log($user, ActivityAction::FolderCreate, "Created the folder {$folder->name}", 'folder', $folder->ulid);

        return (new FolderResource($folder->loadCount(['files', 'children'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Rename, or move somewhere else in the tree.
     *
     * Moving is refused when the destination is the folder itself or one of
     * its own descendants: the row would still be there, but nothing would
     * reach it — a subtree orphaned inside its own child, invisible from
     * the root and impossible to open.
     */
    public function update(Request $request, Folder $folder): FolderResource
    {
        $user = $this->mine($request, $folder);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            // Explicit null moves it to the root; absent leaves it alone.
            'parent_id' => ['sometimes', 'nullable', 'string', 'max:26'],
        ]);

        if (array_key_exists('parent_id', $validated)) {
            $parent = Folder::ownedBy($user, $validated['parent_id']);

            abort_if(
                $parent !== null && $parent->isSelfOrDescendantOf($folder),
                422,
                'A folder cannot be moved inside itself.',
            );

            $folder->parent_id = $parent?->id;
        }

        if (isset($validated['name'])) {
            $folder->name = trim($validated['name']);
        }

        $folder->save();

        return new FolderResource($folder->loadCount(['files', 'children']));
    }

    /**
     * Delete the folder, not what is in it.
     *
     * Files fall back to the root — `nullOnDelete` on files.folder_id, and
     * the point of the whole feature: a folder is how the owner sees their
     * library, so removing the view must never remove the work. Child
     * folders cascade, and their files surface at the root too.
     */
    public function destroy(Request $request, Folder $folder): JsonResponse
    {
        $this->mine($request, $folder);

        $name = $folder->name;
        $folder->delete();

        Activity::log(
            $request->user(),
            ActivityAction::FolderDelete,
            "Deleted the folder {$name}",
        );

        return response()->json(null, 204);
    }

    /**
     * 404 rather than 403, the same rule the rest of the API follows:
     * somebody who does not own this has no business learning it exists.
     * Returns the workspace owner, since that is whose tree this is.
     */
    private function mine(Request $request, Folder $folder): User
    {
        abort_unless(Workspace::canWrite($request->user()), 403);

        $user = Workspace::owner($request->user());
        abort_unless($folder->user_id === $user->id, 404);

        return $user;
    }
}
