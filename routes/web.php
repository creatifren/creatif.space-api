<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\GoogleClientAuthController;
use App\Http\Controllers\Auth\GoogleDriveConnectController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect(config('app.frontend_url')));

// Google OAuth (session-based, consumed by the Next.js SPA via Sanctum cookies)
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');

// Drive connect (second OAuth pass, scope drive.file) — signed-in users only
Route::middleware('auth')->group(function () {
    Route::get('/auth/google/drive/redirect', [GoogleDriveConnectController::class, 'redirect'])->name('auth.google.drive.redirect');
    Route::get('/auth/google/drive/callback', [GoogleDriveConnectController::class, 'callback'])->name('auth.google.drive.callback');
});

// Client sign-in (third pass, identity only) — deliberately NOT behind
// `auth`: whoever opens the Space link is a stranger to us until here.
Route::get('/auth/google/client/redirect', [GoogleClientAuthController::class, 'redirect'])->name('auth.google.client.redirect');
Route::get('/auth/google/client/callback', [GoogleClientAuthController::class, 'callback'])->name('auth.google.client.callback');
Route::post('/auth/client/logout', [GoogleClientAuthController::class, 'logout'])->name('auth.client.logout');

Route::post('/auth/logout', function () {
    Auth::guard('web')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return response()->noContent();
})->middleware('auth')->name('auth.logout');
