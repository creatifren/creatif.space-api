<?php

namespace App\Support\Ai;

/** What a caption provider returns: the caption, and hashtags without duplicates. */
final readonly class CaptionDraft
{
    /**
     * @param  list<string>  $hashtags  each starting with "#"
     */
    public function __construct(
        public string $caption,
        public array $hashtags,
    ) {}

    /**
     * Tidy whatever a model sent back: "#" in front, no spaces, no repeats,
     * at most $limit.
     *
     * @param  array<int, mixed>  $raw
     * @return list<string>
     */
    public static function hashtags(array $raw, int $limit): array
    {
        $tags = [];

        foreach ($raw as $tag) {
            if (! is_string($tag)) {
                continue;
            }
            $tag = preg_replace('/[^\p{L}\p{N}_]/u', '', $tag) ?? '';
            if ($tag !== '' && ! isset($tags[mb_strtolower($tag)])) {
                $tags[mb_strtolower($tag)] = '#'.$tag;
            }
        }

        return array_slice(array_values($tags), 0, max(0, $limit));
    }
}
