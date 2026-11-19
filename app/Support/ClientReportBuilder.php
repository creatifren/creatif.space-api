<?php

namespace App\Support;

use App\Models\ClientReport;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Support\Insight\InsightCollector;
use App\Support\Insight\InsightPeriod;
use App\Support\Insight\InsightSummary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The monthly client report (prototype v7 `R.report`, decisions › Monthly
 * report): period, prepared by, the client's accounts with platform and
 * username, KPIs vs last month, weekly reach, a per-account table, top
 * posts, content types, an editable summary and a data-source note.
 *
 * The numbers are Insight's (InsightCollector, cached per period); this
 * class only scopes them to the client's accounts and adds what a client
 * report has on top. Shared accounts are reported whole and marked.
 */
final class ClientReportBuilder
{
    public function __construct(private InsightCollector $collector) {}

    /** @return array<string, mixed> */
    public function build(ClientReport $report, bool $withSummary = true): array
    {
        $owner = $report->user;
        $label = $report->clientLabel;
        $period = InsightPeriod::month($report->month);
        $accounts = $this->accounts($report);

        $data = $accounts->isEmpty() ? null : InsightSummary::present($this->collector->collectCached($owner, $accounts, $period));
        $shared = $label === null ? [] : DB::table('client_label_social_account')
            ->whereIn('social_account_id', $accounts->pluck('id'))
            ->where('client_label_id', '!=', $label->id)
            ->pluck('social_account_id')->unique()->all();

        $out = [
            'id' => $report->ulid,
            'month' => $report->month,
            'period' => ['from' => $period->from->toDateString(), 'to' => $period->to->toDateString(), 'label' => $period->label('en')],
            'client' => $label?->toBadge(),
            'prepared_by' => $owner->report_from_name ?: $owner->name,
            'accounts' => $accounts->map(fn (SocialAccount $a) => [
                'id' => $a->ulid,
                'platform' => $a->platform->value,
                'username' => $a->username,
                'shared' => in_array($a->id, $shared, true),
            ])->values()->all(),
            'kpis' => $data === null ? null : [
                'current' => $data['totals'],
                'previous' => $data['totals_previous'],
                'change_pct' => $data['change_pct'],
                'compared_with' => $data['compared_with'],
            ],
            'weekly_reach' => $data['weekly_reach'] ?? null,
            'by_account' => $data['channels'] ?? [],
            'top_posts' => array_slice($data['top_posts'] ?? [], 0, 5),
            'content_types' => $this->contentTypes($report, $accounts, $period),
            'summary' => $report->summary,
            'summary_edited' => $report->summary_edited_at !== null,
            'data_source' => 'Numbers come from each network\'s own statistics for these accounts, read by Creatif Space'
                .($shared === [] ? '.' : '. Accounts shared with another client are reported whole.'),
            'notes' => $data['notes'] ?? [],
            'visibility' => $report->visibility,
            'url' => $report->url(),
            'sent_at' => $report->sent_at?->toIso8601String(),
            'sent_to' => $report->sent_to,
            'open_count' => $report->open_count,
        ];

        if ($withSummary && $report->summary === null && $data !== null) {
            $out['summary'] = $this->summarise($report, $out);
        }

        return $out;
    }

    /** @return Collection<int, SocialAccount> */
    public function accounts(ClientReport $report): Collection
    {
        return $report->clientLabel?->accounts()->orderBy('platform')->get() ?? collect();
    }

    /**
     * What kinds of posts went out for the client this month (our posts on
     * the client's accounts): Reels / Carousel / Photo / Text, with counts.
     *
     * @param  Collection<int, SocialAccount>  $accounts
     * @return list<array{type: string, posts: int}>
     */
    private function contentTypes(ClientReport $report, Collection $accounts, InsightPeriod $period): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }
        $posts = SocialPost::query()
            ->where('user_id', $report->user_id)
            ->where('status', 'published')
            ->whereBetween('scheduled_at', [$period->from->startOfDay()->utc(), $period->to->endOfDay()->utc()])
            ->whereHas('targets', fn ($q) => $q->whereIn('social_account_id', $accounts->pluck('id')))
            ->get(['id', 'type', 'media']);

        $kinds = ['Reels' => 0, 'Carousel' => 0, 'Photo' => 0, 'Text' => 0];
        foreach ($posts as $p) {
            $media = is_array($p->media) ? $p->media : [];
            $kind = $p->type === 'reels' ? 'Reels' : (count($media) > 1 ? 'Carousel' : (count($media) === 1 ? 'Photo' : 'Text'));
            $kinds[$kind]++;
        }

        return array_map(fn ($type, $n) => ['type' => $type, 'posts' => $n], array_keys($kinds), $kinds);
    }

    /**
     * The summary paragraph, written once and stored: Claude (services.ai)
     * when it is set up, else a plain sentence from the numbers. The owner
     * can edit it before it goes out.
     *
     * @param  array<string, mixed>  $report
     */
    private function summarise(ClientReport $row, array $report): string
    {
        $text = $this->aiSummary($report) ?? self::plainSummary($report);
        $row->forceFill(['summary' => $text])->save();

        return $text;
    }

    /** @param  array<string, mixed>  $report */
    public static function plainSummary(array $report): string
    {
        $cur = $report['kpis']['current'] ?? [];
        $pct = $report['kpis']['change_pct'] ?? [];
        $reach = (int) ($cur['reach'] ?? 0);
        $posts = (int) ($cur['posts'] ?? 0);
        $change = $pct['reach'] ?? null;
        $trend = $change === null ? '' : ($change >= 0 ? sprintf(', up %d%% on last month', (int) round($change * 100)) : sprintf(', down %d%% on last month', (int) round(-$change * 100)));

        return sprintf('%s: %d %s reached %s people%s. Next month we keep what worked best and plan the content ahead.',
            $report['period']['label'], $posts, $posts === 1 ? 'post' : 'posts', number_format($reach), $trend);
    }

    /** @param  array<string, mixed>  $report */
    private function aiSummary(array $report): ?string
    {
        $config = config('services.ai');
        if (! ($config['key'] ?? null) || ! ($config['base_url'] ?? null) || ! ($config['model'] ?? null)) {
            return null;
        }
        $facts = json_encode([
            'period' => $report['period']['label'],
            'client' => $report['client']['name'] ?? null,
            'kpis' => $report['kpis'],
            'top_posts' => array_map(fn ($p) => ['network' => $p['network'] ?? null, 'caption' => $p['caption'] ?? null, 'engagement' => $p['engagement'] ?? null], $report['top_posts']),
            'content_types' => $report['content_types'],
        ]);

        try {
            $response = Http::withHeaders(['x-api-key' => $config['key'], 'anthropic-version' => '2023-06-01'])
                ->timeout(30)
                ->post(rtrim($config['base_url'], '/').'/messages', [
                    'model' => $config['model'],
                    'max_tokens' => 400,
                    'system' => 'You write the short summary of a social media manager\'s monthly report to their client. Plain, warm English; 3–4 sentences; what went well, what to watch, and one concrete idea for next month. Only use the numbers given; no hashtags, no emojis, no headings.',
                    'messages' => [['role' => 'user', 'content' => (string) $facts]],
                ]);
            $text = trim(collect((array) $response->json('content'))->where('type', 'text')->pluck('text')->implode(''));

            return $response->successful() && $text !== '' ? $text : null;
        } catch (Throwable $e) {
            Log::warning('client_report.summary_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
