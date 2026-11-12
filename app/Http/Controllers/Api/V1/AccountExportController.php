<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\BuildAccountExport;
use App\Models\AccountExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Account data export. POST /me/export queues a ZIP (BuildAccountExport)
 * and the user gets an email with a 24-hour signed link. One running
 * export per user (409 otherwise). Signed-in session only: no MCP tool,
 * and EnforceTokenScope refuses tokens (the route is not in mcp.routes) —
 * a whole-account copy is not something to hand an AI client.
 */
class AccountExportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // The check and the insert under one lock: two clicks make one export.
        $export = Cache::lock("account-export:{$user->id}", 10)->block(5, function () use ($user) {
            $running = AccountExport::query()->where('user_id', $user->id)->running()->latest('id')->first();
            if ($running !== null) {
                return [$running, false];
            }

            return [AccountExport::create(['user_id' => $user->id, 'status' => AccountExport::STATUS_QUEUED]), true];
        });
        [$row, $created] = $export;

        if (! $created) {
            return response()->json([
                'message' => 'An export is already being prepared. You will get an email when it is ready.',
                'reason' => 'export_running',
                'data' => $row->toApi(),
            ], 409);
        }

        BuildAccountExport::dispatch($row->id);

        return response()->json(['data' => $row->toApi()], 202);
    }

    /** GET /me/export — the latest export's status (no link: that goes by email). */
    public function show(Request $request): JsonResponse
    {
        $row = AccountExport::query()->where('user_id', $request->user()->id)->latest('id')->first();

        return response()->json(['data' => $row?->toApi()]);
    }

    /** GET /me/export/{export}/download?signature= — the emailed link (route middleware: signed). */
    public function download(string $export): StreamedResponse
    {
        $row = AccountExport::query()->where('ulid', $export)->first();
        abort_if($row === null || ! $row->available(), 404);

        return Storage::disk(AccountExport::DISK)->download((string) $row->path, "creatif-space-export-{$row->ulid}.zip", [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store',
        ]);
    }
}
