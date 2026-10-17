<?php

namespace App\Support;

/**
 * What a file may be — the one allowlist shared by direct upload and
 * replacing a file's version. Only what can end up in a post or a Space:
 * JPG, PNG, GIF, SVG, PDF, MP4, MOV. Mirrored by ACCEPTED_UPLOADS in the
 * frontend's api.ts.
 */
class AcceptedUploads
{
    /**
     * @var list<string>
     */
    public const MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/svg+xml',
        'video/mp4', 'video/quicktime',
        'application/pdf',
    ];

    /**
     * Per-file ceiling for direct uploads, in bytes (500 MB).
     */
    public const MAX_BYTES = 500 * 1024 * 1024;
}
