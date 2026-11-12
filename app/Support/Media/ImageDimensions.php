<?php

namespace App\Support\Media;

/**
 * Width and height of an image from its first bytes, for an upload whose
 * client sent none (B14: files uploaded through MCP read width/height
 * null). PNG, GIF and WebP carry them in the header; a JPEG's frame
 * header sits after its EXIF block, inside the first 64 KiB for the
 * photos people post.
 */
final class ImageDimensions
{
    public const HEAD_BYTES = 65536;

    /** @return array{0: int, 1: int}|null */
    public static function fromHead(?string $bytes): ?array
    {
        if ($bytes === null || $bytes === '') {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        if (! is_array($info) || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        return [(int) $info[0], (int) $info[1]];
    }
}
