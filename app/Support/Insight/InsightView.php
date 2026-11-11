<?php

namespace App\Support\Insight;

use Carbon\CarbonImmutable;

/**
 * Turns collected numbers into the strings a page prints — formatting,
 * comparison arrows, footnotes, translations — so the Blade templates only
 * place text and never decide anything.
 */
final class InsightView
{
    /** One page and the deck list at most this many channels in their table. */
    public const TABLE_CHANNELS = 6;

    /**
     * @param  array<string, mixed>  $data  InsightCollector::collect()
     * @param  array{client_name: string, locale: string, logo: string|null, branding: bool, notes: string|null, generated_at: CarbonImmutable, thumbnails: array<string, string|null>}  $context
     * @return array<string, mixed>
     */
    public static function build(array $data, array $context): array
    {
        $locale = $context['locale'];
        $t = fn (string $key, array $params = []) => InsightStrings::get($locale, $key, $params);
        $n = fn (int|float|null $v) => InsightFormat::number($v, $locale);

        $period = InsightPeriod::fromDates(
            CarbonImmutable::parse($data['period']['from']),
            CarbonImmutable::parse($data['period']['to']),
            $data['period']['kind'],
        );

        $cur = $data['totals']['current'];
        $prev = $data['totals']['previous'];
        $wf = $data['workflow']['current'];
        $wfPrev = $data['workflow']['previous'];
        // Zero published posts. Null (no network answered) is not "none".
        $empty = $cur['posts'] === 0;

        // A comparison needs the previous period to have published something.
        $comparable = ($prev['posts'] ?? 0) > 0;
        $delta = fn ($a, $b) => $comparable ? InsightFormat::delta($a, $b, $locale) : null;

        $kpis = [
            // Orange is the publish colour.
            ['tone' => 'orange', 'label' => $t('posts_published'), 'value' => $n($cur['posts']),
                'delta' => $delta($cur['posts'], $prev['posts']), 'sub' => null],
            ['tone' => 'white', 'label' => $t('reach'), 'value' => $n($cur['reach']),
                'delta' => $delta($cur['reach'], $prev['reach']),
                'sub' => $cur['views'] !== null ? $t('impressions').' '.$n($cur['views']) : null],
            ['tone' => 'white', 'label' => $t('engagement_rate'), 'value' => InsightFormat::percent($cur['rate'], $locale),
                'delta' => $delta($cur['rate'], $prev['rate']),
                'sub' => $t('engagement').' '.$n($cur['engagement'])],
            // Lavender is the approval colour: the workflow tile.
            ['tone' => 'lavender', 'label' => $t('posts_approved'), 'value' => $n($wf['approved']),
                'delta' => InsightFormat::delta($wf['approved'], $wfPrev['approved'], $locale),
                'sub' => $wf['avg_approval_seconds'] !== null
                    ? $t('avg_approval_short', ['time' => InsightFormat::duration($wf['avg_approval_seconds'], $locale)])
                    : null],
        ];

        $weeks = $period->weeks();
        $bars = function (array $values) use ($weeks, $t, $n, $locale): array {
            $max = max(1, ...$values);

            return array_map(fn (int $value, int $i) => [
                'label' => $t('week').' '.($i + 1),
                'range' => $weeks[$i][0]->locale($locale)->translatedFormat('j M'),
                'value' => $n($value),
                'pct' => (int) round($value / $max * 100),
            ], $values, array_keys($values));
        };
        $weekly = ! $empty && $data['weekly_reach'] !== null ? $bars($data['weekly_reach']) : null;

        $note = function (array $note) use ($locale): string {
            $params = $note['params'];
            if (isset($params['metric'])) {
                $params['metric'] = InsightStrings::get($locale, $params['metric']);
            }
            if (isset($params['date'])) {
                $params['date'] = CarbonImmutable::parse($params['date'])->locale($locale)->translatedFormat('j M Y');
            }

            return InsightStrings::get($locale, $note['key'], $params);
        };

        $channels = array_map(function (array $c) use ($n, $locale, $note, $context, $bars) {
            $cc = $c['current'];
            $cp = $c['previous'];
            $comparable = ($cp['posts'] ?? 0) > 0;
            $d = fn ($a, $b) => $comparable ? InsightFormat::delta($a, $b, $locale) : null;
            $f = $c['followers'];

            return [
                'network' => $c['network'],
                'platform' => $c['platform'],
                'username' => $c['username'],
                'available' => $c['available'],
                'posts' => $n($cc['posts']),
                'reach' => $n($cc['reach']),
                'views' => $n($cc['views']),
                'engagement' => $n($cc['engagement']),
                'rate' => InsightFormat::percent($cc['rate'], $locale),
                'likes' => $n($cc['likes']),
                'comments' => $n($cc['comments']),
                'shares' => $n($cc['shares']),
                'saves' => $n($cc['saves']),
                'followers_start' => $n($f['start']),
                'followers_has' => $f['delta'] !== null,
                'followers_end' => $n($f['end']),
                'followers_delta' => InsightFormat::signed($f['delta'], $locale),
                'deltas' => [
                    'posts' => $d($cc['posts'], $cp['posts']),
                    'reach' => $d($cc['reach'], $cp['reach']),
                    'engagement' => $d($cc['engagement'], $cp['engagement']),
                    'rate' => $d($cc['rate'], $cp['rate']),
                ],
                'empty' => $cc['posts'] === 0,
                'weekly' => $c['weekly_reach'] === null ? null : $bars($c['weekly_reach']),
                'top' => array_map(fn ($p) => self::post($p, $locale, $context['thumbnails']), $c['top']),
                'notes' => array_values(array_unique(array_map($note, $c['notes']))),
            ];
        }, $data['channels']);

        $footnotes = array_map($note, $data['footnotes']);

        $brand = resource_path('insight/brand');

        return [
            'locale' => $locale,
            'strings' => InsightStrings::STRINGS[$locale] ?? InsightStrings::STRINGS['en'],
            'client' => $context['client_name'],
            'period' => $period->label($locale),
            'previous' => InsightPeriod::fromDates(
                CarbonImmutable::parse($data['previous']['from']),
                CarbonImmutable::parse($data['previous']['to']),
                $data['period']['kind'],
            )->label($locale),
            'generated' => $t('generated', ['date' => $context['generated_at']->locale($locale)->translatedFormat('j M Y')]),
            'empty' => $empty,
            'kpis' => $kpis,
            'weekly' => $weekly,
            'channels' => $channels,
            'table' => array_slice($channels, 0, self::TABLE_CHANNELS),
            'more_channels' => max(0, count($channels) - self::TABLE_CHANNELS),
            'top' => array_map(fn ($p) => self::post($p, $locale, $context['thumbnails']), $data['top_posts']),
            'workflow' => [
                ['label' => $t('posts_approved'), 'value' => $n($wf['approved']),
                    'delta' => InsightFormat::delta($wf['approved'], $wfPrev['approved'], $locale)],
                ['label' => $t('avg_approval_time'), 'value' => InsightFormat::duration($wf['avg_approval_seconds'], $locale),
                    'delta' => null],
                ['label' => $t('on_time_rate'), 'value' => InsightFormat::percent($wf['on_time_rate'], $locale),
                    'delta' => InsightFormat::delta($wf['on_time_rate'], $wfPrev['on_time_rate'], $locale),
                    'sub' => $wf['on_time_known'] > 0 ? $wf['on_time'].' / '.$wf['on_time_known'] : null],
            ],
            'footnotes' => array_values(array_unique($footnotes)),
            'notes' => $context['notes'] !== null && trim($context['notes']) !== '' ? trim($context['notes']) : null,
            'branding' => $context['branding'],
            'logo' => $context['logo'],
            'rocket_light' => 'data:image/svg+xml;base64,'.base64_encode((string) file_get_contents($brand.'/icon-only-light.svg')),
            'rocket_dark' => 'data:image/svg+xml;base64,'.base64_encode((string) file_get_contents($brand.'/icon-only-dark.svg')),
            'fonts' => [
                'lora' => resource_path('insight/fonts/lora-600.ttf'),
                'inter' => resource_path('insight/fonts/inter-500.ttf'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, string|null>  $thumbnails
     * @return array<string, mixed>
     */
    private static function post(array $p, string $locale, array $thumbnails): array
    {
        $t = fn (string $key) => InsightStrings::get($locale, $key);
        $second = $p['reach'] !== null
            ? [$t('reach'), InsightFormat::number($p['reach'], $locale)]
            : [$t('impressions'), InsightFormat::number($p['views'], $locale)];

        return [
            'network' => $p['network'],
            'username' => $p['username'],
            'date' => CarbonImmutable::parse($p['posted_at'])->locale($locale)->translatedFormat('j M Y'),
            'caption' => $p['caption'],
            'thumb' => $p['thumb_url'] !== null ? ($thumbnails[$p['thumb_url']] ?? null) : null,
            'numbers' => [
                [$t('engagement'), InsightFormat::number($p['engagement'], $locale)],
                $second,
            ],
        ];
    }
}
