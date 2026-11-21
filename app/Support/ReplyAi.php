<?php

namespace App\Support;

use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Short replies to the audience, written by Claude on the Standard model
 * (services.ai.model_standard, Sonnet — Rizal 21 Nov). Used by the thread's
 * "Suggest replies" (3) and Inbox "Reply all with AI" (1 each). Never sends.
 */
class ReplyAi
{
    private const ANTHROPIC_VERSION = '2023-06-01';

    public static function ready(): bool
    {
        $c = config('services.ai');

        return (bool) ($c['key'] && $c['base_url'] && ($c['model_standard'] ?? $c['model']));
    }

    /** The thread as plain lines: the last 10 DMs, or the post caption + the comment. '' = nothing to answer. */
    public static function context(SocialConversation|SocialComment $row): string
    {
        if ($row instanceof SocialConversation) {
            return $row->messages()->latest('id')->limit(10)->get(['direction', 'content'])
                ->reverse()
                ->filter(fn (SocialMessage $m) => trim((string) $m->content) !== '')
                ->map(fn (SocialMessage $m) => ($m->direction === SocialMessage::OUT ? 'Brand: ' : 'Them: ').mb_substr((string) $m->content, 0, 1000))
                ->implode("\n");
        }

        $text = trim((string) $row->text);
        if ($text === '') {
            return '';
        }
        $caption = trim((string) ($row->post->caption ?? ''));

        return ($caption !== '' ? 'Brand post: '.mb_substr($caption, 0, 1000)."\n" : '').'Them (comment): '.mb_substr($text, 0, 1000);
    }

    /**
     * Up to $count short replies; [] when the AI did not answer.
     *
     * @return list<string>
     */
    public static function write(string $context, int $count): array
    {
        $c = config('services.ai');
        $prompt = 'You help a social media manager answer their audience on behalf of a brand. '
            .($count === 1
                ? 'Write exactly 1 short reply to the last message from the other person'
                : "Write exactly {$count} different short replies to the last message from the other person")
            .', in the language they wrote in. Friendly, natural, no hashtags, no made-up facts, prices or promises. '
            .($count === 1 ? 'Reply with the reply only, without quotes.' : "Reply with the {$count} replies only, one per line, without numbers or quotes.");

        try {
            $response = Http::withHeaders([
                'x-api-key' => $c['key'],
                'anthropic-version' => self::ANTHROPIC_VERSION,
            ])
                ->timeout(30)
                ->post(rtrim((string) $c['base_url'], '/').'/messages', [
                    'model' => $c['model_standard'] ?: $c['model'],
                    'max_tokens' => 200 * $count,
                    'system' => $prompt,
                    'messages' => [['role' => 'user', 'content' => $context]],
                ]);
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $text = collect((array) $response->json('content'))->where('type', 'text')->pluck('text')->implode('');
        $lines = $count === 1 ? [trim($text)] : (preg_split('/\R+/u', $text) ?: []);

        return collect($lines)
            ->map(fn ($l) => trim((string) preg_replace('/^\s*(?:\d+[.)]|[-*•])\s*/u', '', (string) $l), " \t\"'“”"))
            ->filter()
            ->take($count)
            ->values()
            ->all();
    }
}
