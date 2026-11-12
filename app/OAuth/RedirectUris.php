<?php

namespace App\OAuth;

/**
 * Which redirect URIs an OAuth client may use — shared by Dynamic Client
 * Registration and Client ID Metadata Documents.
 *
 * https anywhere; http only on loopback (RFC 8252 native apps); a
 * private-use app scheme (cursor://, vscode://). Never a scheme a browser
 * would execute or read locally, and never a fragment.
 */
final class RedirectUris
{
    public static function acceptable(string $uri): bool
    {
        $parts = parse_url($uri);
        if ($parts === false || ! isset($parts['scheme']) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host'] ?? '');

        return match (true) {
            $scheme === 'https' => $host !== '',
            $scheme === 'http' => in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true),
            in_array($scheme, ['javascript', 'data', 'file', 'vbscript', 'blob', 'ftp', 'ws', 'wss'], true) => false,
            default => preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) === 1,
        };
    }
}
