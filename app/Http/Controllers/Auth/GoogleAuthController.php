<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ReferralAttribution;
use App\Support\SafePath;
use App\Support\TeamInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class GoogleAuthController extends Controller
{
    /**
     * Redirect the user to Google's OAuth consent screen.
     *
     * Login-only for now: the drive.file scope is requested separately when
     * the user connects a Drive account (Fase 2), per the onboarding design
     * ("Later" escape hatch on the Drive step).
     */
    public function redirect(Request $request): SymfonyRedirectResponse
    {
        // The frontend appends ?ref= from its 90-day cookie. It has to
        // travel through the session because the cookie belongs to the
        // Next.js origin and Laravel never sees it.
        $code = $request->query('ref');

        if (is_string($code) && $code !== '') {
            $request->session()->put('referral_code', substr($code, 0, 30));
        }

        // Where they were headed before the guard sent them to /login. Travels
        // through the session for the same reason as the referral code, and is
        // checked here as well as on the way back — the value came from a URL
        // a stranger could have written.
        $next = $request->query('next');

        if (is_string($next) && $next !== '') {
            $request->session()->put('next_path', SafePath::of($next));
        }

        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle the OAuth callback: find or create the creator, start the
     * session (Sanctum SPA cookie), and hand off to the Next.js frontend.
     */
    public function callback(): RedirectResponse
    {
        /** @var GoogleUser $googleUser */
        $googleUser = Socialite::driver('google')->user();

        $user = User::query()
            ->where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        if ($user?->status === UserStatus::Suspended) {
            return redirect(config('app.frontend_url').'/login?error=suspended');
        }

        if ($user === null) {
            $user = User::query()->create([
                'name' => $googleUser->getName() ?? $googleUser->getNickname() ?? 'Creator',
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'avatar_url' => $googleUser->getAvatar(),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            // Only a brand-new account can be somebody's referral — a
            // returning login is not a signup, whatever link they used.
            $code = request()->session()->pull('referral_code');

            if (is_string($code)) {
                ReferralAttribution::attach($user, $code);
            }
        } elseif ($user->google_id === null) {
            // Existing account (e.g. staff) signing in with Google for the first time.
            $user->forceFill(['google_id' => $googleUser->getId()])->save();
        }

        // Somebody may have invited this address to their team, whether the
        // account is new or a year old — this login is when it takes.
        TeamInvite::claim($user);

        Auth::login($user, remember: true);
        request()->session()->regenerate();

        /* Where to land. An account that has not been through onboarding goes
           there whatever it asked for — `next` is a convenience, never a way
           to skip picking an address. Otherwise honour the page the guard
           interrupted, checked a second time because a session value is only
           as trustworthy as what was put in it. */
        $next = request()->session()->pull('next_path');
        $wanted = SafePath::of($next);

        $destination = match (true) {
            $user->onboarded_at === null => '/onboarding',
            $wanted !== '/' => $wanted,
            default => '/home',
        };

        return redirect(config('app.frontend_url').$destination);
    }
}
