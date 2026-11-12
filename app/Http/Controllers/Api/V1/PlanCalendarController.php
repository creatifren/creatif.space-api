<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\SocialApprovalLink;
use App\Support\PlanCalendar;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The Content Plan calendar feed (ICS).
 *
 *   GET    /social-approval-links/{id}/calendar  → the feed URL (read-only; MCP get_plan_calendar_link)
 *   POST   /social-approval-links/{id}/calendar  → turn it on again; {rotate: true} gives a new URL
 *   DELETE /social-approval-links/{id}/calendar  → turn it off; the URL dies for good
 *   GET    /content-plans/{token}/calendar.ics   → the feed, no sign-in: the signed token is the credential
 *
 * Every Content Plan has a feed, on by default. Its URL is derived
 * (SocialApprovalLink::calendarToken), so nothing secret is stored. A
 * revoked or expired plan, a feed turned off or rotated, or a bad
 * signature answers 404.
 */
class PlanCalendarController extends Controller
{
    public function feed(string $token): Response
    {
        $link = SocialApprovalLink::forCalendarToken($token);
        abort_if($link === null || ! $link->isScoped() || ! $link->calendarOn() || $link->status() !== 'active', 404);
        $link->loadMissing('user');
        abort_if($link->user === null || $link->user->status === UserStatus::PendingDeletion, 404);

        return response(PlanCalendar::ics($link), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="content-plan-'.$link->ulid.'.ics"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function show(Request $request, SocialApprovalLink $socialApprovalLink): JsonResponse
    {
        $link = $this->readable($request, $socialApprovalLink);
        if ($refusal = $this->refusal($link)) {
            return $refusal;
        }

        return response()->json(['data' => $this->body($link)]);
    }

    public function store(Request $request, SocialApprovalLink $socialApprovalLink): JsonResponse
    {
        $link = $this->writable($request, $socialApprovalLink);
        $validated = $request->validate(['rotate' => ['sometimes', 'boolean']]);
        if ($refusal = $this->refusal($link)) {
            return $refusal;
        }

        $link->forceFill([
            'calendar_disabled_at' => null,
            'calendar_version' => $link->calendarVersion() + (($validated['rotate'] ?? false) ? 1 : 0),
        ])->save();

        return response()->json(['data' => $this->body($link)]);
    }

    public function destroy(Request $request, SocialApprovalLink $socialApprovalLink): JsonResponse
    {
        $link = $this->writable($request, $socialApprovalLink);
        // Off, and the old URL never comes back: turning it on again gives a new one.
        $link->forceFill(['calendar_disabled_at' => now(), 'calendar_version' => $link->calendarVersion() + 1])->save();

        return response()->json(['data' => $this->body($link)]);
    }

    /** @return array<string, mixed> */
    private function body(SocialApprovalLink $link): array
    {
        $on = $link->calendarOn();

        return [
            'link_id' => $link->ulid,
            'enabled' => $on,
            'url' => $on ? $link->calendarUrl() : null,
            'webcal_url' => $on ? preg_replace('#^https?://#', 'webcal://', $link->calendarUrl()) : null,
        ];
    }

    private function refusal(SocialApprovalLink $link): ?JsonResponse
    {
        if (! $link->isScoped()) {
            return response()->json(['message' => 'Only a Content Plan (a link with dates) has a calendar feed.', 'reason' => 'not_a_plan'], 422);
        }
        if ($link->status() !== 'active') {
            return response()->json(['message' => 'This link is '.$link->status().'; a calendar feed needs a live Content Plan.', 'reason' => 'link_'.$link->status()], 409);
        }

        return null;
    }

    private function readable(Request $request, SocialApprovalLink $link): SocialApprovalLink
    {
        // ScopeToWorkspace already made another workspace's id a 404.
        abort_unless(Workspace::contains($request->user(), $link->user_id), 404);

        return $link;
    }

    private function writable(Request $request, SocialApprovalLink $link): SocialApprovalLink
    {
        $this->readable($request, $link);
        abort_unless(Workspace::canWrite($request->user()), 403, 'Viewers cannot change calendar feeds.');

        return $link;
    }
}
