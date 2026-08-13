<?php

namespace App\Services;

use App\Enums\DriveAccountStatus;
use App\Models\DriveAccount;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client over the Google Drive v3 REST API. Deliberately not the
 * google/apiclient SDK: we call three endpoints and want full control over
 * token refresh + the drive.file scope boundary.
 *
 * Scope drive.file: only files the user explicitly picked (via the Picker)
 * are visible to us. Metadata + thumbnails only — bytes never come here.
 */
class GoogleDriveService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3';

    public const FILE_FIELDS = 'id,name,mimeType,size,md5Checksum,version,thumbnailLink,parents,trashed,imageMediaMetadata';

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
     * List children of a folder (for subfolder traversal), one page.
     *
     * @return array{files: list<array<string, mixed>>, nextPageToken: string|null}
     *
     * @throws ConnectionException
     */
    public function listChildren(DriveAccount $account, string $folderId, ?string $pageToken = null): array
    {
        $response = Http::withToken($this->accessToken($account))
            ->get(self::API.'/files', array_filter([
                'q' => sprintf("'%s' in parents and trashed = false", addslashes($folderId)),
                'fields' => 'nextPageToken,files('.self::FILE_FIELDS.')',
                'pageSize' => 200,
                'pageToken' => $pageToken,
            ]));

        $response->throw();

        return [
            'files' => $response->json('files', []),
            'nextPageToken' => $response->json('nextPageToken'),
        ];
    }

    /**
     * Put one file into a folder the owner picked — the File Request path,
     * and the only place this app ever writes to someone's Drive.
     *
     * Resumable rather than multipart: the bytes are already a temp file on
     * disk, and streaming them keeps a 100 MB submission out of PHP's
     * memory. Two calls — ask for a session, then PUT the body at the URL
     * Google hands back.
     *
     * drive.file reaches this folder because the owner chose it in the
     * Picker; a folder we were never given stays invisible, which is the
     * scope working, not a bug.
     *
     * Verified live against a folder this app created. The Picker-chosen
     * case follows the same grant but has not been exercised end-to-end —
     * a null return here on a real request is the first place to look.
     *
     * Returns the new file's provider id, or null when Drive refused — the
     * folder was deleted, or the grant no longer covers it.
     *
     * @throws ConnectionException
     */
    public function upload(
        DriveAccount $account,
        string $folderId,
        string $path,
        string $name,
        string $mimeType,
    ): ?string {
        $token = $this->accessToken($account);
        $size = filesize($path);

        if ($size === false) {
            throw new RuntimeException("Cannot read upload at {$path}.");
        }

        $start = Http::withToken($token)
            ->withHeaders([
                'X-Upload-Content-Type' => $mimeType,
                'X-Upload-Content-Length' => (string) $size,
            ])
            ->post(self::UPLOAD.'/files?uploadType=resumable', [
                'name' => $name,
                'parents' => [$folderId],
            ]);

        if (in_array($start->status(), [401, 403, 404], true)) {
            return null;
        }

        $start->throw();

        $session = $start->header('Location');

        if ($session === '') {
            return null;
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open upload at {$path}.");
        }

        // A PSR-7 stream, not the raw contents: 100 MB must never be a
        // string in memory. Utils::streamFor closes the handle with it.
        $stream = Utils::streamFor($handle);

        try {
            $finish = Http::withToken($token)
                ->withBody($stream, $mimeType)
                ->put($session);
        } finally {
            $stream->close();
        }

        if ($finish->failed()) {
            return null;
        }

        return $finish->json('id');
    }

    private function markReconnectNeeded(DriveAccount $account): void
    {
        if ($account->status !== DriveAccountStatus::Revoked) {
            $account->forceFill(['status' => DriveAccountStatus::ReconnectNeeded])->save();
        }
    }
}
