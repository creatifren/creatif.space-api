<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GET /me/onboarding — Today's "Get started" checklist, from the data itself
 * (the same facts as the first_* milestones, never from analytics):
 *
 *   connect  a channel connected   (first social account row)
 *   draft    a post made           (first post, draft or queued)
 *   send     sent to a client      (first approval link)
 *   publish  a post published      (first published destination)
 *
 * Each step is {done, at}: `at` is when it first happened (ISO 8601) or
 * null. `draft_post_id` is the newest draft, so the app can open the exact
 * post for "Send to a client" and "Publish"; null when there is none.
 *
 * `publish.at` is when the first published destination's row last changed
 * (social_post_targets has no published_at of its own).
 *
 * Counted across the workspace (the owner and active members), as the app
 * shows it. Five indexed queries, each a MIN or a single row.
 */
class OnboardingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $ids = Workspace::memberIds(Workspace::owner($request->user()))->all();

        $connect = SocialAccount::query()->whereIn('user_id', $ids)->min('created_at');
        $draft = SocialPost::query()->whereIn('user_id', $ids)->min('created_at');
        $send = SocialApprovalLink::query()->whereIn('user_id', $ids)->min('created_at');
        $publish = SocialPostTarget::query()
            ->where('status', SocialPostTargetStatus::Published)
            ->whereHas('post', fn ($q) => $q->whereIn('user_id', $ids))
            ->min('updated_at');

        $latestDraft = SocialPost::query()
            ->whereIn('user_id', $ids)
            ->where('status', SocialPostStatus::Draft)
            ->latest('created_at')
            ->latest('id')
            ->value('ulid');

        return response()->json(['data' => [
            'connect' => self::step($connect),
            'draft' => self::step($draft),
            'send' => self::step($send),
            'publish' => self::step($publish),
            'draft_post_id' => is_string($latestDraft) ? $latestDraft : null,
        ]]);
    }

    /** @return array{done: bool, at: string|null} */
    private static function step(mixed $first): array
    {
        if ($first === null || $first === '') {
            return ['done' => false, 'at' => null];
        }

        return ['done' => true, 'at' => Carbon::parse((string) $first, config('app.timezone'))->toIso8601String()];
    }
}
