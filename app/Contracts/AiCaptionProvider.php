<?php

namespace App\Contracts;

use App\Exceptions\AiUnavailable;
use App\Models\AiStyleProfile;
use App\Support\Ai\CaptionDraft;

/**
 * Writes a caption and hashtags from a brief, in the user's style.
 * OpenAiCaptionProvider in production, FakeAiCaptionProvider in tests.
 */
interface AiCaptionProvider
{
    /**
     * @param  string  $brief  what the post is about, in the user's words
     * @param  string|null  $platform  a SocialPlatform value, when known
     *
     * @throws AiUnavailable when the provider is not set up or did not answer
     */
    public function caption(string $brief, ?string $platform, ?AiStyleProfile $style): CaptionDraft;
}
