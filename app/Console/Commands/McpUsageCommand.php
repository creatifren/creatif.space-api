<?php

namespace App\Console\Commands;

use App\Models\McpAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The daily launch check for the MCP server: calls per tool, error rate and
 * the top error codes over the last N days, from mcp_audit_logs (one row per
 * API request a connected app or API key made). Aggregates only — never a
 * user, token, IP, client name or content.
 *
 * Latency: p50 / p95 per tool over the last 24 hours, from duration_ms (the
 * API's own time to answer, written by EnforceTokenScope; rows from before
 * that column existed have none and are left out).
 *
 * Counts API requests, not tool calls: a tool can make more than one
 * (get_storage_usage reads several pages), and calls refused inside the MCP
 * server (bad arguments, missing scope) never reach the API.
 */
class McpUsageCommand extends Command
{
    protected $signature = 'mcp:usage
        {--days=7 : How many days back (1–90; audit rows are kept 90 days)}
        {--json : Print JSON instead of tables}';

    protected $description = 'MCP tool usage: API calls per tool, error rate, top error codes and p50/p95 latency (aggregates only, no user data)';

    /** What an HTTP status means to the MCP model — the codes toToolError gives. */
    private const CODES = [
        400 => 'invalid_input',
        401 => 'unauthorized',
        402 => 'plan_required',
        403 => 'forbidden_or_scope',
        404 => 'not_found',
        409 => 'conflict',
        422 => 'invalid_input',
        429 => 'rate_limited',
    ];

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 90) {
            $this->error('--days must be a whole number from 1 to 90.');

            return self::INVALID;
        }

        $since = now()->subDays($days);
        $rows = McpAuditLog::query()
            ->where('created_at', '>=', $since)
            ->groupBy('tool', 'status')
            ->selectRaw('tool, status, COUNT(*) as n')
            ->toBase()
            ->get()
            ->map(fn (object $r): array => [
                'tool' => is_string($r->tool ?? null) ? $r->tool : '(no tool)',
                'status' => (int) ($r->status ?? 0),
                'n' => (int) ($r->n ?? 0),
            ]);

        $report = $this->report($rows, $days, $since->utc()->toIso8601ZuluString());
        $report['latency_24h'] = $this->latency();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

            return self::SUCCESS;
        }

        $this->info("MCP usage, last {$days} day(s) since {$report['since']}: {$report['calls']} API calls, {$report['errors']} errors ({$report['error_rate']}%).");
        if ($report['calls'] === 0) {
            $this->line('No MCP calls in this window.');

            return self::SUCCESS;
        }

        $this->table(
            ['Tool', 'Calls', 'Errors', 'Error rate %', 'Top errors'],
            array_map(fn (array $t) => [
                $t['tool'], $t['calls'], $t['errors'], $t['error_rate'],
                implode(', ', array_map(fn (array $e) => "{$e['code']} ({$e['status']}) ×{$e['count']}", $t['top_errors'])),
            ], $report['tools']),
        );
        $this->table(
            ['Error code', 'HTTP status', 'Count'],
            array_map(fn (array $e) => [$e['code'], $e['status'], $e['count']], $report['top_errors']),
        );
        $this->info('Latency, last 24 h (API time to answer, ms):');
        $report['latency_24h'] === []
            ? $this->line('No timed calls in the last 24 h.')
            : $this->table(
                ['Tool', 'Timed calls', 'Errors', 'p50 ms', 'p95 ms'],
                array_map(fn (array $l) => [$l['tool'], $l['calls'], $l['errors'], $l['p50_ms'], $l['p95_ms']], $report['latency_24h']),
            );
        $this->line('Counts are API requests (a tool may make several); calls refused inside the MCP server are not here.');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, array{tool: string, status: int, n: int}>  $rows
     * @return array{days: int, since: string, calls: int, errors: int, error_rate: float, tools: list<array<string, mixed>>, top_errors: list<array{code: string, status: int, count: int}>}
     */
    private function report(Collection $rows, int $days, string $since): array
    {
        $errorsOf = fn (Collection $c) => $c->filter(fn (array $r) => $r['status'] >= 400);
        $rate = fn (int $errors, int $calls) => $calls === 0 ? 0.0 : round($errors / $calls * 100, 1);
        $top = fn (Collection $c, int $n) => $errorsOf($c)
            ->groupBy('status')
            ->map(fn (Collection $g, $status) => ['code' => self::code((int) $status), 'status' => (int) $status, 'count' => (int) $g->sum('n')])
            ->sortByDesc('count')->take($n)->values()->all();

        $tools = $rows->groupBy('tool')
            ->map(function (Collection $g, string $tool) use ($errorsOf, $rate, $top) {
                $calls = (int) $g->sum('n');
                $errors = (int) $errorsOf($g)->sum('n');

                return ['tool' => $tool, 'calls' => $calls, 'errors' => $errors, 'error_rate' => $rate($errors, $calls), 'top_errors' => $top($g, 3)];
            })
            ->sortByDesc('calls')->values()->all();

        $calls = (int) $rows->sum('n');
        $errors = (int) $errorsOf($rows)->sum('n');

        return [
            'days' => $days,
            'since' => $since,
            'calls' => $calls,
            'errors' => $errors,
            'error_rate' => $rate($errors, $calls),
            'tools' => $tools,
            'top_errors' => $top($rows, 5),
        ];
    }

    /**
     * p50 / p95 (nearest rank) and error count per tool, last 24 h, timed rows only.
     *
     * @return list<array{tool: string, calls: int, errors: int, p50_ms: int, p95_ms: int}>
     */
    private function latency(): array
    {
        return McpAuditLog::query()
            ->where('created_at', '>=', now()->subDay())
            ->whereNotNull('duration_ms')
            ->toBase()
            ->get(['tool', 'status', 'duration_ms'])
            ->map(fn (object $r): array => [
                'tool' => is_string($r->tool ?? null) ? $r->tool : '(no tool)',
                'status' => (int) ($r->status ?? 0),
                'ms' => (int) ($r->duration_ms ?? 0),
            ])
            ->groupBy('tool')
            ->map(function (Collection $g, string $tool): array {
                $ms = $g->pluck('ms')->sort()->values();

                return [
                    'tool' => $tool,
                    'calls' => $ms->count(),
                    'errors' => $g->filter(fn (array $r) => $r['status'] >= 400)->count(),
                    'p50_ms' => self::percentile($ms, 50),
                    'p95_ms' => self::percentile($ms, 95),
                ];
            })
            ->sortByDesc('p95_ms')->values()->all();
    }

    /** Nearest-rank percentile of an ascending list. @param Collection<int, int> $sorted */
    private static function percentile(Collection $sorted, int $p): int
    {
        $n = $sorted->count();

        return $n === 0 ? 0 : (int) $sorted[max(0, (int) ceil($p / 100 * $n) - 1)];
    }

    private static function code(int $status): string
    {
        return self::CODES[$status] ?? ($status >= 500 ? 'upstream_unavailable' : "http_{$status}");
    }
}
