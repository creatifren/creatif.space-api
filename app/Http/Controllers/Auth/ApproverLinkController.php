<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApproverSignIn;
use App\Support\AutoHandle;
use App\Support\ProductEvents;
use App\Support\TeamInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * GET /auth/approver-link/{token} — the approver's one-time email link.
 * Signs in (or creates, verified) the account for the address it was sent
 * to and returns to the approval page. A dead link returns there too, with
 * ?signin=expired so the page can offer a fresh one.
 */
class ApproverLinkController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $grant = ApproverSignIn::consume($token);
        if ($grant === null) {
            return redirect(rtrim((string) config('app.public_url'), '/').'/?signin=expired');
        }

        ['link' => $link, 'email' => $email] = $grant;

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user?->status === UserStatus::Suspended || ($user !== null && $user->is_staff)) {
            // Staff never sign in by email link; suspended never sign in.
            Log::warning('approver.email_link_refused', ['user' => $user->ulid]);

            return redirect($link->url().'?signin=refused');
        }

        if ($user === null) {
            $user = User::query()->create([
                'name' => Str::of($email)->before('@')->limit(60, '')->toString() ?: 'Client',
                'email' => $email,
            ]);
            ProductEvents::signedUp($user, 'approver_email_link');
        }

        // The address just proved itself. A password signup nobody confirmed
        // loses its password, as on Google sign-in: whoever typed it never
        // proved the address was theirs.
        if ($user->email_verified_at === null) {
            $user->forceFill(['password' => null, 'email_verified_at' => now()])->save();
        }

        // Same as any sign-in: a pending team invite takes, a handle is given.
        TeamInvite::claim($user);
        AutoHandle::give($user);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect($link->url());
    }
}
