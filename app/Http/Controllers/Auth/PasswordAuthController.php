<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AutoHandle;
use App\Support\ReferralAttribution;
use App\Support\TeamInvite;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Email + password, the second way in next to Google.
 *
 * The one rule everything here bends around: an address nobody has proven
 * is never signed in. GoogleAuthController, TeamInvite and the client
 * sign-in all match people by email, so an unverified account that could
 * log in would let somebody register a stranger's address and wait for the
 * stranger's team invites — or their Google sign-in — to land in it.
 */
class PasswordAuthController extends Controller
{
    /**
     * Create the account and send the verification link. No session: the
     * link is what signs them in.
     *
     * An address that already has an account gets a reset link instead, and
     * the answer is the same either way — which accounts exist is not this
     * form's to say. The reset link also gets everyone unstuck: whoever lost
     * the verify email, a Google-only account, or the real owner of an
     * address a stranger signed up with all end up holding the password.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', PasswordRule::min(8)->max(72)],
            'ref' => ['nullable', 'string', 'max:30'],
        ]);
        $email = Str::lower($data['email']);

        if (User::withTrashed()->where('email', $email)->exists()) {
            Password::sendResetLink(['email' => $email]);
        } else {
            // A mail that fails to send takes the account with it, so a
            // retry starts clean instead of hitting "already registered".
            DB::transaction(function () use ($data, $email): void {
                $user = User::query()->create([
                    // ponytail: the part before the @ until they rename themselves in
                    // Settings — Google hands us a real name, a form does not.
                    'name' => Str::before($email, '@'),
                    'email' => $email,
                    'password' => $data['password'],
                ]);

                if (isset($data['ref'])) {
                    ReferralAttribution::attach($user, $data['ref']);
                }

                $user->sendEmailVerificationNotification();
            });
        }

        return response()->json(['message' => 'Check your email to finish signing up.'], 202);
    }

    public function login(Request $request): Response|JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', Str::lower($data['email']))->first();

        // A Google-only account has no password and fails here like a typo
        // would — which accounts exist is not this form's to say.
        if ($user?->password === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'That email and password don’t match.',
            ]);
        }

        if ($user->status === UserStatus::Suspended) {
            return response()->json(['message' => 'This account is suspended.', 'reason' => 'suspended'], 403);
        }

        if ($user->email_verified_at === null) {
            $user->sendEmailVerificationNotification();

            return response()->json([
                'message' => 'Confirm your email first — we just sent a new link.',
                'reason' => 'unverified',
            ], 403);
        }

        $this->signIn($request, $user);

        return response()->noContent();
    }

    /**
     * The link in the verification email. Not behind `auth`: it is opened
     * from an inbox, often in a different browser from the one that signed
     * up, and the signature is the proof.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $frontend = config('app.frontend_url');
        $user = User::query()->find($id);

        if (
            $user === null
            || ! $request->hasValidSignature()
            || ! hash_equals(sha1($user->getEmailForVerification()), $hash)
        ) {
            return redirect($frontend.'/login?error=link');
        }

        if ($user->status === UserStatus::Suspended) {
            return redirect($frontend.'/login?error=suspended');
        }

        // One use only: otherwise the link is a 60-minute login for whoever
        // the email gets forwarded to.
        if ($user->hasVerifiedEmail()) {
            return redirect($frontend.'/login?error=link');
        }

        $user->markEmailAsVerified();
        $this->signIn($request, $user);

        // Same landing as the Google callback.
        return redirect($frontend.'/home');
    }

    /**
     * Send a reset link — also how a Google account sets its first password.
     * The answer never says whether the address has an account.
     */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => 'If that address has an account, a link is on its way.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', PasswordRule::min(8)->max(72)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    // Signs out every "remember me" cookie made with the old one.
                    'remember_token' => Str::random(60),
                    // The link came to their inbox — the same proof the
                    // verification email asks for.
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => 'Password saved — sign in with it now.']);
    }

    /** What the Google callback does once it knows who this is. */
    private function signIn(Request $request, User $user): void
    {
        TeamInvite::claim($user);
        AutoHandle::give($user);

        Auth::login($user, remember: true);
        $request->session()->regenerate();
    }
}
