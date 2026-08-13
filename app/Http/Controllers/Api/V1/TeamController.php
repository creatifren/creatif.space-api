<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TeamMemberStatus;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\TeamMemberResource;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\TeamInvited;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Team. The owner's screen only: a member cannot invite, and
 * cannot see the seat they occupy from the inside.
 *
 * Seats are the gate rather than a plan check — Free and Premium both come
 * with one seat, so the same sentence refuses on every plan and there is
 * no second rule to keep in step with pricing.
 */
class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        Workspace::ownerOnly($user);

        $members = $user->teamMembers()
            ->where('status', '!=', TeamMemberStatus::Removed)
            ->with('member')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => TeamMemberResource::collection($members)->toArray($request),
            'meta' => [
                'seats' => Workspace::seats($user),
                'seats_used' => Workspace::seatsUsed($user),
                'seat_price_monthly' => $user->plan()->seat_price_monthly,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        Workspace::ownerOnly($user);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['sometimes', 'string', 'in:admin,editor,viewer'],
        ]);

        $email = strtolower($validated['email']);

        if ($email === strtolower($user->email)) {
            throw ValidationException::withMessages([
                'email' => 'That’s your own account — you already have it.',
            ]);
        }

        $existing = $user->teamMembers()->where('email', $email)->first();

        if ($existing !== null && $existing->occupiesSeat()) {
            throw ValidationException::withMessages([
                'email' => 'They’re already on your team.',
            ]);
        }

        // Invited seats count: a seat is spoken for the moment it is
        // offered, or two invitations could fill the last one.
        if ($existing === null && Workspace::seatsUsed($user) >= Workspace::seats($user)) {
            throw ValidationException::withMessages([
                'email' => 'You have '.Workspace::seats($user)
                    .' seats. Add another from Settings → Subscription.',
            ]);
        }

        // A removed member being invited back updates their row rather than
        // colliding with the unique (owner_id, email).
        $member = $user->teamMembers()->updateOrCreate(
            ['email' => $email],
            [
                'role' => $validated['role'] ?? TeamRole::Editor->value,
                'invited_at' => now(),
            ],
        );

        $member->forceFill([
            'status' => TeamMemberStatus::Invited,
            'member_id' => null,
            'joined_at' => null,
        ])->save();

        $member->notify(new TeamInvited($member));

        return (new TeamMemberResource($member->fresh()->load('member')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, TeamMember $teamMember): JsonResponse
    {
        $user = $request->user();
        Workspace::ownerOnly($user);
        $this->mine($user, $teamMember);

        $validated = $request->validate([
            'role' => ['required', 'string', 'in:admin,editor,viewer'],
        ]);

        $teamMember->forceFill(['role' => $validated['role']])->save();

        return response()->json([
            'data' => (new TeamMemberResource($teamMember->load('member')))->toArray($request),
        ]);
    }

    /**
     * Remove somebody. The row stays as `removed` — access ends now, and
     * who was on the team last quarter stays answerable.
     */
    public function destroy(Request $request, TeamMember $teamMember): JsonResponse
    {
        $user = $request->user();
        Workspace::ownerOnly($user);
        $this->mine($user, $teamMember);

        $teamMember->forceFill([
            'status' => TeamMemberStatus::Removed,
            'member_id' => null,
        ])->save();

        Workspace::forget();

        return response()->json(null, 204);
    }

    private function mine(User $user, TeamMember $member): void
    {
        abort_unless($member->owner_id === $user->id, 404);
    }
}
