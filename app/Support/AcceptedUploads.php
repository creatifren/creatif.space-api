<?php

namespace App\Support;

/**
 * What a file may be — the one allowlist shared by direct upload, Drive
 * import, and File Request submissions.
 */
class AcceptedUploads
{
    /**
     * @var list<string>
     */
    public const MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif',
        'image/tiff', 'image/avif',
        'video/mp4', 'video/quicktime',
        'audio/mpeg', 'audio/wav',
        'application/pdf', 'application/zip', 'application/x-zip-compressed',
        'application/msword', 'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain', 'text/csv',
    ];

    /**
     * Per-file ceiling for direct uploads, in bytes (500 MB).
     */
    public const MAX_BYTES = 500 * 1024 * 1024;
}
