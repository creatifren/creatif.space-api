<?php

namespace App\Support\Insight;

use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Workspace;
use Illuminate\Support\Collection;

/**
 * One Insight PDF, from a workspace, its channels and a period: collect →
 * thumbnails → Blade → Dompdf. Shared by the queued report
 * (BuildInsightReport) and the direct download (GET /insight/export.pdf,
 * and its signed link for connected apps).
 *
 * The plan is the caller's to check (requireFeature / hasFeature): this
 * class only builds.
 */
class InsightPdfBuilder
{
    /**
     * A direct download waits for this; thumbnails not fetched by then are
     * left as empty tiles rather than holding the request.
     */
    public const SYNC_THUMBNAIL_SECONDS = 6.0;

    public function __construct(
        private InsightCollector $collector,
        private InsightRenderer $renderer,
        private InsightImages $images,
    ) {}

    /**
     * The workspace's channels by ULID, in the order given and still in the
     * workspace (any status: a lost channel still prints, as "—").
     *
     * @param  list<string>  $ulids
     * @return Collection<int, SocialAccount>
     */
    public static function accounts(User $owner, array $ulids): Collection
    {
        return SocialAccount::query()
            ->whereIn('user_id', Workspace::memberIds($owner))
            ->whereIn('ulid', $ulids)
            ->get()
            ->sortBy(fn (SocialAccount $a) => array_search($a->ulid, $ulids, true))
            ->values();
    }

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  array{locale: string, branding: bool, notes?: string|null}  $options
     * @return array{pdf: string, pages: int}
     */
    public function build(User $owner, Collection $accounts, InsightPeriod $period, string $format, array $options, ?float $thumbnailBudget = null): array
    {
        $data = $this->collector->collect($owner, $accounts, $period);

        $started = microtime(true);
        $thumbnails = [];
        foreach ($data['thumbnail_sources'] as $key => $candidates) {
            $thumbnails[$key] = $thumbnailBudget !== null && microtime(true) - $started > $thumbnailBudget
                ? null
                : $this->images->firstImage($candidates);
        }

        $view = InsightView::build($data, [
            'client_name' => InsightClients::name($owner),
            'locale' => $options['locale'],
            // The API holds no workspace logo yet, and an avatar is not a
            // logo: the slot stays empty (hidden) until one exists.
            'logo' => null,
            'branding' => $options['branding'],
            'notes' => $options['notes'] ?? null,
            'generated_at' => now(),
            'thumbnails' => $thumbnails,
        ]);

        return $this->renderer->render($format, $view);
    }
}
