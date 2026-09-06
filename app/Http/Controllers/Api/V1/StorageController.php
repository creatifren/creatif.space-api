<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\File;
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
 * old versions (`DELETE /files/{file}/versions/{version}`), the Trash
 * (`DELETE /files/trash`), and duplicates (`DELETE /files/{file}` on the
 * extra copies). Reporting a figure no button can act on is how "you can
 * free up 340 MB" stops being true.
 *
 * The duplicate figure counts the *copies*, never the original: N files
 * with the same bytes can free (N-1) × size. Files with no checksum are
 * simply not in the count — a multipart upload has no MD5 and pre-2026-09
 * rows are still being backfilled, and guessing there would mean offering
 * to delete something that is not a duplicate.
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

        /* Grouped in SQL, not in PHP: an account with 20k files should not
           pull 20k rows to count them. Served by the (user_id, checksum)
           index. Trashed rows are excluded — their bytes are already
           counted under `trash`, and offering the same megabyte twice
           would make the total a lie. */
        $duplicates = (int) File::query()
            ->where('user_id', $user->id)
            ->whereNotNull('checksum')
            ->groupBy('checksum')
            ->havingRaw('COUNT(*) > 1')
            // (copies - 1) × the size they share.
            ->selectRaw('(COUNT(*) - 1) * MAX(size_bytes) as waste')
            ->pluck('waste')
            ->sum();

        return response()->json([
            'data' => [
                'used' => PlanQuota::storageUsed($user),
                // null = no ceiling, same convention as every other quota.
                'limit' => PlanQuota::storageLimit($user),
                'reclaimable' => [
                    'versions' => $versions,
                    'trash' => $trashed,
                    'duplicates' => $duplicates,
                ],
            ],
        ]);
    }
}
