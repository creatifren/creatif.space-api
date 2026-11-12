<?php

use App\Http\Controllers\Api\V1\ProfileFontController;
use App\Http\Controllers\Auth\ApproverLinkController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\PasswordAuthController;
use App\Http\Controllers\Auth\ReviewerLoginController;
use App\Http\Middleware\RequirePkce;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;

Route::get('/', fn () => redirect(config('app.frontend_url')));

// A profile's uploaded display font. Here, not under /api, so it can carry
// its own open CORS header — see ProfileFontController::show.
Route::get('/fonts/{handle}', [ProfileFontController::class, 'show'])->middleware('throttle:120,1')->name('profiles.font');

// Google OAuth (session-based, consumed by the Next.js SPA via Sanctum cookies)
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:20,1')->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:20,1')->name('auth.google.callback');
// The Android app: an ID token from Credential Manager, posted from inside the WebView (see GoogleAuthController::native).
Route::post('/auth/google/native', [GoogleAuthController::class, 'native'])
    ->middleware('throttle:10,1')
    ->name('auth.google.native');

// An approver's one-time email sign-in link (POST /api/v1/a/{token}/email-link
// sends it): signs in or creates that address and returns to the approval page.
Route::get('/auth/approver-link/{token}', ApproverLinkController::class)
    ->middleware('throttle:20,1')
    ->name('auth.approver-link');

// Email + password. JSON for the SPA except the verify link, which is opened
// from an inbox and redirects back to the frontend.
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/auth/register', [PasswordAuthController::class, 'register'])->name('auth.register');
    Route::post('/auth/login', [PasswordAuthController::class, 'login'])->name('auth.login');
    Route::post('/auth/forgot-password', [PasswordAuthController::class, 'forgot'])->name('password.email');
    Route::post('/auth/reset-password', [PasswordAuthController::class, 'reset'])->name('password.update');
});
// Directory reviewers only (staff-set flag, `php artisan reviewer:grant`):
// password sign-in with no verification step. Every other account is
// refused here, whatever its password. Throttled per IP and per address.
Route::post('/auth/reviewer/login', ReviewerLoginController::class)
    ->middleware('throttle:10,1')
    ->name('auth.reviewer.login');

Route::get('/auth/email/verify/{id}/{hash}', [PasswordAuthController::class, 'verify'])
    ->middleware('throttle:10,1')
    ->name('verification.verify');

Route::post('/auth/logout', function () {
    Auth::guard('web')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return response()->noContent();
})->middleware('auth')->name('auth.logout');

// OAuth 2.1 for MCP clients — the browser half: the consent screen and its
// two buttons. Session-based, like the rest of web.php; a guest is sent to
// the frontend's login and comes back through /oauth/resume.
Route::get('/oauth/authorize', [AuthorizationController::class, 'authorize'])
    ->middleware([RequirePkce::class, 'throttle:60,1'])
    ->name('passport.authorizations.authorize');
Route::middleware('auth')->group(function () {
    Route::post('/oauth/authorize', [ApproveAuthorizationController::class, 'approve'])
        ->name('passport.authorizations.approve');
    Route::delete('/oauth/authorize', [DenyAuthorizationController::class, 'deny'])
        ->name('passport.authorizations.deny');
});

// Back from the frontend's login to the authorize request that sent the
// person there. Only ever an /oauth/authorize URL on this host.
Route::get('/oauth/resume', function () {
    $intended = (string) session()->pull('oauth.intended', '');
    $prefix = url('/oauth/authorize').'?';

    return Auth::guard('web')->check() && str_starts_with($intended, $prefix)
        ? redirect()->to($intended)
        : redirect(config('app.frontend_url'));
})->middleware('throttle:30,1')->name('oauth.resume');
