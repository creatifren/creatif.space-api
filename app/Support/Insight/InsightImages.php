<?php

namespace App\Support\Insight;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Pictures for a report, embedded as data URIs: the renderer never reaches
 * the network itself (remote fetching is off), and a thumbnail is shrunk to
 * a small JPEG so a report with every top post stays well under 5 MB.
 * Anything that is not a still image we can read — a video, a dead link —
 * comes back null and the template draws an empty tile instead.
 */
class InsightImages
{
    public const MAX_SOURCE_BYTES = 8 * 1024 * 1024;

    /** Thumbnails are square: cover-cropped from the centre. */
    public const MAX_EDGE = 360;

    public const QUALITY = 72;

    /**
     * The first of $urls that is a still image we can read, as a square
     * thumbnail (a post's media may open with a video).
     *
     * @param  iterable<mixed>  $urls
     */
    public function firstImage(iterable $urls, int $edge = self::MAX_EDGE): ?string
    {
        foreach ($urls as $url) {
            $thumb = is_string($url) ? $this->thumbnail($url, $edge) : null;
            if ($thumb !== null) {
                return $thumb;
            }
        }

        return null;
    }

    public function thumbnail(?string $url, int $edge = self::MAX_EDGE): ?string
    {
        if ($url === null || ! preg_match('#^https://#i', $url)) {
            return null;
        }

        try {
            $response = Http::timeout(10)->connectTimeout(5)->get($url);
        } catch (Throwable) {
            return null;
        }

        $body = $response->successful() ? $response->body() : '';

        if ($body === '' || strlen($body) > self::MAX_SOURCE_BYTES) {
            return null;
        }

        return $this->shrink($body, $edge);
    }

    /** Bytes of any image GD can read, cover-cropped to a square JPEG data URI of at most $edge px. */
    public function shrink(string $bytes, int $edge = self::MAX_EDGE): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        // Cover: the largest centred square, scaled down to $edge.
        $w = imagesx($image);
        $h = imagesy($image);
        $side = max(1, min($w, $h));
        $size = max(1, min($edge, $side));

        $canvas = imagecreatetruecolor($size, $size);
        // Transparent PNGs land on paper, not black.
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 0xFA, 0xFA, 0xF8));
        imagecopyresampled($canvas, $image, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $size, $size, $side, $side);

        ob_start();
        imagejpeg($canvas, null, self::QUALITY);
        $jpeg = (string) ob_get_clean();

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }
}
