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

    public const MAX_EDGE = 360;

    public const QUALITY = 72;

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

    /** Bytes of any image GD can read, as a JPEG data URI at most $edge px wide or high. */
    public function shrink(string $bytes, int $edge = self::MAX_EDGE): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, $edge / max($w, $h, 1));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $canvas = imagecreatetruecolor($nw, $nh);
        // Transparent PNGs land on paper, not black.
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 0xFA, 0xFA, 0xF8));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($canvas, null, self::QUALITY);
        $jpeg = (string) ob_get_clean();

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }
}
