<?php

namespace App\Http\Controllers\Auth;

use App\Enums\DriveAccountStatus;
use App\Http\Controllers\Controller;
use App\Models\DriveAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Connecting a Drive is a second, separate OAuth pass with the drive.file
 * scope — deliberately not bundled into login, so onboarding step 3 can be
 * skipped ("Later") and asked again at first use.
 */
class GoogleDriveConnectController extends Controller
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Send the signed-in user to Google's consent screen for drive.file.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $state = str()->random(40);
        $request->session()->put('drive_oauth_state', $state);

        return redirect(self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.drive_redirect'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.file openid email',
            // offline + consent so Google issues a refresh token every time.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]));
    }

    /**
     * Exchange the code, then create or refresh the DriveAccount.
     */
    public function callback(Request $request): RedirectResponse
    {
        $frontend = config('app.frontend_url');

        if (
            $request->query('state') !== $request->session()->pull('drive_oauth_state')
            || $request->query('code') === null
        ) {
            return redirect($frontend.'/onboarding?drive=error');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'grant_type' => 'authorization_code',
            'code' => (string) $request->query('code'),
            'redirect_uri' => config('services.google.drive_redirect'),
        ]);

        if ($response->failed()) {
            return redirect($frontend.'/onboarding?drive=error');
        }

        // The id_token payload carries the Google account identity — decode
        // the claims (signature verification is unnecessary here: the token
        // came to us directly from Google over TLS).
        $claims = json_decode(base64_decode(
            str_replace(['-', '_'], ['+', '/'], explode('.', (string) $response->json('id_token'))[1] ?? ''),
        ) ?: '{}', true);

        $user = $request->user();

        $account = DriveAccount::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => 'google',
                'provider_account_id' => (string) ($claims['sub'] ?? ''),
            ],
            [
                'email' => (string) ($claims['email'] ?? $user->email),
                'access_token' => $response->json('access_token'),
                'refresh_token' => $response->json('refresh_token'),
                'token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
                'status' => DriveAccountStatus::Connected,
            ],
        );

        return redirect($frontend.'/onboarding?drive=connected&account='.$account->ulid);
    }
}
