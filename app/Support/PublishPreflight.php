<?php

namespace App\Support;

use App\Exceptions\PublishPreflightFailed;
use App\Models\SocialAccount;
use App\Support\Media\FileSignature;
use App\Support\Media\MediaLinks;

/**
 * What each network needs before a post may go to it — checked before
 * anything is charged or reaches the provider, so a post that a platform
 * would refuse is named and fixed here instead of failing minutes later.
 *
 * Only rules we are sure of (Outstand's documented requirements):
 *  - Instagram, TikTok: an image or a video.
 *  - YouTube: exactly one video, nothing else.
 *  - Pinterest: an image or a video (not both), a board, one Pinterest
 *    account per post (the board is set per network), title ≤ 100.
 *  - Threads: ≤ 500 characters.
 * Media kind is read from the file extension; an unknown one is not refused.
 */
final class PublishPreflight
{
    private const VIDEO = ['mp4', 'mov', 'm4v', 'webm', 'avi', 'mkv'];

    private const IMAGE = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'bmp', 'tif', 'tiff'];

    private const NEEDS_MEDIA = ['instagram', 'tiktok', 'pinterest', 'youtube'];

    /**
     * @param  iterable<SocialAccount>  $accounts
     * @param  array<string, string>  $captions  per-account caption overrides, by account ulid
     * @param  list<array<string, mixed>>|null  $media
     * @param  array<string, array<string, mixed>>  $options  per-account network options, by account ulid
     *
     * @throws PublishPreflightFailed
     */
    public static function check(iterable $accounts, string $caption, array $captions, ?array $media, array $options = []): void
    {
        $problems = self::problems($accounts, $caption, $captions, $media, $options);

        if ($problems !== []) {
            throw new PublishPreflightFailed($problems);
        }
    }

    /**
     * @param  iterable<SocialAccount>  $accounts
     * @param  array<string, string>  $captions
     * @param  list<array<string, mixed>>|null  $media
     * @param  array<string, array<string, mixed>>  $options
     * @return list<array{account_id: string, platform: string, code: string, reason: string}>
     */
    public static function problems(iterable $accounts, string $caption, array $captions, ?array $media, array $options = []): array
    {
        $kinds = array_map(fn ($m) => self::kind((string) ($m['url'] ?? '')), $media ?? []);
        $videos = count(array_filter($kinds, fn ($k) => $k === 'video'));
        $images = count(array_filter($kinds, fn ($k) => $k === 'image'));
        $count = count($kinds);

        $problems = [];
        $pinterestSeen = 0;

        foreach ($accounts as $account) {
            $platform = $account->platform->value;
            $text = $captions[$account->ulid] ?? $caption;
            $own = $options[$account->ulid] ?? [];
            $add = function (string $code, string $reason) use (&$problems, $account, $platform): void {
                $problems[] = ['account_id' => $account->ulid, 'platform' => $platform, 'code' => $code, 'reason' => $reason];
            };

            if (in_array($platform, self::NEEDS_MEDIA, true) && $count === 0) {
                $add($platform === 'youtube' ? 'needs_video' : 'needs_media', PublishFailure::reason($platform === 'youtube' ? 'needs_video' : 'needs_media', $platform));

                continue;
            }

            if ($platform === 'youtube' && ($videos !== 1 || $images > 0 || $count > 1)) {
                $add('needs_video', $videos === 0
                    ? PublishFailure::reason('needs_video', 'youtube')
                    : 'YouTube takes exactly one video and nothing else. Send the other media to the other channels in a separate post.');
            }

            if ($platform === 'pinterest') {
                $pinterestSeen++;

                if ($videos > 0 && $images > 0) {
                    $add('media_spec', 'A Pin is either images or one video, not both. Remove one kind for Pinterest.');
                }
                if (! is_string($own['board_id'] ?? null) || $own['board_id'] === '') {
                    $add('missing_board', PublishFailure::reason('missing_board', 'pinterest'));
                }
                if (isset($own['title']) && mb_strlen((string) $own['title']) > 100) {
                    $add('content_too_long', 'The Pin title is over 100 characters. Shorten it.');
                }
                if ($pinterestSeen > 1) {
                    $add('missing_board', 'Only one Pinterest account per post — each needs its own board. Send the others in a separate post.');
                }
            }

            if ($platform === 'threads' && mb_strlen($text) > 500) {
                $add('content_too_long', 'Threads allows 500 characters; this caption has '.mb_strlen($text).'. Shorten it or set a shorter caption for Threads.');
            }
        }

        return $problems;
    }

    /**
     * 'video' | 'image' | 'unknown', from the URL path's extension — or,
     * for a library file whose key has none, from the file's MIME.
     */
    public static function kind(string $url): string
    {
        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        if ($ext === '' && ($file = MediaLinks::fileFor($url)) !== null) {
            $ext = (string) FileSignature::extension($file->mime_type);
        }

        return match (true) {
            in_array($ext, self::VIDEO, true) => 'video',
            in_array($ext, self::IMAGE, true) => 'image',
            default => 'unknown',
        };
    }
}
