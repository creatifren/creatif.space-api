<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\EmailSignInCode;
use App\Support\AutoHandle;
use App\Support\Onboarding;
use App\Support\ProductEvents;
use App\Support\ReferralAttribution;
use App\Support\SafePath;
use App\Support\SignupAttribution;
use App\Support\TeamInvite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sign in or sign up with an email code (prototype v7: one screen, Google or
 * email + 6-digit code; name and an optional password come later in
 * Profile). The first right code for a new address makes the account.
 *
 *     POST /auth/email-code          { email }          → 202 (always, so it never says which addresses exist)
 *     POST /auth/email-code/verify   { email, code, … } → 200 { new_account, next }  and the session
 */
class EmailCodeAuthController extends Controller
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254']]);
        $email = Str::lower(trim($data['email']));

        // A suspended account gets no code, and the answer is the same.
        $user = User::query()->where('email', $email)->first();
        if ($user?->status !== UserStatus::Suspended) {
            $code = (string) random_int(100000, 999999);
            DB::table('email_login_codes')->where('email', $email)->whereNull('consumed_at')->delete();
            DB::table('email_login_codes')->insert([
                'email' => $email,
                'code_hash' => self::hash($email, $code),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'ip' => $request->ip(),
                'created_at' => now(),
            ]);
            Notification::route('mail', $email)->notify(new EmailSignInCode($code));
        }

        return response()->json(['message' => 'Check your email for a 6-digit code.', 'expires_in_minutes' => self::TTL_MINUTES], 202);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'ref' => ['nullable', 'string', 'max:30'],
            'next' => ['nullable', 'string', 'max:2000'],
        ]);
        $email = Str::lower(trim($data['email']));

        $row = DB::table('email_login_codes')
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($row === null || $row->attempts >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['code' => 'That code has expired. Ask for a new one.']);
        }
        if (! hash_equals($row->code_hash, self::hash($email, $data['code']))) {
            DB::table('email_login_codes')->where('id', $row->id)->increment('attempts');
            throw ValidationException::withMessages(['code' => 'That code is not right. Check the email and try again.']);
        }
        // Single use, even when two requests race: only one update wins.
        if (DB::table('email_login_codes')->where('id', $row->id)->whereNull('consumed_at')->update(['consumed_at' => now()]) !== 1) {
            throw ValidationException::withMessages(['code' => 'That code has expired. Ask for a new one.']);
        }

        $user = User::query()->where('email', $email)->first();
        if ($user?->status === UserStatus::Suspended) {
            return response()->json(['message' => 'This account is suspended.', 'reason' => 'suspended'], 403);
        }
        // Staff sign in with Google or a password, never by a mailed code.
        if ($user?->is_staff) {
            return response()->json(['message' => 'Use Google to sign in to this account.', 'reason' => 'staff'], 403);
        }

        $new = $user === null;
        $next = SafePath::of($data['next'] ?? null);
        if ($new) {
            $user = User::query()->create([
                // Until they set their name in Profile.
                'name' => Str::before($email, '@'),
                'email' => $email,
            ]);
            $user->forceFill(['email_verified_at' => now()] + ($next !== '/' ? ['signup_next_path' => $next] : []))->save();
            if (isset($data['ref'])) {
                ReferralAttribution::attach($user, $data['ref']);
            }
            ProductEvents::signedUp($user, 'email_code', [
                'ref' => $data['ref'] ?? null,
                'entry' => ProductEvents::entryFrom($request),
            ] + ProductEvents::utmFrom($request) + SignupAttribution::touchFrom($request));
            if (! Onboarding::isClientLanding($next)) {
                Onboarding::welcome($user);
            }
        } elseif ($user->email_verified_at === null) {
            // The code proves the address.
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        TeamInvite::claim($user);
        AutoHandle::give($user);
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return response()->json(['data' => [
            // New → Connect channels (onboarding); known → Today.
            'new_account' => $new,
            'next' => $new ? '/onboarding' : $next,
        ]]);
    }

    private static function hash(string $email, string $code): string
    {
        return hash_hmac('sha256', $email.'|'.$code, (string) config('app.key'));
    }
}
