<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialAccountStatus;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Support\Insight\PostMetrics;
use App\Support\PlanQuota;
use App\Support\SocialProviders;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * GET /api/v1/insight/export.csv?from=YYYY-MM-DD&to=YYYY-MM-DD&channels=ulid,ulid
 *
 * Insight (Pro and Max; Free gets the standard 402 plan_required body):
 * every post the chosen channels published in the range, one row each, with
 * the same six metrics the feed shows. Streamed as it is read from the
 * platforms, page by page — nothing is held in memory or stored.
 *
 * Dates are whole days in the app's timezone, both ends included. No
 * `channels` = every connected channel of the workspace.
 */
class InsightExportController extends Controller
{
    /** The column order is part of the contract: append, never reorder. */
    public const COLUMNS = [
        'date', 'posted_at', 'platform', 'channel', 'post_id', 'url',
        'views', 'likes', 'comments', 'shares', 'saves', 'reach', 'caption',
    ];

    public const MAX_DAYS = 366;

    /** Feed pages read per channel, at most (25 posts a page). */
    public const MAX_PAGES = 40;

    public function __invoke(Request $request, SocialProviders $providers): StreamedResponse
    {
        PlanQuota::requireFeature($request->user(), 'insight');

        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'channels' => ['sometimes', 'nullable'],
        ]);

        $from = Date::parse($validated['from'])->startOfDay()->toImmutable();
        $to = Date::parse($validated['to'])->endOfDay()->toImmutable();

        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Export at most '.self::MAX_DAYS.' days at a time.']);
        }

        $workspace = Workspace::memberIds(Workspace::owner($request->user()));
        $accounts = $this->accounts($workspace, $validated['channels'] ?? null);

        // Posts made through us, to name our post id beside the platform's.
        $posts = SocialPost::query()->whereIn('user_id', $workspace);
        $ours = (clone $posts)->pluck('ulid')->flip();
        $byProviderId = (clone $posts)->whereNotNull('provider_post_id')->pluck('ulid', 'provider_post_id');

        $name = "insight-{$from->toDateString()}-to-{$to->toDateString()}.csv";

        return response()->streamDownload(function () use ($accounts, $providers, $from, $to, $ours, $byProviderId) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::COLUMNS, escape: '');

            foreach ($accounts as $account) {
                foreach ($this->posts($account, $providers, $from, $to) as $p) {
                    $postedAt = CarbonImmutable::parse((string) $p['posted_at']);
                    $metrics = PostMetrics::from((array) ($p['metrics'] ?? []));
                    $ourId = isset($p['external_post_id'], $ours[$p['external_post_id']])
                        ? $p['external_post_id']
                        : $byProviderId->get((string) ($p['provider_post_id'] ?? ''));

                    fputcsv($out, [
                        $postedAt->setTimezone((string) config('app.timezone'))->toDateString(),
                        $postedAt->utc()->toIso8601ZuluString(),
                        $account->platform->value,
                        self::safe($account->label()),
                        $ourId,
                        self::safe((string) ($p['platform_url'] ?? '')),
                        ...array_values($metrics),
                        self::safe((string) ($p['caption'] ?? '')),
                    ], escape: '');
                }
                flush();
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * @param  Collection<int, int>  $workspace
     * @return Collection<int, SocialAccount>
     */
    private function accounts(Collection $workspace, mixed $channels): Collection
    {
        $query = SocialAccount::query()
            ->whereIn('user_id', $workspace)
            ->where('status', SocialAccountStatus::Connected)
            ->orderBy('id');

        if ($channels === null || $channels === '' || $channels === []) {
            return $query->get();
        }

        $ulids = collect(is_array($channels) ? $channels : explode(',', (string) $channels))
            ->map(fn ($c) => trim((string) $c))->filter()->unique()->values();

        $found = $query->whereIn('ulid', $ulids)->get();

        if ($found->count() !== $ulids->count()) {
            throw ValidationException::withMessages(['channels' => 'One or more channels are not connected to this workspace.']);
        }

        return $found;
    }

    /**
     * The channel's posts inside [from, to], read page by page. Feeds come
     * newest first: once a page reaches back past `from`, the rest is older.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function posts(SocialAccount $account, SocialProviders $providers, CarbonImmutable $from, CarbonImmutable $to): \Generator
    {
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            try {
                $feed = $providers->for($account)->feed($account->provider_account_id, $cursor);
            } catch (Throwable $e) {
                // One channel that does not share its feed leaves the rest of the file intact.
                Log::warning('insight.export_feed_failed', ['account' => $account->ulid, 'error' => $e->getMessage()]);

                return;
            }

            $older = false;
            foreach ($feed['data'] as $p) {
                if (empty($p['posted_at'])) {
                    continue;
                }
                $at = CarbonImmutable::parse((string) $p['posted_at']);
                if ($at->lessThan($from)) {
                    $older = true;
                } elseif ($at->lessThanOrEqualTo($to)) {
                    yield $p;
                }
            }

            $cursor = $feed['cursor'];
            if ($older || $cursor === null) {
                return;
            }
        }
    }

    /** No cell may start a spreadsheet formula. */
    private static function safe(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
