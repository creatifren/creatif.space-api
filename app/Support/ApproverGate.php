<?php

namespace App\Support;

use App\Enums\UserStatus;
use App\Models\SocialApprovalDecision;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\User;
use App\Notifications\ApprovalLinkBound;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Who may decide on an approval link (Meeting #02, 11 Nov 2026).
 *
 * Looking stays open: the token shows the plan to anyone holding it.
 * Deciding (approve, ask for changes, take back) needs a signed-in web
 * session (the app's Sanctum cookie) whose VERIFIED email is the link's
 * approver_email, compared case-insensitively:
 *
 *   401 {reason: "sign_in_required", methods: ["google", "email"]}
 *
 *   403 {reason: "approver_mismatch", expected_hint: "r***@d***.com"}
 *
 * A link made before approver_email existed is bound on first use: the
 * first signed-in person (verified email) to decide becomes its approver,
 * and the owner is told by email.
 */
final class ApproverGate
{
    public const METHODS = ['google', 'email'];

    /**
     * The user allowed to decide on this link right now, or an HTTP answer.
     *
     * @throws HttpResponseException
     */
    public static function authorize(Request $request, SocialApprovalLink $link): User
    {
        $user = self::sessionUser($request);

        if ($user === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'Sign in to approve or request changes.',
                'reason' => 'sign_in_required',
                'methods' => self::METHODS,
            ], 401));
        }

        if (! self::verified($user) || ($link->approver_email !== null && ! self::matches($user, $link))) {
            throw new HttpResponseException(response()->json([
                'message' => $link->approver_email === null
                    ? 'Confirm your email address before deciding on this link.'
                    : 'This link was sent to a different email address. Sign in with that address to decide.',
                'reason' => 'approver_mismatch',
                'expected_hint' => $link->approver_email === null ? null : self::hint($link->approver_email),
            ], 403));
        }

        return $user;
    }

    /** The web-session user (never a bearer token: an approver is a person). */
    public static function sessionUser(Request $request): ?User
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User ? $user : null;
    }

    public static function verified(User $user): bool
    {
        // Raw read: a model built in memory has no status until refreshed
        // (the column defaults to active), and strict mode would throw.
        $status = $user->getAttributes()['status'] ?? UserStatus::Active->value;

        return $user->email_verified_at !== null
            && ($status instanceof UserStatus ? $status : UserStatus::tryFrom((string) $status)) !== UserStatus::Suspended;
    }

    public static function matches(User $user, SocialApprovalLink $link): bool
    {
        return $link->approver_email !== null
            && self::verified($user)
            && mb_strtolower(trim($user->email)) === $link->approver_email;
    }

    /**
     * Trust on first use: bind an unbound link to this user's email. True
     * when this call bound it. Atomic: two first deciders at once cannot
     * both win; the loser is then checked against the winner's email.
     *
     * @throws HttpResponseException
     */
    public static function bindIfUnbound(Request $request, SocialApprovalLink $link, User $user, string $decision): bool
    {
        if ($link->approver_email !== null) {
            return false;
        }

        $email = mb_strtolower(trim($user->email));
        $bound = SocialApprovalLink::query()
            ->whereKey($link->id)
            ->whereNull('approver_email')
            ->update(['approver_email' => $email, 'approver_bound_at' => now()]);

        $link->refresh();

        if ($bound === 0) {
            // Someone else bound it a moment ago: the usual check applies.
            self::authorize($request, $link);

            return false;
        }

        $link->user->notify(new ApprovalLinkBound($link, $email, $decision));

        return true;
    }

    /** Write one audit row. */
    public static function record(Request $request, SocialApprovalLink $link, SocialPost $post, string $decision, User $user, ?string $name, string $source = 'web', ?string $comment = null): void
    {
        SocialApprovalDecision::create([
            // The approver's reason ("request changes"), for the audit export.
            'comment' => $comment === null ? null : mb_substr($comment, 0, 5000),
            // Where the decision was made: the /a page, or an MCP client.
            'source' => $source,
            'social_approval_link_id' => $link->id,
            'social_post_id' => $post->id,
            'decision' => $decision,
            'approver_user_id' => $user->id,
            'approver_email' => mb_strtolower(trim($user->email)),
            'client_name' => $name === null ? null : mb_substr($name, 0, 120),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
        ]);
    }

    /**
     * What the /a page needs to know about the person looking at it.
     *
     * @return array<string, mixed>
     */
    public static function viewer(Request $request, SocialApprovalLink $link): array
    {
        $user = self::sessionUser($request);

        return [
            'approver_required' => true,
            'approver_hint' => $link->approver_email === null ? null : self::hint($link->approver_email),
            // An old link nobody has decided on yet: the first signed-in person binds it.
            'bind_on_first_decision' => $link->approver_email === null,
            'signed_in' => $user !== null,
            'signed_in_email' => $user?->email,
            'can_decide' => $user !== null && self::verified($user)
                && ($link->approver_email === null || self::matches($user, $link)),
            'sign_in_methods' => self::METHODS,
        ];
    }

    /** "rizal@digicard.com" → "r***@d***.com"; never the whole address. */
    public static function hint(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', mb_strtolower(trim($email)), 2), 2, '');
        $labels = explode('.', $domain);
        $tld = count($labels) > 1 ? array_pop($labels) : '';
        $host = implode('.', $labels);

        return mb_substr($local, 0, 1).'***@'.mb_substr($host, 0, 1).'***'.($tld !== '' ? '.'.$tld : '');
    }
}
