<?php

namespace App\Actions\Auth;

use App\Enums\UserStatus;
use App\Exceptions\GoogleLoginRefused;
use App\Models\User;
use App\Support\AutoHandle;
use App\Support\ProductEvents;
use App\Support\ReferralAttribution;
use App\Support\SignupAttribution;
use App\Support\TeamInvite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The account a Google identity signs in to: found, created or linked.
 *
 * Shared by the web redirect callback and the Android app's native
 * sign-in (POST /auth/google/native), so both follow the same rules.
 * Starting the session is left to the caller.
 */
class ResolveGoogleUser
{
    /**
     * @param  string|null  $platform  set for a non-web client (e.g. `android_app`); added to the signup event
     *
     * @throws GoogleLoginRefused suspended, or google_not_linked (staff matched by email only)
     */
    public function handle(GoogleIdentity $google, Request $request, ?string $platform = null): User
    {
        // The account this Google identity already belongs to wins; only
        // then an account with the same address.
        $user = User::query()->where('google_id', $google->id)->first()
            ?? User::query()->where('email', $google->email)->first();

        if ($user?->status === UserStatus::Suspended) {
            throw new GoogleLoginRefused('suspended');
        }

        /* Staff (anyone who can reach /admin) are never linked or signed in
           by email: a Google account that merely carries a staff address —
           a re-created mailbox, a shared domain — must not become a way into
           the panel. Their google_id has to match, set beforehand. */
        if ($user !== null && $user->is_staff && $user->google_id !== $google->id) {
            Log::warning('auth.google_staff_link_refused', ['user' => $user->ulid, 'had_google_id' => $user->google_id !== null]);

            throw new GoogleLoginRefused('google_not_linked');
        }

        if ($user === null) {
            $user = User::query()->create([
                'name' => $google->name ?? 'Creator',
                'email' => $google->email,
                'google_id' => $google->id,
                'avatar_url' => $google->avatar,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            // Only a brand-new account can be somebody's referral — a
            // returning login is not a signup, whatever link they used.
            $code = $request->session()->pull('referral_code');

            if (is_string($code)) {
                ReferralAttribution::attach($user, $code);
            }

            $utm = $request->session()->pull('signup_utm');
            $entry = $request->session()->pull('signup_entry');
            // Web: stashed by the redirect. Native sign-in: in the POST body.
            $touch = $request->session()->pull('signup_touch');
            ProductEvents::signedUp($user, 'google', [
                'ref' => is_string($code) ? $code : null,
                'entry' => is_string($entry) ? $entry : null,
            ] + (is_array($utm) ? $utm : []) + (is_array($touch) ? $touch : SignupAttribution::touchFrom($request)),
                $platform === null ? [] : ['platform' => $platform]);
        } elseif ($user->google_id === null) {
            // Existing (non-staff) account signing in with Google for the first time.
            $user->forceFill(['google_id' => $google->id])->save();

            // A password signup nobody confirmed: whoever typed that password
            // never proved this address was theirs, and Google just did. The
            // password goes, so it can't be a way into the owner's account.
            if ($user->email_verified_at === null) {
                $user->forceFill(['password' => null, 'email_verified_at' => now()])->save();
            }
        }

        // Somebody may have invited this address to their team, whether the
        // account is new or a year old — this login is when it takes.
        TeamInvite::claim($user);
        // No onboarding screen: the address comes from the email.
        AutoHandle::give($user);

        return $user;
    }
}
