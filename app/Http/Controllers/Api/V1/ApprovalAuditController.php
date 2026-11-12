<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialApprovalDecision;
use App\Models\SocialApprovalLink;
use App\Models\SocialPostComment;
use App\Support\Workspace;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/v1/social-approval-links/{id}/audit.csv — every decision on one
 * approval link or Content Plan, oldest first: who decided, on what post,
 * when, the decision, and the approver's comment.
 *
 * For the link's workspace (ScopeToWorkspace: another workspace's id is
 * 404). The approver's IP and user agent stay in the database: the owner
 * gets who and what, not where from.
 */
class ApprovalAuditController extends Controller
{
    public const COLUMNS = [
        'decided_at_utc', 'approver_name', 'approver_email', 'decision',
        'comment', 'post_id', 'post_caption', 'post_scheduled_at_utc', 'source',
    ];

    /** Older rows have no comment column value: the APPROVER note written within this many seconds. */
    private const COMMENT_MATCH_SECONDS = 5;

    public function __invoke(Request $request, SocialApprovalLink $socialApprovalLink): StreamedResponse
    {
        abort_unless(Workspace::contains($request->user(), $socialApprovalLink->user_id), 404);

        $decisions = $socialApprovalLink->decisions()
            ->with('post:id,ulid,caption,scheduled_at')
            ->orderBy('created_at')->orderBy('id')
            ->get();
        $notes = $this->approverNotes($decisions);

        $name = 'approval-audit-'.$socialApprovalLink->ulid.'.csv';

        return response()->streamDownload(function () use ($decisions, $notes) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::COLUMNS, escape: '');
            foreach ($decisions as $d) {
                $post = $d->post;
                fputcsv($out, array_map(self::safe(...), [
                    $d->created_at?->utc()->toIso8601ZuluString() ?? '',
                    (string) $d->client_name,
                    (string) $d->approver_email,
                    $d->decision,
                    (string) ($d->comment ?? $this->noteFor($d, $notes)),
                    (string) $post?->ulid,
                    str((string) $post?->caption)->squish()->limit(200)->toString(),
                    $post?->scheduled_at?->utc()->toIso8601ZuluString() ?? '',
                    $d->source,
                ]), escape: '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * Approver notes on the link's posts, for decisions recorded before
     * the comment column existed.
     *
     * @param  Collection<int, SocialApprovalDecision>  $decisions
     * @return Collection<array-key, EloquentCollection<int, SocialPostComment>> by post id
     */
    private function approverNotes(Collection $decisions): Collection
    {
        $postIds = $decisions
            ->filter(fn (SocialApprovalDecision $d) => $d->comment === null && $d->decision === SocialApprovalDecision::CHANGES_REQUESTED)
            ->pluck('social_post_id')->unique()->values();
        if ($postIds->isEmpty()) {
            return collect();
        }

        return SocialPostComment::query()
            ->whereIn('social_post_id', $postIds)
            ->where('role', 'APPROVER')
            ->whereNull('parent_id')
            ->get(['id', 'social_post_id', 'body', 'created_at'])
            ->groupBy('social_post_id');
    }

    /** @param  Collection<array-key, EloquentCollection<int, SocialPostComment>>  $notes */
    private function noteFor(SocialApprovalDecision $d, Collection $notes): string
    {
        if ($d->decision !== SocialApprovalDecision::CHANGES_REQUESTED || $d->created_at === null) {
            return '';
        }
        $note = $notes->get($d->social_post_id)?->first(
            fn (SocialPostComment $c) => $c->created_at !== null
                && abs($c->created_at->diffInSeconds($d->created_at)) <= self::COMMENT_MATCH_SECONDS,
        );

        return (string) $note?->body;
    }

    /** No cell may start a spreadsheet formula. */
    private static function safe(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
