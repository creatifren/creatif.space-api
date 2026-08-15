<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SpaceEventType;
use App\Http\Controllers\Controller;
use App\Models\Space;
use App\Models\SpaceEvent;
use App\Models\SpaceItem;
use App\Notifications\SpaceOpened;
use App\Support\CrawlerAgents;
use App\Support\SpaceUnlockToken;
use App\Support\VisitorHash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The analytics beacon. Called from the visitor's browser, never from the
 * Next.js server — that is the whole reason it exists as its own route.
 *
 * Recording inside PublicSpaceController::show would be wrong three ways:
 * that endpoint is fetched by the Next server (so every row would carry the
 * server's IP as the visitor), it is fetched twice per page load
 * (generateMetadata, then the page), and it is fetched a third time when a
 * Space has approval on. Traffic would be inflated by a factor that depends
 * on which features the creator turned on.
 *
 * Always answers 204. A beacon must never be the thing that makes a page
 * look broken.
 */
class SpaceEventController extends Controller
{
    public function __invoke(Request $request, Space $space): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', SpaceEventType::beaconable())],
            'item' => ['sometimes', 'nullable', 'string', 'size:26'],
            'st' => ['sometimes', 'nullable', 'string'],
        ]);

        // Same gates as the viewer itself: nothing counts for a Space that
        // is not published, is expired, or is still behind its password.
        abort_unless($space->isPublished() && ! $space->isExpired(), 404);

        if (
            $space->password_hash !== null
            && ! SpaceUnlockToken::verify($space, $validated['st'] ?? null)
        ) {
            abort(404);
        }

        $crawler = CrawlerAgents::detect($request->userAgent());
        $hash = VisitorHash::for($request);

        // No middleware on this route on purpose: whoever opens a Space link
        // is a stranger, and requiring auth:client would silence the beacon
        // for almost everyone. The guard still resolves from the session
        // cookie when the visitor happens to be a signed-in client — the
        // same trick PublicSpaceResource::approvalBlock() already uses.
        $client = $request->user('client');

        $type = SpaceEventType::from($validated['type']);

        $isFirstView = $type === SpaceEventType::View
            && ! $crawler['is_crawler']
            && ! $this->seenToday($space, $hash);

        SpaceEvent::query()->create([
            'space_id' => $space->id,
            'type' => $type,
            'visitor_hash' => $hash,
            'client_id' => $client?->id,
            'space_item_id' => $this->itemId($space, $validated['item'] ?? null),
            'referrer_host' => $this->referrerHost($request),
            'is_ai_crawler' => $crawler['is_crawler'],
            'crawler_name' => $crawler['name'],
        ]);

        if ($isFirstView) {
            $this->tellTheOwner($space, $client?->user_id);
        }

        $this->stampDelivery($space, $type, $crawler['is_crawler']);

        return response()->json(null, 204);
    }

    /**
     * The delivery log's milestones, written onto the Space itself.
     *
     * The raw event above answers the same question, but PruneSpaceEvents
     * drops it after 90 days and the log promises a record that outlives the
     * Space entirely. So the first open and the first download are copied to
     * columns that nothing prunes.
     *
     * First-write-wins on both: the card asks when the work was delivered,
     * not how many times it was looked at. Crawlers never count — GPTBot
     * fetching a page is not a client opening it.
     */
    private function stampDelivery(Space $space, SpaceEventType $type, bool $isCrawler): void
    {
        if ($isCrawler) {
            return;
        }

        if ($type === SpaceEventType::View && $space->first_opened_at === null) {
            $space->forceFill(['first_opened_at' => now()])->save();

            return;
        }

        if ($type === SpaceEventType::Download && $space->first_downloaded_at === null) {
            $space->forceFill([
                'first_downloaded_at' => now(),
                // How much was taken, so the line can read "24 files · 13 Jul".
                'downloaded_files' => $space->items()->count(),
            ])->save();
        }
    }

    /**
     * Has this browser already been counted for this Space today? The bell
     * fires on the first visit of a day, not on every page refresh.
     */
    private function seenToday(Space $space, string $hash): bool
    {
        return SpaceEvent::query()
            ->where('space_id', $space->id)
            ->where('visitor_hash', $hash)
            ->where('type', SpaceEventType::View)
            ->where('created_at', '>=', now()->subDay())
            ->exists();
    }

    /**
     * "Someone opened your Space." Skipped when the visitor is the creator
     * looking at their own work — being told about yourself is noise.
     */
    private function tellTheOwner(Space $space, ?int $viewerUserId): void
    {
        $owner = $space->user;

        if ($owner === null || $owner->id === $viewerUserId) {
            return;
        }

        $owner->notify(new SpaceOpened($space));
    }

    /**
     * The item a lightbox_open or download refers to, when it belongs to
     * this Space. A ulid from somewhere else is dropped, not an error —
     * the beacon never argues with the page.
     */
    private function itemId(Space $space, ?string $ulid): ?int
    {
        if ($ulid === null) {
            return null;
        }

        return SpaceItem::query()
            ->where('space_id', $space->id)
            ->where('ulid', $ulid)
            ->value('id');
    }

    /**
     * Where they came from, as a bare host. Null is a real answer — it is
     * the "Direct link" bucket, and usually the biggest one.
     */
    private function referrerHost(Request $request): ?string
    {
        $referer = $request->header('referer');

        if (! is_string($referer) || $referer === '') {
            return null;
        }

        $host = parse_url($referer, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return substr(str_starts_with($host, 'www.') ? substr($host, 4) : $host, 0, 255);
    }
}
