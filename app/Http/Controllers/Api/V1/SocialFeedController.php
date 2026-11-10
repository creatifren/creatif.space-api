<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Support\Workspace;
use App\Support\SocialProviders;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * One account's feed as the platform holds it, with metrics — Analytics
 * reads totals and top posts from it, Grid the posts made outside Creatif
 * Space. Read through, cached briefly: the platforms rate-limit and the
 * numbers move slowly.
 *
 * ponytail: no daily snapshot table, so there is no history beyond what the
 * platform returns today. Add a scheduled snapshot when Analytics needs a
 * trend line older than the feed.
 */
class SocialFeedController extends Controller
{
    /**
     * Each platform names its numbers differently; these are the six every
     * screen can read, first match wins.
     */
    private const METRICS = [
        'views' => ['views', 'view_count', 'video_views', 'media_views', 'impressions'],
        'likes' => ['likes', 'like_count', 'reactions_total', 'favorites', 'engagement_likes'],
        'comments' => ['comments', 'comment_count', 'replies'],
        'shares' => ['shares', 'share_count', 'quotes'],
        'saves' => ['saved', 'saves'],
        'reach' => ['reach'],
    ];

    public function show(Request $request, SocialAccount $socialAccount, SocialProviders $providers): JsonResponse
    {
        // A team member reads the workspace's feeds, as in the rest of Social.
        $workspace = Workspace::memberIds(Workspace::owner($request->user()));
        Gate::allowIf(fn () => $workspace->contains($socialAccount->user_id));

        $cursor = $request->string('cursor')->toString() ?: null;
        $key = "social-feed:{$socialAccount->id}:".($cursor ?? 'first');

        try {
            $feed = Cache::remember($key, now()->addMinutes(10), fn () => $providers->for($socialAccount)->feed(
                $socialAccount->provider_account_id,
                $cursor,
            ));
        } catch (RequestException $e) {
            // Mostly: the account was connected without the feeds permission,
            // or the platform has no feed API. Say so; the screen keeps working.
            return response()->json([
                'message' => 'This account does not share its posts with us yet — reconnect it to allow it.',
            ], $e->response->status() === 404 ? 404 : 409);
        }

        $posts = SocialPost::query()->whereIn('user_id', $workspace);
        $ours = (clone $posts)->pluck('ulid')->flip();
        // A relay that keeps no external id (Outstand) names its own post id.
        $byProviderId = (clone $posts)->whereNotNull('provider_post_id')
            ->pluck('ulid', 'provider_post_id');

        return response()->json([
            'data' => array_map(fn (array $p) => [
                'id' => (string) ($p['platform_post_id'] ?? ''),
                'caption' => (string) ($p['caption'] ?? ''),
                'url' => $p['platform_url'] ?? null,
                'posted_at' => $p['posted_at'] ?? null,
                'media' => $this->mediaUrls($p['media'] ?? []),
                // Made through us when the provider echoes our post ulid back.
                'post_id' => isset($p['external_post_id'], $ours[$p['external_post_id']])
                    ? $p['external_post_id']
                    : $byProviderId->get((string) ($p['provider_post_id'] ?? '')),
                'metrics' => $this->metrics((array) ($p['metrics'] ?? [])),
            ], $feed['data']),
            'meta' => ['next_cursor' => $feed['cursor']],
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, int|null>
     */
    private function metrics(array $raw): array
    {
        // X nests its counts one level down.
        $flat = $raw + (array) ($raw['public_metrics'] ?? []);
        $out = [];

        foreach (self::METRICS as $name => $keys) {
            $out[$name] = null;
            foreach ($keys as $k) {
                if (isset($flat[$k]) && is_numeric($flat[$k])) {
                    $out[$name] = (int) $flat[$k];
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $media
     * @return array<int, string>
     */
    private function mediaUrls(array $media): array
    {
        return array_values(array_filter(array_map(
            fn ($m) => is_array($m) ? ($m['thumbnail_url'] ?? $m['url'] ?? null) : (is_string($m) ? $m : null),
            $media,
        )));
    }
}
