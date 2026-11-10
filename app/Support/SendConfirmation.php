<?php

namespace App\Support;

use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The confirm_token of a send preview (MCP M2).
 *
 * GET /social-posts/{post}/send-preview hands one out; a send made with a
 * bearer token (connected app or API key) must bring it back. It is an
 * HMAC (app key) over the user, the post, its destinations (target ids)
 * and the Credit price, valid ten minutes: if any of those moved since the
 * user saw the preview, the send is refused as preview_stale (409) and the
 * user has to see the new numbers first. The app, signed in with the
 * session cookie, never needs one.
 */
final class SendConfirmation
{
    public const TTL_MINUTES = 10;

    /** A bearer-token caller (MCP / API key) — the same test the daily cap uses. */
    public static function viaToken(Request $request): bool
    {
        return $request->attributes->has('mcp_token') || $request->bearerToken() !== null;
    }

    /**
     * The post's destinations in a fixed order, each with its Credit price.
     *
     * @return list<array{target: SocialPostTarget, credits: int}>
     */
    public static function priced(SocialPost $post): array
    {
        return $post->targets()->with('account')->orderBy('id')->get()
            ->map(fn (SocialPostTarget $t) => ['target' => $t, 'credits' => Credits::priceFor($post, $t)])
            ->values()
            ->all();
    }

    public static function issue(User $user, SocialPost $post): string
    {
        $body = self::encode(self::claims($user, $post) + ['exp' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp()]);

        return $body.'.'.self::sign($body);
    }

    /** Does the token confirm what this send would do right now? */
    public static function matches(User $user, SocialPost $post, string $token): bool
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

        return $claims === self::claims($user, $post);
    }

    /**
     * @return array{u: int, p: string, t: list<int>, c: int}
     */
    private static function claims(User $user, SocialPost $post): array
    {
        $priced = self::priced($post);

        return [
            'u' => $user->id,
            'p' => $post->ulid,
            't' => array_map(fn ($p) => $p['target']->id, $priced),
            'c' => (int) array_sum(array_column($priced, 'credits')),
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
        return hash_hmac('sha256', 'send-confirmation|'.$body, (string) config('app.key'));
    }
}
