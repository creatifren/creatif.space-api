<?php

namespace App\Support;

/**
 * Whether POST /api/v1/ai/caption would answer: switched on
 * (AI_CAPTION_ENABLED) and, for the OpenAI provider, a key, a model and a
 * base URL set — the same checks as AiCaptionController (404) and
 * OpenAiCaptionProvider (503 not set up). The plan's ai_caption feature is
 * a separate gate (402).
 */
final class AiCaptionReady
{
    public static function check(): bool
    {
        if (! (bool) config('ai.caption.enabled')) {
            return false;
        }

        if (config('ai.caption.provider') === 'fake') {
            return true;
        }

        return (bool) config('ai.openai.key') && (bool) config('ai.openai.caption_model') && (bool) config('ai.openai.base_url');
    }
}
