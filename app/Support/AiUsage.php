<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * One log line per AI answer with the tokens the API reports (AI cost per
 * tap vs its Credits price — Rizal 21 Nov). Read them back with
 * `grep "ai usage" storage/logs/ai-*.log` (own channel `ai`, 90 days). Prices per million tokens
 * (input / output, Nov 2026): Sonnet 5.5 $2 / $10, Opus 5.5 $4 / $20;
 * 1 Credit = $0.0001.
 */
class AiUsage
{
    private const PRICES = [
        'sonnet' => [2.0, 10.0],
        'opus' => [4.0, 20.0],
        'haiku' => [1.0, 5.0],
    ];

    /** Dollars for one answer; null when the model's price is unknown. */
    public static function dollars(string $model, int $in, int $out): ?float
    {
        foreach (self::PRICES as $name => [$pin, $pout]) {
            if (str_contains($model, $name)) {
                return ($in * $pin + $out * $pout) / 1_000_000;
            }
        }

        return null;
    }

    /** @param  mixed  $usage  the response's `usage` object */
    public static function log(string $feature, string $model, mixed $usage, ?int $credits = null): void
    {
        $usage = is_array($usage) ? $usage : [];
        $in = (int) ($usage['input_tokens'] ?? 0);
        $out = (int) ($usage['output_tokens'] ?? 0);
        $usd = self::dollars($model, $in, $out);

        Log::channel('ai')->info('ai usage', array_filter([
            'feature' => $feature,
            'model' => $model,
            'in' => $in,
            'out' => $out,
            'cost_credits' => $usd === null ? null : round($usd * 10_000, 1),
            'price_credits' => $credits,
        ], fn ($v) => $v !== null));
    }
}
