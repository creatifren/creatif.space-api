<?php

namespace App\Support\Media;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Throwable;

/**
 * What a file really is, from its first bytes (magic numbers) — never the
 * extension or the browser's MIME.
 *
 * Post media: JPEG, PNG, WebP, GIF; MP4 and MOV, recognised by the
 * ISO-BMFF container only (an `ftyp` box and its brand, or a classic
 * QuickTime atom). Codecs (H.264/AAC) are NOT checked: they live in the
 * `moov` box, which is often at the end of the file, so a head read cannot
 * see them reliably. Spaces material keeps working: PDF (%PDF-) and SVG
 * (XML text with an <svg> root).
 */
final class FileSignature
{
    /** How much of the head is read. */
    public const HEAD_BYTES = 4096;

    /** @var list<string> MP4-family major/compatible brands */
    private const MP4_BRANDS = [
        'isom', 'iso2', 'iso3', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'mp71',
        'avc1', 'M4V ', 'M4VP', 'M4VH', 'dash', 'mmp4', 'MSNV', 'NDAS', 'NDSC',
        'NDSH', 'NDSM', 'NDSP', 'NDSS', 'NDXC', 'NDXH', 'NDXM', 'NDXP', 'NDXS',
        'f4v ', 'XAVC', '3gp4', '3gp5', '3gp6', '3g2a',
    ];

    /** Atoms an old QuickTime file may open with, before (or without) ftyp. */
    private const QT_ATOMS = ['moov', 'mdat', 'wide', 'free', 'skip', 'pnot'];

    /** Families a declared MIME belongs to. */
    public static function family(string $mime): string
    {
        return match ($mime) {
            'image/jpeg', 'image/png', 'image/gif', 'image/webp' => 'image',
            'video/mp4', 'video/quicktime' => 'video',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            default => 'other',
        };
    }

    /** Post media (what retention and per-file media caps apply to). */
    public static function isPostMedia(string $mime): bool
    {
        return in_array(self::family($mime), ['image', 'video'], true);
    }

    /** The canonical extension for an accepted MIME. */
    public static function extension(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            default => null,
        };
    }

    /** The MIME these bytes are, or null when they are none we accept. */
    public static function detect(string $head): ?string
    {
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'image/gif';
        }
        if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (str_starts_with($head, '%PDF-')) {
            return 'application/pdf';
        }

        $video = self::isoBmff($head);
        if ($video !== null) {
            return $video;
        }

        return self::isSvg($head) ? 'image/svg+xml' : null;
    }

    /**
     * Do these bytes fit the declared MIME's family? Returns the detected
     * MIME (which may differ inside the family, e.g. a .mov that is MP4),
     * or null to reject.
     */
    public static function verify(string $head, string $declared): ?string
    {
        $detected = self::detect($head);

        return $detected !== null && self::family($detected) === self::family($declared) ? $detected : null;
    }

    /**
     * The first bytes of an object: a ranged GET on R2/S3, a short stream
     * read elsewhere (the local and fake disks).
     */
    public static function head(Filesystem $disk, string $path, int $bytes = self::HEAD_BYTES): ?string
    {
        try {
            if ($disk instanceof AwsS3V3Adapter) {
                $config = $disk->getConfig();
                $prefix = trim((string) ($config['root'] ?? ''), '/');
                $result = $disk->getClient()->getObject([
                    'Bucket' => $config['bucket'],
                    'Key' => ($prefix === '' ? '' : $prefix.'/').$path,
                    'Range' => 'bytes=0-'.($bytes - 1),
                ]);

                return (string) $result['Body'];
            }

            $stream = $disk->readStream($path);
            if (! is_resource($stream)) {
                return null;
            }
            $head = (string) fread($stream, $bytes);
            fclose($stream);

            return $head;
        } catch (Throwable) {
            return null;
        }
    }

    private static function isoBmff(string $head): ?string
    {
        if (strlen($head) < 12) {
            return null;
        }

        $type = substr($head, 4, 4);

        if ($type === 'ftyp') {
            $size = unpack('N', substr($head, 0, 4))[1] ?? 0;
            $major = substr($head, 8, 4);
            if ($major === 'qt  ') {
                return 'video/quicktime';
            }

            $end = min(strlen($head), max(16, (int) $size));
            $brands = [$major];
            for ($i = 16; $i + 4 <= $end; $i += 4) {
                $brands[] = substr($head, $i, 4);
            }

            // HEIF/AVIF images share the container; they are not video.
            if (array_intersect($brands, ['heic', 'heix', 'mif1', 'msf1', 'avif', 'avis']) !== [] && ! in_array($major, self::MP4_BRANDS, true)) {
                return null;
            }

            if (in_array('qt  ', $brands, true) && ! in_array($major, self::MP4_BRANDS, true)) {
                return 'video/quicktime';
            }

            return array_intersect($brands, self::MP4_BRANDS) !== [] ? 'video/mp4' : null;
        }

        return in_array($type, self::QT_ATOMS, true) ? 'video/quicktime' : null;
    }

    private static function isSvg(string $head): bool
    {
        $text = ltrim(str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head);

        if (! (str_starts_with($text, '<?xml') || str_starts_with($text, '<svg') || str_starts_with($text, '<!--') || str_starts_with($text, '<!DOCTYPE svg'))) {
            return false;
        }

        // Text, not a binary with a leading "<": no NUL bytes in the head.
        return ! str_contains($text, "\0") && stripos($text, '<svg') !== false;
    }
}
