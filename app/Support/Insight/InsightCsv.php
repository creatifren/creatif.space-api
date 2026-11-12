<?php

namespace App\Support\Insight;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A small Insight CSV: header row, then the rows, streamed. No cell may
 * start a spreadsheet formula (as export.csv); numbers stay numbers, null
 * stays an empty cell — never a 0.
 */
final class InsightCsv
{
    /**
     * @param  list<string>  $columns
     * @param  list<list<scalar|null>>  $rows
     */
    public static function download(string $name, array $columns, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => is_string($v) ? self::safe($v) : $v, $row), escape: '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public static function safe(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
