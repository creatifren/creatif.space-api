<?php

namespace App\Services\Ai;

use App\Contracts\AiCaptionProvider;
use App\Exceptions\AiUnavailable;
use App\Models\AiStyleProfile;
use App\Support\Ai\CaptionDraft;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Chat completions with a JSON answer: {"caption": "...", "hashtags": [...]}.
 * Key, base URL and model come from config/ai.php only.
 */
class OpenAiCaptionProvider implements AiCaptionProvider
{
    public function caption(string $brief, ?string $platform, ?AiStyleProfile $style): CaptionDraft
    {
        $config = (array) config('ai.openai');
        $key = $config['key'] ?? null;
        $model = $config['caption_model'] ?? null;
        $baseUrl = $config['base_url'] ?? null;

        if (! $key || ! $model || ! $baseUrl) {
            throw AiUnavailable::notSetUp();
        }

        $count = $style !== null ? $style->hashtag_count : AiStyleProfile::DEFAULT_HASHTAGS;

        try {
            $response = Http::withToken((string) $key)
                ->timeout((int) ($config['timeout'] ?? 30))
                ->post(rtrim((string) $baseUrl, '/').'/chat/completions', [
                    'model' => $model,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $this->system($platform, $style, $count)],
                        ['role' => 'user', 'content' => $brief],
                    ],
                ]);
        } catch (ConnectionException) {
            throw AiUnavailable::noAnswer();
        }

        if ($response->failed()) {
            Log::warning('ai.caption_failed', ['status' => $response->status()]);

            throw AiUnavailable::noAnswer();
        }

        $answer = json_decode((string) $response->json('choices.0.message.content'), true);
        $caption = is_array($answer) ? trim((string) ($answer['caption'] ?? '')) : '';

        if ($caption === '') {
            throw AiUnavailable::noAnswer();
        }

        return new CaptionDraft($caption, CaptionDraft::hashtags((array) ($answer['hashtags'] ?? []), $count));
    }

    private function system(?string $platform, ?AiStyleProfile $style, int $count): string
    {
        $lines = [
            'You write social media captions.',
            'Reply with JSON only: {"caption": string, "hashtags": string[]}.',
            'Put hashtags only in "hashtags", never in the caption.',
            "Give exactly {$count} hashtags.",
        ];

        if ($platform !== null) {
            $lines[] = "The post is for {$platform}.";
        }

        if ($style !== null) {
            if ($style->tone) {
                $lines[] = "Tone: {$style->tone}.";
            }
            $lines[] = match ($style->emoji_use) {
                'none' => 'Use no emoji.',
                'heavy' => 'Use emoji generously.',
                default => 'Use a few emoji at most.',
            };
            if ($style->language) {
                $lines[] = "Write in this language: {$style->language}.";
            }
            $samples = array_slice(array_filter((array) $style->sample_captions, 'is_string'), 0, AiStyleProfile::MAX_SAMPLES);
            if ($samples !== []) {
                $lines[] = "Match the voice of these captions the user wrote:\n---\n".implode("\n---\n", $samples)."\n---";
            }
        }

        if ($style === null || ! $style->language) {
            $lines[] = 'Write in the language of the brief.';
        }

        return implode("\n", $lines);
    }
}
