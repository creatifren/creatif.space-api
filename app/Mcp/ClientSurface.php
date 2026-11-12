<?php

namespace App\Mcp;

/**
 * Which app an OAuth client is, where it changes what the MCP server says.
 *
 * ChatGPT (directory rules) gets neutral plan errors with no upgrade links
 * or pricing. A client is ChatGPT when its registered (DCR) name says so, or
 * any of its redirect URIs is on an OpenAI / ChatGPT host.
 */
final class ClientSurface
{
    public const CHATGPT = 'chatgpt';

    public const OTHER = 'other';

    /** Exact hosts, and parents whose subdomains count too. */
    private const CHATGPT_HOSTS = ['chatgpt.com', 'chat.openai.com', 'openai.com'];

    /**
     * @param  iterable<mixed>  $redirectUris
     */
    public static function of(?string $clientName, iterable $redirectUris = []): string
    {
        if ($clientName !== null && preg_match('/chat\s*gpt|openai/i', $clientName) === 1) {
            return self::CHATGPT;
        }

        foreach ($redirectUris as $uri) {
            $host = is_string($uri) ? strtolower((string) parse_url($uri, PHP_URL_HOST)) : '';
            foreach (self::CHATGPT_HOSTS as $known) {
                if ($host === $known || str_ends_with($host, '.'.$known)) {
                    return self::CHATGPT;
                }
            }
        }

        return self::OTHER;
    }
}
