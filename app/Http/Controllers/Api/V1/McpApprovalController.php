<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialApprovalLink;
use App\Models\User;
use App\Support\ApprovalConfirmation;
use App\Support\ApproverGate;
use App\Support\PlanScope;
use App\Support\SendConfirmation;
use App\Support\SocialPostRelay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Deciding on posts sent to me, from an MCP client (Claude, ChatGPT) —
 * tools list_waiting_approvals and decide_approval, scope approvals:decide.
 *
 * Two steps, like publishing: GET .../preview shows the post and the
 * decision and hands out a confirm_token; POST .../decision with that
 * token decides, through the same path as the /a page
 * (PublicApprovalController::decideAs): the post must be in the link's
 * scope and decidable, Free's window applies, a repeat is answered with
 * where the post stands, and the audit row says source "mcp".
 *
 * Only the link's invited approver: the token's user must have a verified
 * email equal to approver_email. A link nobody has bound yet (made before
 * signed-in approvers) is decided on the /a page first — an MCP client
 * never binds a link.
 *
 * `link` is the link's id (from list_waiting_approvals) or its URL.
 */
class McpApprovalController extends Controller
{
    public function preview(Request $request, string $post): JsonResponse
    {
        $link = $this->link($request->input('link'));
        $validated = $request->validate([
            'decision' => ['required', 'in:approve,revise'],
            'reason' => ['required_if:decision,revise', 'nullable', 'string', 'max:5000'],
        ]);

        $user = $this->approver($request, $link);
        $socialPost = PlanScope::posts($link)->where('ulid', $post)->with('targets.account')->firstOrFail();
        $window = PlanScope::decidability($link, $socialPost);
        $state = PlanScope::state($socialPost);
        $already = ($validated['decision'] === 'approve' && $state === PlanScope::STATE_APPROVED)
            || ($validated['decision'] === 'revise' && $state === PlanScope::STATE_CHANGES);
        // A post with no time cannot be approved: a yes would publish it at
        // once. The sender sets a time first (PublicApprovalController).
        $needsTime = $validated['decision'] === 'approve' && ! $already && $socialPost->scheduled_at === null;
        $effect = match (true) {
            $needsTime => 'This post has no time yet, so it cannot be approved: '.$link->user->name.' has to set one first. Nothing is published.',
            $validated['decision'] === 'approve' => 'The post is approved and goes out at its scheduled time ('.$socialPost->scheduled_at?->toIso8601String().') on these channels, not at once.',
            default => 'The post goes back to '.$link->user->name.' with your reason as a comment; nothing is published.',
        };

        return response()->json(['data' => [
            'link' => ['id' => $link->ulid, 'title' => $link->title, 'editor' => $link->user->name],
            'post' => [
                'id' => $socialPost->ulid,
                'caption' => $socialPost->caption,
                'scheduled_at' => $socialPost->scheduled_at?->toIso8601String(),
                'channels' => $socialPost->targets->map(fn ($t) => [
                    'platform' => $t->account->platform->value,
                    'handle' => $t->account->username,
                ])->values()->all(),
                'media' => count((array) ($socialPost->media ?? [])),
                'state' => $state,
            ],
            'decision' => $validated['decision'],
            'reason' => $validated['reason'] ?? null,
            'decidable' => $window['decidable'],
            'decidable_from' => $window['decidable_from'],
            // Already decided this way: confirming changes nothing.
            'already' => $already,
            'needs_time' => $needsTime,
            'effect' => $effect,
            'confirm_token' => ($window['decidable'] && ! $needsTime) || $already
                ? ApprovalConfirmation::issue($user, $link, $socialPost, $validated['decision'], $validated['reason'] ?? null)
                : null,
            'confirm_expires_at' => now()->addMinutes(ApprovalConfirmation::TTL_MINUTES)->toIso8601String(),
        ]]);
    }

    public function decide(Request $request, string $post, SocialPostRelay $relay): JsonResponse
    {
        $link = $this->link($request->input('link'));
        $validated = $request->validate([
            'decision' => ['required', 'in:approve,revise'],
            'reason' => ['required_if:decision,revise', 'nullable', 'string', 'max:5000'],
            'confirm_token' => ['required', 'string', 'max:2048'],
        ]);

        $user = $this->approver($request, $link);
        $socialPost = PlanScope::posts($link)->where('ulid', $post)->firstOrFail();

        if (! ApprovalConfirmation::matches($user, $link, $socialPost, $validated['decision'], $validated['reason'] ?? null, $validated['confirm_token'])) {
            return response()->json([
                'message' => 'The post changed since the preview (or 10 minutes passed). Nothing was decided — preview it again.',
                'code' => 'preview_stale',
            ], 409);
        }

        return app(PublicApprovalController::class)->decideAs(
            $request, $link, $post, $user,
            ['decision' => $validated['decision'], 'reason' => $validated['reason'] ?? null],
            $relay,
            SendConfirmation::viaToken($request) ? 'mcp' : 'web',
        );
    }

    /** The link by id (ulid) or URL; a dead link answers like /a does. */
    private function link(mixed $ref): SocialApprovalLink
    {
        // The link is part of the address: none, or one that does not
        // exist, answers 404 — like any id this caller cannot reach.
        $ref = is_string($ref) ? trim($ref) : '';
        abort_if($ref === '' || mb_strlen($ref) > 2048, 404, 'No approval link with that id.');
        $link = str_contains($ref, '/')
            ? SocialApprovalLink::findByToken(basename((string) parse_url($ref, PHP_URL_PATH)))
            : SocialApprovalLink::query()->where('ulid', $ref)->first();

        abort_if($link === null, 404, 'No approval link with that id.');
        abort_if($link->revoked_at !== null, response()->json(['message' => 'This link was taken back.', 'editor' => $link->user->name], 409));
        abort_if($link->expired(), response()->json(['message' => 'This link has expired.', 'editor' => $link->user->name], 410));

        return $link;
    }

    /** The caller, if they are this link's invited approver (verified email). */
    private function approver(Request $request, SocialApprovalLink $link): User
    {
        /** @var User $user */
        $user = $request->user();

        if (! ApproverGate::matches($user, $link)) {
            abort(response()->json([
                'message' => $link->approver_email === null
                    ? 'This link has no invited approver yet — open it in a browser and decide there first.'
                    : 'This link was sent to a different email address than this account’s.',
                'reason' => 'approver_mismatch',
                'expected_hint' => $link->approver_email === null ? null : ApproverGate::hint($link->approver_email),
            ], 403));
        }

        return $user;
    }
}
