<?php

use App\Http\Controllers\OAuth\ClientRegistrationController;
use App\Http\Controllers\OAuth\MetadataController;
use App\Http\Controllers\OAuth\RevocationController;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;

/*
 * OAuth 2.1 endpoints machines call — no session, no CSRF. The browser
 * half (authorize, approve, deny) is in web.php.
 */

// Discovery (RFC 8414, RFC 9728).
Route::get('/.well-known/oauth-authorization-server', [MetadataController::class, 'authorizationServer'])
    ->middleware('throttle:120,1')
    ->name('oauth.metadata');
Route::get('/.well-known/oauth-protected-resource', [MetadataController::class, 'protectedResource'])
    ->middleware('throttle:120,1')
    ->name('oauth.protected-resource');
Route::get('/.well-known/oauth-protected-resource/mcp', [MetadataController::class, 'protectedResource'])
    ->middleware('throttle:120,1')
    ->name('oauth.protected-resource.mcp');

Route::prefix('oauth')->group(function () {
    Route::post('/token', [AccessTokenController::class, 'issueToken'])
        ->middleware('throttle:60,1')
        ->name('passport.token');

    Route::post('/register', ClientRegistrationController::class)
        ->middleware('throttle:10,1')
        ->name('oauth.register');

    Route::post('/revoke', RevocationController::class)
        ->middleware('throttle:60,1')
        ->name('oauth.revoke');
});
