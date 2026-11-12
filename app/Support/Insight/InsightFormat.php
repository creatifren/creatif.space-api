<?php

namespace App\Support\Insight;

/**
 * Numbers as the report prints them: 950 · 24.1k · 1.2M, percentages to
 * one decimal, "—" for anything the network did not give us. Indonesian
 * uses a decimal comma (24,1k · 4,5%).
 */
final class InsightFormat
{
    public const MISSING = '—';

    public static function number(int|float|null $n, string $locale = 'en'): string
    {
        if ($n === null) {
            return self::MISSING;
        }

        $sign = $n < 0 ? '-' : '';
        $abs = abs($n);

        if ($abs < 1000) {
            return $sign.(string) (int) round($abs);
        }

        // 999 950 rounds to "1000.0k" — that is 1M.
        if ($abs < 999_950) {
            return $sign.self::decimal($abs / 1000, $locale).'k';
        }

        return $sign.self::decimal($abs / 1_000_000, $locale).'M';
    }

    /** A ratio (0.045) as "4.5%". */
    public static function percent(?float $ratio, string $locale = 'en'): string
    {
        if ($ratio === null) {
            return self::MISSING;
        }

        return self::decimal($ratio * 100, $locale, false).'%';
    }

    /** A follower change: "+120", "-3", "0". */
    public static function signed(?int $n, string $locale = 'en'): string
    {
        if ($n === null) {
            return self::MISSING;
        }

        return ($n > 0 ? '+' : '').self::number($n, $locale);
    }

    /**
     * Change against the previous period, only when both periods have a
     * number and the previous one is above zero (a % of nothing is not a
     * number). Up/down glyphs are Inter's (U+25B2 / U+25BC).
     *
     * @return array{dir: 'up'|'down'|'flat', text: string}|null
     */
    public static function delta(int|float|null $current, int|float|null $previous, string $locale = 'en'): ?array
    {
        if ($current === null || $previous === null || $previous <= 0) {
            return null;
        }

        $change = ($current - $previous) / $previous;

        if (abs($change) < 0.0005) {
            return ['dir' => 'flat', 'text' => '0'.($locale === 'id' ? ',' : '.').'0%'];
        }

        return [
            'dir' => $change > 0 ? 'up' : 'down',
            'text' => ($change > 0 ? "\u{25B2} " : "\u{25BC} ").self::decimal(abs($change) * 100, $locale, false).'%',
        ];
    }

    /** Seconds as "45 min", "5.2 h", "1.3 d" (ID: mnt / jam / hari). */
    public static function duration(?int $seconds, string $locale = 'en'): string
    {
        if ($seconds === null) {
            return self::MISSING;
        }

        $id = $locale === 'id';

        if ($seconds < 3600) {
            return max(1, (int) round($seconds / 60)).($id ? ' mnt' : ' min');
        }

        if ($seconds < 86400) {
            return self::decimal($seconds / 3600, $locale, false).($id ? ' jam' : ' h');
        }

        return self::decimal($seconds / 86400, $locale, false).($id ? ' hari' : ' d');
    }

    /** First $max characters of a caption on one line, with "…" when cut. */
    public static function caption(string $text, int $max = 80): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($flat) > $max ? rtrim(mb_substr($flat, 0, $max - 1)).'…' : $flat;
    }

    private static function decimal(float $value, string $locale, bool $trimZero = true): string
    {
        $text = number_format($value, 1, $locale === 'id' ? ',' : '.', '');

        if ($trimZero) {
            $text = (string) preg_replace('/[.,]0$/', '', $text);
        }

        return $text;
    }
}
