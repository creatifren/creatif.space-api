<?php

namespace App\Jobs;

use App\Models\McpAuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * MCP audit log retention: rows older than mcp.audit_retention_days (90 by
 * default — the privacy page promises it) are deleted daily. Same chunked
 * shape as PruneActivityLog.
 */
class PruneMcpAuditLogs implements ShouldQueue
{
    use Queueable;

    private const CHUNK = 5000;

    private const MAX_CHUNKS = 20;

    public int $tries = 1;

    public function handle(): void
    {
        $cutoff = now()->subDays(max(1, (int) config('mcp.audit_retention_days', 90)));

        for ($i = 0; $i < self::MAX_CHUNKS; $i++) {
            $ids = McpAuditLog::query()
                ->where('created_at', '<', $cutoff)
                ->limit(self::CHUNK)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            McpAuditLog::query()->whereIn('id', $ids)->delete();
        }
    }
}
