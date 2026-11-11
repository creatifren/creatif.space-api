<?php

namespace App\Services\Ai;

use App\Contracts\AiCaptionProvider;
use App\Exceptions\AiUnavailable;
use App\Models\AiStyleProfile;
use App\Support\Ai\CaptionDraft;

/**
 * No network, no key: answers from the brief, and remembers each call so a
 * test can see what the endpoint passed in (brief, platform, style profile).
 * AI_CAPTION_PROVIDER=fake selects it.
 */
class FakeAiCaptionProvider implements AiCaptionProvider
{
    /** @var list<array{brief: string, platform: ?string, style: ?AiStyleProfile}> */
    public array $calls = [];

    public bool $failing = false;

    public function caption(string $brief, ?string $platform, ?AiStyleProfile $style): CaptionDraft
    {
        $this->calls[] = ['brief' => $brief, 'platform' => $platform, 'style' => $style];

        if ($this->failing) {
            throw AiUnavailable::noAnswer();
        }

        $count = $style !== null ? $style->hashtag_count : AiStyleProfile::DEFAULT_HASHTAGS;
        $words = preg_split('/\s+/u', $brief, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tags = array_merge($words, array_map(fn (int $i) => "tag{$i}", range(1, max(1, $count))));

        return new CaptionDraft(
            trim(($style?->tone ? "[{$style->tone}] " : '').$brief),
            CaptionDraft::hashtags($tags, $count),
        );
    }
}
