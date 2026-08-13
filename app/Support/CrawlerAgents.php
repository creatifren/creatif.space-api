<?php

namespace App\Support;

/**
 * Bots that read pages so an assistant can answer about them. Kept out of
 * the view count and given their own number instead — a creator's traffic
 * figure must be people.
 *
 * ponytail: the beacon only sees crawlers that execute JavaScript, and most
 * do not. The hits shown are true but low. Add a server-side log tap if the
 * number ever needs to be complete.
 */
final class CrawlerAgents
{
    /**
     * Substring → the name to show. Matched case-insensitively, longest
     * first would be over-engineering: no two entries overlap.
     *
     * @var array<string, string>
     */
    private const AGENTS = [
        'gptbot' => 'GPTBot',
        'oai-searchbot' => 'OAI-SearchBot',
        'chatgpt-user' => 'ChatGPT-User',
        'claudebot' => 'ClaudeBot',
        'claude-web' => 'Claude-Web',
        'anthropic-ai' => 'anthropic-ai',
        'perplexitybot' => 'PerplexityBot',
        'google-extended' => 'Google-Extended',
        'googlebot' => 'Googlebot',
        'bingbot' => 'Bingbot',
        'applebot' => 'Applebot',
        'amazonbot' => 'Amazonbot',
        'ccbot' => 'CCBot',
        'bytespider' => 'Bytespider',
        'meta-externalagent' => 'meta-externalagent',
        'cohere-ai' => 'cohere-ai',
        'youbot' => 'YouBot',
        'duckassistbot' => 'DuckAssistBot',
    ];

    /**
     * @return array{is_crawler: bool, name: string|null}
     */
    public static function detect(?string $userAgent): array
    {
        $haystack = strtolower($userAgent ?? '');

        foreach (self::AGENTS as $needle => $name) {
            if ($haystack !== '' && str_contains($haystack, $needle)) {
                return ['is_crawler' => true, 'name' => $name];
            }
        }

        return ['is_crawler' => false, 'name' => null];
    }

    /**
     * Which product a referring host belongs to, for the "people arrived
     * from an AI answer" table. Null when the host is an ordinary site.
     */
    public static function assistantFor(string $host): ?string
    {
        return match (true) {
            str_contains($host, 'chatgpt.com'), str_contains($host, 'openai.com') => 'ChatGPT',
            str_contains($host, 'claude.ai') => 'Claude',
            str_contains($host, 'perplexity.ai') => 'Perplexity',
            str_contains($host, 'gemini.google.com'), str_contains($host, 'bard.google.com') => 'Gemini',
            str_contains($host, 'copilot.microsoft.com') => 'Copilot',
            default => null,
        };
    }
}
