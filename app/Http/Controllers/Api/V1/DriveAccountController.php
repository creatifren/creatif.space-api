<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriveAccountResource;
use App\Jobs\ImportDriveFile;
use App\Models\DriveAccount;
use App\Models\Folder;
use App\Services\GoogleDriveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DriveAccountController extends Controller
{
    /**
     * The user's connected Drive accounts (Settings → import, Files page).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return DriveAccountResource::collection(
            $request->user()->driveAccounts()->get(),
        );
    }

    /**
     * Import files chosen in the Google Picker: one copy job per id. The
     * Picker's own metadata is untrusted — jobs re-fetch from Drive. Capped
     * at 50: each id is a full byte copy now, not a metadata fetch.
     */
    public function pick(Request $request, DriveAccount $driveAccount): JsonResponse
    {
        Gate::allowIf(fn ($user) => $driveAccount->user_id === $user->id);

        $validated = $request->validate([
            'file_ids' => ['required', 'array', 'min:1', 'max:50'],
            'file_ids.*' => ['string', 'max:128'],
            'folder_id' => ['sometimes', 'nullable', 'string', 'max:26'],
        ]);

        $folder = Folder::ownedBy($driveAccount->user, $validated['folder_id'] ?? null);

        foreach (array_unique($validated['file_ids']) as $fileId) {
            ImportDriveFile::dispatch($driveAccount, $fileId, $folder?->id);
        }

        return response()->json(['data' => [
            'queued' => count(array_unique($validated['file_ids'])),
        ]], 202);
    }

    /**
     * A short-lived access token for the Google Picker. The Picker runs in
     * the browser and must present an OAuth token scoped to drive.file —
     * this is the one place a token crosses to the client, and it expires
     * within the hour.
     */
    public function pickerToken(Request $request, DriveAccount $driveAccount, GoogleDriveService $drive): JsonResponse
    {
        Gate::allowIf(fn ($user) => $driveAccount->user_id === $user->id);

        return response()->json(['data' => [
            'access_token' => $drive->accessToken($driveAccount),
        ]]);
    }

    /**
     * Disconnect a Drive account. Imported files stay — they are copies on
     * our storage, not references into the Drive.
     */
    public function destroy(Request $request, DriveAccount $driveAccount): JsonResponse
    {
        Gate::allowIf(fn ($user) => $driveAccount->user_id === $user->id);

        $driveAccount->delete();

        return response()->json(null, 204);
    }
}
