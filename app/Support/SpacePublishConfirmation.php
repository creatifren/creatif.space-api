<?php

namespace App\Support;

use App\Mcp\TokenContext;
use App\Models\Space;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The confirm_token of a Space publish preview (MCP publish_space).
 *
 * GET /spaces/{space}/publish-preview hands one out; a publish made by a
 * connected app or API key must bring it back. It is an HMAC (app key) over
 * the user, the Space and what goes live with it — title, address,
 * visibility, password, expiry and the files in it — valid ten minutes. If
 * any of those moved since the user saw the preview, the publish is
 * refused as preview_stale (409) and the user has to see it again. The app,
 * signed in with the session cookie, never needs one.
 */
final class SpacePublishConfirmation
{
    public const TTL_MINUTES = 10;

    /** A connected app or API key — the callers EnforceTokenScope fences. */
    public static function viaToken(Request $request): bool
    {
        return $request->attributes->get('mcp_token') instanceof TokenContext;
    }

    public static function issue(User $user, Space $space): string
    {
        $body = self::encode(self::claims($user, $space) + ['exp' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp()]);

        return $body.'.'.self::sign($body);
    }

    /** Does the token confirm what this publish would put live right now? */
    public static function matches(User $user, Space $space, string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || ! hash_equals(self::sign($parts[0]), $parts[1])) {
            return false;
        }

        $claims = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (! is_array($claims) || ! is_int($claims['exp'] ?? null) || $claims['exp'] < now()->getTimestamp()) {
            return false;
        }
        unset($claims['exp']);

        return $claims === self::claims($user, $space);
    }

    /**
     * @return array{u: int, s: string, f: string}
     */
    private static function claims(User $user, Space $space): array
    {
        $items = $space->items()->orderBy('id')->pluck('file_id')->all();

        return [
            'u' => $user->id,
            's' => $space->ulid,
            // What the visitor gets: a change to any of it is a new preview.
            'f' => hash('sha256', (string) json_encode([
                $space->title,
                $space->slug,
                $space->visibility->value,
                $space->password_hash,
                $space->expires_at?->getTimestamp(),
                $items,
            ])),
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private static function encode(array $claims): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');
    }

    private static function sign(string $body): string
    {
        return hash_hmac('sha256', 'space-publish-confirmation|'.$body, (string) config('app.key'));
    }
}
