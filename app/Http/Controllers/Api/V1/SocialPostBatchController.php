<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialPostStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CapTokenSends;
use App\Http\Resources\SocialPostResource;
use App\Models\SocialPost;
use App\Models\User;
use App\Support\Credits;
use App\Support\PlanQuota;
use App\Support\ScheduleHorizon;
use App\Support\SendConfirmation;
use App\Support\SocialPostRelay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Schedule up to 10 drafts at once, all or none (MCP schedule_posts).
 *
 *   POST /social-posts/send-batch {posts: [{post_id, scheduled_at, confirm_token?}]}
 *
 * Everything a single send checks is checked for every post first —
 * draft, not waiting for approval, each preview's confirm_token (from a
 * connected app or API key), plan window and queue room for all of them,
 * the relay's horizon, usable channels, today's connected-app sends for
 * all of them, and Credits for the whole batch. Only then are they sent,
 * one by one. If one is refused on the way, the ones already handed over
 * are recalled (taken back from the relay, Credits returned) and put back
 * to draft as they were: nothing stays scheduled.
 */
class SocialPostBatchController extends Controller
{
    public const MAX = 10;

    public function send(Request $request, SocialPostRelay $relay): JsonResponse
    {
        $validated = $request->validate([
            'posts' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'posts.*.post_id' => ['required', 'string', 'max:26', 'distinct'],
            'posts.*.scheduled_at' => ['required', 'date', 'after:now'],
            'posts.*.confirm_token' => ['sometimes', 'nullable', 'string', 'max:1024'],
        ]);
        /** @var User $user */
        $user = $request->user();
        /** @var list<array{post_id: string, scheduled_at: string, confirm_token?: string|null}> $rows */
        $rows = array_values($validated['posts']);
        $items = collect($rows);

        // The user's own posts only; anything else reads as not found.
        // With their owner: the relay prices and charges through $post->user
        // (lazy loading is off).
        $posts = SocialPost::query()->where('user_id', $user->id)
            ->whereIn('ulid', $items->pluck('post_id'))->with('user')->get()->keyBy('ulid');
        $missing = $items->pluck('post_id')->reject(fn ($id) => $posts->has($id))->values()->all();
        if ($missing !== []) {
            return self::refuse('not_found', 'Some of these posts do not exist in your account.', 404, ['post_ids' => $missing]);
        }

        // 1. Check everything before sending anything.
        $byToken = SendConfirmation::viaToken($request);
        $refused = [];
        foreach ($items as $i => $item) {
            $post = $posts[$item['post_id']];
            $at = Carbon::parse($item['scheduled_at'])->setTimezone((string) config('app.timezone'));
            $why = match (true) {
                $post->status !== SocialPostStatus::Draft => ['not_draft', 'Only a draft can be scheduled from here.'],
                $post->approval !== null => ['approval_required', 'This draft is waiting for approval; it cannot be scheduled until it is approved.'],
                $byToken && empty($item['confirm_token']) => ['confirm_token_required', 'Bring the confirm_token from GET /social-posts/{post}/send-preview.'],
                $byToken && ! SendConfirmation::matches($user, $post, (string) $item['confirm_token']) => ['preview_stale', 'The preview is out of date (channels or Credits changed, or 10 minutes passed).'],
                default => null,
            };
            if ($why !== null) {
                $refused[] = ['post_id' => $post->ulid, 'code' => $why[0], 'message' => $why[1]];

                continue;
            }
            PlanQuota::requireScheduleWindow($user, $at, "posts.{$i}.scheduled_at");
            ScheduleHorizon::require($post->provider, $at);
            SocialPostRelay::ensureUsable($post);
        }
        if ($refused !== []) {
            $stale = collect($refused)->every(fn ($r) => $r['code'] === 'preview_stale');

            return self::refuse(
                $stale ? 'preview_stale' : 'not_schedulable',
                'Nothing was scheduled: '.count($refused).' of these posts cannot be scheduled as they are.',
                409,
                ['refused' => $refused],
            );
        }
        PlanQuota::requireQueueRoom($user, $items->count(), 'posts');

        // The whole batch's price against the balance, before the first charge.
        $destinations = $items->flatMap(function (array $item) use ($posts) {
            $post = $posts[$item['post_id']];

            return collect(SendConfirmation::priced($post))
                ->map(fn (array $p) => [$p['target']->account->platform, $p['target']->configuration['caption'] ?? $post->caption]);
        })->all();
        $need = Credits::sendPrice($user, $destinations);
        if (Credits::balance($user) < $need) {
            return self::refuse('not_enough_credits', 'Nothing was scheduled: your Credits do not cover all of these posts.', 422, [
                'needed' => $need + Credits::monthlyBalance($user),
                'available' => Credits::total($user),
            ]);
        }

        if ($byToken && ! CapTokenSends::reserve($user, $items->count())) {
            $status = CapTokenSends::status($user);

            return self::refuse('daily_send_cap', 'Nothing was scheduled: connected apps can send '.CapTokenSends::DAILY_CAP.' posts a day, and '.$status['remaining'].' are left today.', 429, $status + [
                'resets_at' => now('UTC')->addDay()->startOfDay()->toIso8601String(),
            ]);
        }

        // 2. Send one by one; on a refusal, take back the ones already sent.
        $was = $posts->map(fn (SocialPost $p) => $p->scheduled_at);
        $sent = [];
        foreach ($items as $item) {
            $post = $posts[$item['post_id']];
            try {
                $post->update(['scheduled_at' => Carbon::parse($item['scheduled_at'])->setTimezone((string) config('app.timezone'))]);
                $relay->send($post);
                $sent[] = $post;
            } catch (Throwable $e) {
                $post->update(['scheduled_at' => $was[$post->ulid], 'status' => SocialPostStatus::Draft]);
                [$back, $stuck] = $this->rollBack($relay, $sent, $was->all());
                if ($byToken) {
                    CapTokenSends::release($user, $items->count());
                }
                Log::warning('social.batch_rolled_back', ['user' => $user->id, 'failed' => $post->ulid, 'error' => $e->getMessage(), 'stuck' => $stuck]);

                return self::refuse('batch_failed', 'Nothing was scheduled: one post was refused, so the others were taken back.', 409, [
                    'failed_post_id' => $post->ulid,
                    'reason' => $e->getMessage(),
                    'rolled_back' => $back,
                    // Taken back from us but not confirmed by the relay: shown so the user can check them.
                    'rollback_failed' => $stuck,
                ]);
            }
        }

        return response()->json(['data' => [
            'scheduled' => collect($sent)->map(fn (SocialPost $p) => new SocialPostResource($p->fresh()->load('targets.account')))->all(),
        ]]);
    }

    /**
     * @param  list<SocialPost>  $sent
     * @param  array<string, mixed>  $was  each post's scheduled_at before the batch, by ulid
     * @return array{0: list<string>, 1: list<string>}
     */
    private function rollBack(SocialPostRelay $relay, array $sent, array $was): array
    {
        $back = [];
        $stuck = [];
        foreach (array_reverse($sent) as $post) {
            try {
                $relay->recall($post, 'Batch schedule taken back');
                $post->update(['status' => SocialPostStatus::Draft, 'scheduled_at' => $was[$post->ulid] ?? null]);
                $back[] = $post->ulid;
            } catch (Throwable $e) {
                Log::error('social.batch_rollback_failed', ['post' => $post->ulid, 'error' => $e->getMessage()]);
                $stuck[] = $post->ulid;
            }
        }

        return [$back, $stuck];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private static function refuse(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code] + $extra, $status);
    }
}
