<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FileVersion;
use App\Support\PlanQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the account is using, and what of it can be handed back.
 *
 * The numbers already existed — they rode on `GET /files` meta, so a screen
 * that wanted the ceiling and nothing else (Trash, Usage) had to ask for a
 * page of files to get it. Usage was fetching `per_page=1` for exactly this.
 *
 * `reclaimable` is only what a control on screen can actually free today:
 * old versions (`DELETE /files/{file}/versions/{version}`) and the Trash
 * (`DELETE /files/trash`). Duplicates belong here too and are deliberately
 * absent — `files.checksum` is only populated for Drive imports, so the
 * number would be wrong for everyone else. Reporting a figure no button
 * can act on is how "you can free up 340 MB" stops being true.
 */
class StorageController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $versions = (int) FileVersion::query()
            ->whereIn('file_id', $user->files()->withTrashed()->select('id'))
            ->sum('size_bytes');

        $trashed = (int) $user->files()->onlyTrashed()->sum('size_bytes');

        return response()->json([
            'data' => [
                'used' => PlanQuota::storageUsed($user),
                // null = no ceiling, same convention as every other quota.
                'limit' => PlanQuota::storageLimit($user),
                'reclaimable' => [
                    'versions' => $versions,
                    'trash' => $trashed,
                ],
            ],
        ]);
    }
}
