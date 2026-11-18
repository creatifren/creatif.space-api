<?php

namespace App\Support\Media;

/**
 * The EXIF Orientation tag (1–8) of a JPEG, read from its APP1 segment
 * without ext-exif. Phones store portrait photos as landscape pixels plus
 * this tag; browsers apply it, GD does not, so a variant made without it
 * would lie on its side. 1 (as stored) for anything else.
 */
final class ExifOrientation
{
    public static function fromJpeg(string $bytes): int
    {
        if (strlen($bytes) < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            return 1;
        }
        $pos = 2;
        $len = strlen($bytes);
        while ($pos + 4 <= $len) {
            if ($bytes[$pos] !== "\xFF") {
                return 1;
            }
            $marker = ord($bytes[$pos + 1]);
            $size = unpack('n', substr($bytes, $pos + 2, 2))[1];
            if ($marker === 0xDA || $size < 2) {
                return 1; // start of scan: no EXIF before the image data
            }
            if ($marker === 0xE1 && substr($bytes, $pos + 4, 6) === "Exif\0\0") {
                return self::fromTiff(substr($bytes, $pos + 10, $size - 8));
            }
            $pos += 2 + $size;
        }

        return 1;
    }

    private static function fromTiff(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }
        $le = substr($tiff, 0, 2) === 'II';
        $u16 = fn (int $at) => strlen($tiff) >= $at + 2 ? unpack($le ? 'v' : 'n', substr($tiff, $at, 2))[1] : 0;
        $u32 = fn (int $at) => strlen($tiff) >= $at + 4 ? unpack($le ? 'V' : 'N', substr($tiff, $at, 4))[1] : 0;

        $ifd = $u32(4);
        $count = $u16($ifd);
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }
}
