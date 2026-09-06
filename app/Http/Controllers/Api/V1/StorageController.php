<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\File;
use App\Models\FileVersion;
use App\Support\PlanQuota;
use App\Support\Workspace;
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
    /** Rows returned per group on the scan — the screen is a list, not a table. */
    public const SCAN_LIMIT = 50;

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

    /**
     * The Free up screen: what can go, itemised, so a person can pick.
     *
     * Three groups, each already deletable through a route that exists —
     * the Trash (`DELETE /files/trash/{ulid}`), old versions
     * (`DELETE /files/{file}/versions/{version}`) and duplicate copies
     * (`DELETE /files/{file}`). There is no "reclaim these ids" endpoint
     * and deliberately so: one that deleted across three kinds of thing at
     * once would be the only place in the API where a single call removes a
     * live file, a history entry and a binned one together.
     */
    public function scan(Request $request): JsonResponse
    {
        $user = Workspace::owner($request->user());

        $trash = $user->files()->onlyTrashed()
            ->with('folder')
            ->orderByDesc('size_bytes')
            ->limit(self::SCAN_LIMIT)
            ->get()
            ->map(fn (File $f) => [
                'id' => $f->ulid,
                'kind' => 'trash',
                'name' => $f->name,
                'size_bytes' => (int) $f->size_bytes,
                'from' => $f->folder?->name,
                'purge_at' => $f->purge_at,
            ]);

        $versions = FileVersion::query()
            ->whereIn('file_id', $user->files()->withTrashed()->select('id'))
            ->with('file:id,ulid,name')
            ->orderByDesc('size_bytes')
            ->limit(self::SCAN_LIMIT)
            ->get()
            ->map(fn (FileVersion $v) => [
                'id' => $v->ulid,
                'kind' => 'version',
                'name' => $v->file?->name,
                'size_bytes' => (int) $v->size_bytes,
                /* The version delete route is nested under its file, so the
                   client needs both ids to act on the row. */
                'file_id' => $v->file?->ulid,
                'number' => $v->number,
            ]);

        return response()->json(['data' => [
            'trash' => $trash,
            'versions' => $versions,
            'duplicates' => $this->duplicateRows($user),
        ]]);
    }

    /**
     * One row per extra copy. The keeper is the oldest — whatever pointed
     * at a file first is the one most likely to still point at it — and it
     * is named on every row so the screen can say what survives.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function duplicateRows(\App\Models\User $user): \Illuminate\Support\Collection
    {
        $checksums = File::query()
            ->where('user_id', $user->id)
            ->whereNotNull('checksum')
            ->groupBy('checksum')
            ->havingRaw('COUNT(*) > 1')
            ->limit(self::SCAN_LIMIT)
            ->pluck('checksum');

        if ($checksums->isEmpty()) {
            return collect();
        }

        return File::query()
            ->where('user_id', $user->id)
            ->whereIn('checksum', $checksums)
            ->with('folder')
            ->orderBy('checksum')
            ->orderBy('id')
            ->get()
            ->groupBy('checksum')
            ->flatMap(function ($copies) {
                $keeper = $copies->first();

                return $copies->skip(1)->map(fn (File $f) => [
                    'id' => $f->ulid,
                    'kind' => 'duplicate',
                    'name' => $f->name,
                    'size_bytes' => (int) $f->size_bytes,
                    'from' => $f->folder?->name,
                    'keeping' => $keeper->folder?->name ?? 'Your files',
                ]);
            })
            ->values();
    }
}
