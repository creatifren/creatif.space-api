<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialAutoDm;
use App\Models\SocialPost;
use App\Support\AutoDm;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * v10.5 Auto DM on comment, per post (Compose card + Post page row).
 * GET / PUT / DELETE /social-posts/{post}/auto-dm. Any status: the rule
 * only matters once the post is live. Saving again resumes a paused rule.
 */
class AutoDmController extends Controller
{
    public function show(Request $request, SocialPost $socialPost): JsonResponse
    {
        Gate::allowIf(fn ($user) => Workspace::contains($user, $socialPost->user_id));

        return response()->json(['data' => self::present($socialPost)]);
    }

    public function update(Request $request, SocialPost $socialPost): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);
        abort_unless(Workspace::canWrite($request->user()), 403, 'Viewers cannot change Auto DM.');
        PlanQuota::requireCommentToDm($request->user(), 'keyword');
        $v = $request->validate([
            'keyword' => ['required', 'string', 'max:40', 'regex:/\S/'],
            'mode' => ['required', 'in:'.implode(',', AutoDm::MODES)],
            'message' => ['required', 'string', 'max:1000', 'regex:/\S/'],
        ]);
        $socialPost->forceFill(['auto_dm' => [
            'keyword' => trim($v['keyword']),
            'mode' => $v['mode'],
            'message' => trim($v['message']),
            'paused_at' => null,
        ]])->save();

        return response()->json(['data' => self::present($socialPost)]);
    }

    public function destroy(Request $request, SocialPost $socialPost): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);
        $socialPost->forceFill(['auto_dm' => null])->save();

        return response()->json(['data' => self::present($socialPost)]);
    }

    /** @return array<string, mixed> */
    public static function present(SocialPost $post): array
    {
        $rule = $post->auto_dm;

        return [
            'on' => is_array($rule),
            'keyword' => $rule['keyword'] ?? null,
            'mode' => $rule['mode'] ?? null,
            'message' => $rule['message'] ?? null,
            'credits_per_dm' => is_array($rule) ? AutoDm::price((string) $rule['mode']) : null,
            'paused' => ! empty($rule['paused_at']),
            'sent' => $post->autoDms()->where('status', SocialAutoDm::SENT)->count(),
        ];
    }
}
