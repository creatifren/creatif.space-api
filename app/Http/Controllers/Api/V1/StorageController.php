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
 * old versions, which `DELETE /files/{file}/versions/{version}` removes.
 * Trashed bytes and duplicates belong here too and are deliberately absent
 * — files are hard-deleted (no soft delete yet) and `files.checksum` is
 * only populated for Drive imports. Reporting a number no button can act
 * on is how "you can free up 340 MB" stops being true.
 */
class StorageController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $versions = (int) FileVersion::query()
            ->whereIn('file_id', $user->files()->select('id'))
            ->sum('size_bytes');

        return response()->json([
            'data' => [
                'used' => PlanQuota::storageUsed($user),
                // null = no ceiling, same convention as every other quota.
                'limit' => PlanQuota::storageLimit($user),
                'reclaimable' => [
                    'versions' => $versions,
                ],
            ],
        ]);
    }
}
