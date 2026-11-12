<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AutoHandle;
use App\Support\DirectoryReviewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * POST /auth/reviewer/login — password sign-in for directory reviewers
 * only (see DirectoryReviewer). An account without the flag never gets in
 * here, whatever its password: the answer is the same as a wrong password,
 * so the form says nothing about which accounts exist or are reviewers.
 *
 * Throttled per IP (route) and per address (here); every attempt is
 * audited in directory_reviewer_logins.
 */
class ReviewerLoginController extends Controller
{
    /** A bcrypt hash of nothing in particular, for timing parity. */
    private const DUMMY_HASH = '$2y$12$iLDYF.xO2cu7rwzJcFMjdeqhvW3SZIp4KD20W3wFbZZAkHEnOcRJm';

    private const FAILED = 'That email and password don’t match.';

    public function __invoke(Request $request): Response|JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        $email = Str::lower(trim($data['email']));
        $key = 'reviewer-login:'.DirectoryReviewer::emailHash($email);

        if (RateLimiter::tooManyAttempts($key, DirectoryReviewer::MAX_ATTEMPTS)) {
            DirectoryReviewer::audit(null, $email, $request->ip(), DirectoryReviewer::THROTTLED);

            return response()->json([
                'message' => 'Too many attempts. Try again later.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }

        $user = User::query()->where('email', $email)->first();
        $hash = $user !== null && $user->is_directory_reviewer && is_string($user->password) ? $user->password : null;

        // Always one hash check, so the timing does not say which case this was.
        $matches = Hash::check($data['password'], $hash ?? self::DUMMY_HASH);

        if ($user === null || $hash === null || ! $matches) {
            RateLimiter::hit($key, DirectoryReviewer::DECAY_SECONDS);
            DirectoryReviewer::audit($user, $email, $request->ip(), DirectoryReviewer::REJECTED);

            throw ValidationException::withMessages(['email' => self::FAILED]);
        }

        if ($user->status === UserStatus::Suspended || $user->trashed()) {
            DirectoryReviewer::audit($user, $email, $request->ip(), DirectoryReviewer::SUSPENDED);

            return response()->json(['message' => 'This account is suspended.', 'reason' => 'suspended'], 403);
        }

        RateLimiter::clear($key);
        AutoHandle::give($user);
        Auth::login($user, remember: true);
        $request->session()->regenerate();
        DirectoryReviewer::audit($user, $email, $request->ip(), DirectoryReviewer::SUCCESS);

        return response()->noContent();
    }
}
