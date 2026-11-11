<?php

namespace App\Support;

/**
 * What a file may be — the one allowlist shared by direct upload and
 * replacing a file's version. Only what can end up in a post or a Space:
 * JPG, PNG, GIF, WebP, SVG, PDF, MP4, MOV. The bytes are checked too
 * (App\\Support\\Media\\FileSignature); per-file caps are per tier
 * (App\\Support\\Media\\MediaLimits). Mirrored by ACCEPTED_UPLOADS in the
 * frontend's api.ts.
 */
class AcceptedUploads
{
    /**
     * @var list<string>
     */
    public const MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
        'video/mp4', 'video/quicktime',
        'application/pdf',
    ];

    /**
     * Legacy: the old flat per-file ceiling (500 MB). Per-file caps are now
     * per tier and kind, in config/media.php.
     */
    public const MAX_BYTES = 500 * 1024 * 1024;
}
