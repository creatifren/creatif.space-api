<?php

namespace App\Logging;

use Throwable;

/**
 * Takes personal data and secrets out of what goes into the logs: email
 * addresses, people's names, tokens and keys, and what people wrote
 * (captions, comments, DMs, feedback). Ids, codes, statuses and counts stay,
 * so a log line still says what happened.
 *
 * Two passes: a context key that names such a field has its value replaced
 * whole; every other string is scanned for emails and token shapes.
 */
final class Redactor
{
    public const EMAIL = '[email]';

    public const TOKEN = '[token]';

    public const REDACTED = '[redacted]';

    /** Keys whose whole value is personal or secret (exact, case-insensitive). */
    private const KEYS = [
        // People
        'name', 'author', 'author_name', 'display_name', 'first_name', 'last_name', 'full_name',
        'approver_name', 'client_name', 'sender', 'sender_name', 'recipient', 'recipient_name',
        'email', 'phone',
        // What people wrote
        'caption', 'captions', 'text', 'body', 'comment', 'comments', 'content', 'dm', 'reply',
        'message_text', 'feedback', 'note', 'suggestion', 'brief', 'transcript',
        // Secrets
        'password', 'password_confirmation', 'token', 'access_token', 'refresh_token', 'id_token',
        'auth_token', 'api_key', 'secret', 'client_secret', 'authorization', 'cookie', 'set-cookie',
        'signature', 'x-mcp-secret', 'code_verifier',
    ];

    /** Key suffixes that mark the same (e.g. approver_email, webhook_secret). */
    private const SUFFIXES = ['_email', '_token', '_secret', '_password', '_api_key', '_caption', '_text'];

    /** @var array<string, string> pattern => replacement, applied in order */
    private const PATTERNS = [
        // Bearer / Basic credentials in a header-ish string.
        '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i' => '$1 '.self::TOKEN,
        // JWTs (Passport access tokens, Google ID tokens).
        '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/' => self::TOKEN,
        // Personal API keys.
        '/\bcs_live_[A-Za-z0-9]+/' => self::TOKEN,
        // Secrets in a URL query.
        '/([?&](?:token|access_token|refresh_token|id_token|code|key|api_key|signature|password|secret)=)[^&\s"\'#]+/i' => '$1'.self::REDACTED,
        // Long opaque hex (Passport refresh tokens, raw secrets).
        '/\b[a-f0-9]{40,}\b/i' => self::TOKEN,
        // Email addresses.
        '/[A-Z0-9._%+-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}/i' => self::EMAIL,
    ];

    public static function string(string $value): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $value);
    }

    public static function sensitiveKey(string|int $key): bool
    {
        if (! is_string($key)) {
            return false;
        }
        $key = strtolower($key);
        if (in_array($key, self::KEYS, true)) {
            return true;
        }
        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Any log value: arrays walked (keys checked), strings scanned,
     * exceptions turned into their redacted class / message / place /
     * trace, other objects left for the formatter.
     */
    public static function value(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return self::REDACTED;
        }
        if (is_string($value)) {
            return self::string($value);
        }
        if ($value instanceof Throwable) {
            return self::exception($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::sensitiveKey($k) && $v !== null && $v !== '' ? self::REDACTED : self::value($v, $depth + 1);
            }

            return $out;
        }
        if ($value instanceof \JsonSerializable) {
            return self::value($value->jsonSerialize(), $depth + 1);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function exception(Throwable $e): array
    {
        return array_filter([
            'class' => $e::class,
            'message' => self::string($e->getMessage()),
            'code' => $e->getCode(),
            'file' => $e->getFile().':'.$e->getLine(),
            'trace' => self::string($e->getTraceAsString()),
            'previous' => $e->getPrevious() ? self::exception($e->getPrevious()) : null,
        ], fn ($v) => $v !== null);
    }
}
