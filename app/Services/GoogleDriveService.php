<?php

namespace App\Services;

use App\Enums\DriveAccountStatus;
use App\Models\DriveAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client over the Google Drive v3 REST API. Deliberately not the
 * google/apiclient SDK: we call two endpoints and want full control over
 * token refresh + the drive.file scope boundary.
 *
 * Scope drive.file: only files the user explicitly picked (via the Picker)
 * are visible to us. Import-only: metadata + a one-time byte copy into R2,
 * never a live link back.
 */
class GoogleDriveService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/drive/v3';

    public const FILE_FIELDS = 'id,name,mimeType,size,md5Checksum,imageMediaMetadata';

    /**
     * A valid access token for the account, refreshing when expired.
     * Marks the account reconnect_needed when the refresh is rejected.
     */
    public function accessToken(DriveAccount $account): string
    {
        if (
            $account->access_token !== null
            && $account->token_expires_at !== null
            && $account->token_expires_at->subMinutes(2)->isFuture()
        ) {
            return $account->access_token;
        }

        if ($account->refresh_token === null) {
            $this->markReconnectNeeded($account);
            throw new RuntimeException("Drive account {$account->id} has no refresh token.");
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);

        if ($response->failed()) {
            // invalid_grant = the user revoked us in their Google account.
            $this->markReconnectNeeded($account);
            throw new RuntimeException("Token refresh failed for drive account {$account->id}: {$response->body()}");
        }

        $account->forceFill([
            'access_token' => $response->json('access_token'),
            'token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
            'status' => DriveAccountStatus::Connected,
        ])->save();

        return (string) $response->json('access_token');
    }

    /**
     * Fetch metadata for one file. Returns null when the file is gone or
     * we lost access to it (404/403/401 from Drive).
     *
     * @return array<string, mixed>|null
     *
     * @throws ConnectionException
     */
    public function fileMetadata(DriveAccount $account, string $fileId): ?array
    {
        $response = Http::withToken($this->accessToken($account))
            ->get(self::API."/files/{$fileId}", ['fields' => self::FILE_FIELDS]);

        if (in_array($response->status(), [401, 403, 404], true)) {
            return null;
        }

        $response->throw();

        return $response->json();
    }

    /**
     * Download one file's bytes to a local path — the import copy. Streamed
     * via sink so a 100 MB file never becomes a PHP string. Returns false
     * when Drive refuses (gone, or access lost).
     *
     * @throws ConnectionException
     */
    public function download(DriveAccount $account, string $fileId, string $destPath): bool
    {
        $response = Http::withToken($this->accessToken($account))
            ->sink($destPath)
            ->get(self::API."/files/{$fileId}", ['alt' => 'media']);

        if (in_array($response->status(), [401, 403, 404], true)) {
            return false;
        }

        $response->throw();

        return true;
    }

    private function markReconnectNeeded(DriveAccount $account): void
    {
        if ($account->status !== DriveAccountStatus::Revoked) {
            $account->forceFill(['status' => DriveAccountStatus::ReconnectNeeded])->save();
        }
    }
}
