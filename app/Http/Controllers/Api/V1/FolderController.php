<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FolderResource;
use App\Models\Folder;
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

        return (new FolderResource($folder->loadCount(['files', 'children'])))
            ->response()
            ->setStatusCode(201);
    }
}
