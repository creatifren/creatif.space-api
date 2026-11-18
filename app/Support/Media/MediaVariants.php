<?php

namespace App\Support\Media;

use App\Jobs\GenerateMediaVariants;
use App\Models\File;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use GdImage;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Small copies of a library image (or a video's poster frame) for the
 * client approval page and plan links, which used to load every original:
 * a 57-image plan pulled ~200 MB (q202611141319).
 *
 *   thumb   480 px wide  — grid and calendar cells
 *   feed   1080 px wide  — the feed column, a carousel slide
 *   poster 1080 px wide  — a video's first frame (videos only)
 *
 * Each as WebP (when this PHP's GD writes WebP) and JPEG (always: the
 * fallback). Never upscaled. Made once, stored next to the original in the
 * same bucket under a per-generation token (u/<owner>/<file>.<token>.thumb.webp)
 * with a one-year immutable Cache-Control: new bytes (a new version) get a
 * new token, so a cached copy is never stale. The originals stay for the
 * viewer / zoom. Recorded in files.variants:
 *
 *   {"v":1, "token":"ab12cd", "webp":true,
 *    "thumb":{"w":480,"h":600}, "feed":{"w":1080,"h":1350}, "poster":{...}}
 *   {"v":1, "error":"unreadable"}     — tried, nothing to make; not retried by the backfill
 */
final class MediaVariants
{
    public const VERSION = 1;

    /** Target width per variant. */
    public const SIZES = ['thumb' => 480, 'feed' => 1080];

    public const QUALITY_JPEG = 80;

    public const QUALITY_WEBP = 78;

    /** Above this many pixels GD would need too much memory: left to the original. */
    public const MAX_PIXELS = 50_000_000;

    public const CACHE_CONTROL = 'public, max-age=31536000, immutable';

    /**
     * Signed URLs are cut on a fixed grid (every WINDOW_HOURS), so the same
     * variant has the same URL for hours and the browser cache can hit;
     * each stays valid at least VALID_HOURS - WINDOW_HOURS.
     */
    public const WINDOW_HOURS = 6;

    public const VALID_HOURS = 36;

    public static function isCandidate(File $file): bool
    {
        return $file->status === File::STATUS_READY
            && (str_starts_with($file->mime_type, 'image/') || str_starts_with($file->mime_type, 'video/'));
    }

    /** Done (made, or tried with nothing to make) for these bytes. */
    public static function settled(File $file): bool
    {
        $v = $file->variants;

        return is_array($v) && ($v['v'] ?? null) === self::VERSION;
    }

    /**
     * Make the variants for $file and record them. Never throws: a failure
     * is recorded and logged, and the page keeps using the original.
     *
     * @return array<string, mixed> what was recorded
     */
    public static function generate(File $file): array
    {
        try {
            $result = str_starts_with($file->mime_type, 'video/') ? self::fromVideo($file) : self::fromImage($file);
        } catch (Throwable $e) {
            Log::warning('media.variants_failed', ['file' => $file->ulid, 'error' => $e->getMessage()]);
            $result = ['v' => self::VERSION, 'error' => 'failed'];
        }

        $old = $file->variants;
        $file->forceFill(['variants' => $result])->saveQuietly();
        // A previous generation's objects (the bytes were replaced).
        foreach ([$old, is_array($old) ? ($old['previous'] ?? null) : null] as $gone) {
            if (is_array($gone) && is_string($gone['token'] ?? null) && $gone['token'] !== ($result['token'] ?? null)) {
                self::deleteObjects($file, $gone);
            }
        }

        return $result;
    }

    /** Remove the variant objects: the file is purged. */
    public static function delete(File $file): void
    {
        if (is_array($file->variants)) {
            self::deleteObjects($file, $file->variants);
            if (is_array($file->variants['previous'] ?? null)) {
                self::deleteObjects($file, $file->variants['previous']);
            }
        }
    }

    /**
     * The file's bytes changed (a new version, a restore): the page goes back
     * to the original until the new variants are made; the old objects are
     * removed then. Saves the file and queues the job.
     */
    public static function invalidate(File $file): void
    {
        $old = $file->variants;
        $previous = is_array($old) && is_string($old['token'] ?? null) ? $old : (is_array($old) ? ($old['previous'] ?? null) : null);
        $file->forceFill(['variants' => is_array($previous) ? ['previous' => $previous] : null])->saveQuietly();
        GenerateMediaVariants::dispatch($file->id);
    }

    /**
     * The URLs to show, or null when there are none (the page falls back to
     * the original).
     *
     * @return array{thumb: array{webp: ?string, jpeg: string, width: int, height: int}|null, feed: array{webp: ?string, jpeg: string, width: int, height: int}|null, poster: array{webp: ?string, jpeg: string, width: int, height: int}|null}|null
     */
    public static function urls(File $file): ?array
    {
        $v = $file->variants;
        if (! is_array($v) || ! is_string($v['token'] ?? null)) {
            return null;
        }
        $out = ['thumb' => null, 'feed' => null, 'poster' => null];
        foreach (array_keys($out) as $name) {
            $size = $v[$name] ?? null;
            if (! is_array($size)) {
                continue;
            }
            $out[$name] = [
                'webp' => ($v['webp'] ?? false) === true ? self::signedUrl($file, self::key($file, $v['token'], $name, 'webp')) : null,
                'jpeg' => self::signedUrl($file, self::key($file, $v['token'], $name, 'jpg')),
                'width' => (int) $size['w'],
                'height' => (int) $size['h'],
            ];
        }

        return $out;
    }

    /** u/<owner>/<file>.jpg → u/<owner>/<file>.<token>.<name>.<ext> */
    public static function key(File $file, string $token, string $name, string $ext): string
    {
        $dir = pathinfo($file->path, PATHINFO_DIRNAME);
        $base = pathinfo($file->path, PATHINFO_FILENAME);

        return ($dir === '.' || $dir === '' ? '' : $dir.'/')."{$base}.{$token}.{$name}.{$ext}";
    }

    public static function webpSupported(): bool
    {
        return function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false) === true;
    }

    /** @return array<string, mixed> */
    private static function fromImage(File $file): array
    {
        if (! function_exists('imagecreatefromstring')) {
            return ['v' => self::VERSION, 'error' => 'no_gd'];
        }
        if ($file->width !== null && $file->height !== null && $file->width * $file->height > self::MAX_PIXELS) {
            return ['v' => self::VERSION, 'error' => 'too_large'];
        }
        $bytes = Storage::disk($file->disk)->get($file->path);
        if (! is_string($bytes) || $bytes === '') {
            return ['v' => self::VERSION, 'error' => 'missing'];
        }

        return self::render($file, $bytes, ['thumb', 'feed']);
    }

    /** @return array<string, mixed> */
    private static function fromVideo(File $file): array
    {
        $ffmpeg = self::ffmpeg();
        if ($ffmpeg === null || ! function_exists('imagecreatefromstring')) {
            return ['v' => self::VERSION, 'error' => 'no_ffmpeg'];
        }
        $out = tempnam(sys_get_temp_dir(), 'poster').'.jpg';
        try {
            // A frame just after the start (a black first frame is common);
            // ffmpeg reads the signed URL with range requests, not the whole file.
            $source = Storage::disk($file->disk)->temporaryUrl($file->path, now()->addMinutes(15));
            $run = Process::timeout(60)->run([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-ss', '0.5', '-i', $source,
                '-frames:v', '1', '-vf', 'scale=min(1080\,iw):-2', '-q:v', '3', $out]);
            if (! $run->successful() || ! is_file($out) || filesize($out) === 0) {
                // Shorter than half a second: the very first frame.
                $run = Process::timeout(60)->run([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-i', $source,
                    '-frames:v', '1', '-vf', 'scale=min(1080\,iw):-2', '-q:v', '3', $out]);
            }
            if (! $run->successful() || ! is_file($out) || filesize($out) === 0) {
                return ['v' => self::VERSION, 'error' => 'no_frame'];
            }

            return self::render($file, (string) file_get_contents($out), ['thumb', 'poster']);
        } finally {
            @unlink($out);
        }
    }

    /**
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    private static function render(File $file, string $bytes, array $names): array
    {
        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            return ['v' => self::VERSION, 'error' => 'unreadable'];
        }
        $image = self::orient($image, ExifOrientation::fromJpeg($bytes));
        unset($bytes);

        $webp = self::webpSupported();
        $token = Str::lower(Str::random(8));
        $disk = Storage::disk($file->disk);
        $result = ['v' => self::VERSION, 'token' => $token, 'webp' => $webp];
        $written = [];

        try {
            foreach ($names as $name) {
                $target = self::SIZES[$name === 'poster' ? 'feed' : $name];
                $w = imagesx($image);
                $h = imagesy($image);
                $tw = min($target, $w);
                $th = max(1, (int) round($h * $tw / $w));
                $canvas = imagecreatetruecolor($tw, $th);
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagecopyresampled($canvas, $image, 0, 0, 0, 0, $tw, $th, $w, $h);

                if ($webp) {
                    $key = self::key($file, $token, $name, 'webp');
                    $disk->put($key, self::encode(fn () => imagewebp($canvas, null, self::QUALITY_WEBP)),
                        ['ContentType' => 'image/webp', 'CacheControl' => self::CACHE_CONTROL]);
                    $written[] = $key;
                }
                // JPEG has no alpha: transparent areas land on white.
                $flat = imagecreatetruecolor($tw, $th);
                imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
                imagecopy($flat, $canvas, 0, 0, 0, 0, $tw, $th);
                imageinterlace($flat, true);
                $key = self::key($file, $token, $name, 'jpg');
                $disk->put($key, self::encode(fn () => imagejpeg($flat, null, self::QUALITY_JPEG)),
                    ['ContentType' => 'image/jpeg', 'CacheControl' => self::CACHE_CONTROL]);
                $written[] = $key;

                $result[$name] = ['w' => $tw, 'h' => $th];
            }
        } catch (Throwable $e) {
            $disk->delete($written);

            throw $e;
        }

        return $result;
    }

    private static function encode(callable $write): string
    {
        ob_start();
        $write();

        return (string) ob_get_clean();
    }

    /** Apply an EXIF orientation (1–8), as a browser does for the original. */
    private static function orient(GdImage $image, int $orientation): GdImage
    {
        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };
        if (! $rotated instanceof GdImage) {
            return $image;
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
        }

        return $rotated;
    }

    /** @param  array<string, mixed>  $variants */
    private static function deleteObjects(File $file, array $variants): void
    {
        $token = $variants['token'] ?? null;
        if (! is_string($token)) {
            return;
        }
        $keys = [];
        foreach (['thumb', 'feed', 'poster'] as $name) {
            if (is_array($variants[$name] ?? null)) {
                $keys[] = self::key($file, $token, $name, 'jpg');
                $keys[] = self::key($file, $token, $name, 'webp');
            }
        }
        if ($keys !== []) {
            Storage::disk($file->disk)->delete($keys);
        }
    }

    /**
     * A signed GET whose URL is the same for WINDOW_HOURS (signing time and
     * expiry both on a fixed grid), so the browser cache can serve it again.
     */
    private static function signedUrl(File $file, string $key): string
    {
        $window = self::WINDOW_HOURS * 3600;
        $start = CarbonImmutable::createFromTimestamp(intdiv(now()->getTimestamp(), $window) * $window);
        $expires = $start->addHours(self::VALID_HOURS);
        $disk = Storage::disk($file->disk);

        if ($disk instanceof AwsS3V3Adapter) {
            /** @var S3Client $client */
            $client = $disk->getClient();
            $config = $disk->getConfig();
            $prefix = trim((string) ($config['root'] ?? ''), '/');
            $command = $client->getCommand('GetObject', [
                'Bucket' => $config['bucket'],
                'Key' => ($prefix === '' ? '' : $prefix.'/').$key,
            ]);

            return (string) $client->createPresignedRequest($command, $expires, ['start_time' => $start->getTimestamp()])->getUri();
        }

        return $disk->temporaryUrl($key, $expires);
    }

    private static function ffmpeg(): ?string
    {
        static $path = false;
        if ($path === false) {
            $path = (new ExecutableFinder)->find('ffmpeg');
        }

        return $path;
    }
}
