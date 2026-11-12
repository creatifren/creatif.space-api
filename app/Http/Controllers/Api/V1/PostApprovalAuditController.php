<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialApprovalDecision;
use App\Models\SocialPost;
use App\Models\SocialPostComment;
use App\Support\ApproverGate;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/social-posts/{id}/approval-audit — every approval decision on
 * one post, oldest first, across every link or Content Plan it was on: who
 * (name, email masked), when, the decision, the comment, and where it came
 * from. For the post detail page and MCP get_approval_history.
 *
 * The post's workspace only (ScopeToWorkspace: another workspace's id is
 * 404). The approver's email is masked (ApproverGate::hint) — the full
 * address is in the owner's audit CSV; IP and user agent are never shown.
 */
class PostApprovalAuditController extends Controller
{
    /** Older rows have no comment value: the APPROVER note written within this many seconds. */
    private const COMMENT_MATCH_SECONDS = 5;

    public function __invoke(Request $request, SocialPost $socialPost): JsonResponse
    {
        abort_unless(Workspace::contains($request->user(), $socialPost->user_id), 404);

        $decisions = SocialApprovalDecision::query()
            ->where('social_post_id', $socialPost->id)
            ->with('link:id,ulid,title,scope_from')
            ->orderBy('created_at')->orderBy('id')
            ->limit(500)
            ->get();

        $notes = $decisions->contains(fn (SocialApprovalDecision $d) => $d->comment === null && $d->decision === SocialApprovalDecision::CHANGES_REQUESTED)
            ? SocialPostComment::query()->where('social_post_id', $socialPost->id)->where('role', 'APPROVER')->whereNull('parent_id')->get(['body', 'created_at'])
            : collect();

        $rows = $decisions->map(function (SocialApprovalDecision $d) use ($notes) {
            $comment = $d->comment;
            if ($comment === null && $d->decision === SocialApprovalDecision::CHANGES_REQUESTED && $d->created_at !== null) {
                $comment = $notes->first(fn (SocialPostComment $c) => $c->created_at !== null
                    && abs($c->created_at->diffInSeconds($d->created_at)) <= self::COMMENT_MATCH_SECONDS)?->body;
            }

            return [
                'decided_at' => $d->created_at?->utc()->toIso8601ZuluString(),
                'decision' => $d->decision,
                'approver' => [
                    'name' => $d->client_name,
                    'email' => $d->approver_email === null ? null : ApproverGate::hint($d->approver_email),
                ],
                'comment' => $comment,
                'source' => $d->source,
                'link' => $d->link === null ? null : [
                    'id' => $d->link->ulid,
                    'title' => $d->link->title,
                    'kind' => $d->link->isScoped() ? 'plan' : 'list',
                ],
            ];
        })->values()->all();

        return response()->json(['data' => [
            'post_id' => $socialPost->ulid,
            'approval' => $socialPost->approval?->value,
            'decisions' => $rows,
        ]]);
    }
}
