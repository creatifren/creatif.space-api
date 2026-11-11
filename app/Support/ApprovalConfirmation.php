<?php

namespace App\Support;

use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\User;

/**
 * The confirm_token of an MCP decision preview (decide_approval).
 *
 * GET /me/approvals/{post}/preview hands one out; POST .../decision must
 * bring it back. An HMAC (app key) over who decides, on which link, which
 * post, which decision and reason, and what the post says (caption, media,
 * slot), valid ten minutes. If the post changed since the approver saw the
 * preview, the decision is refused as preview_stale (409): they must see
 * the new version first. The approval state is not part of it, so the same
 * confirmed decision sent twice is answered, not refused (idempotent).
 */
final class ApprovalConfirmation
{
    public const TTL_MINUTES = 10;

    public static function issue(User $user, SocialApprovalLink $link, SocialPost $post, string $decision, ?string $reason): string
    {
        $body = self::encode(self::claims($user, $link, $post, $decision, $reason) + ['exp' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp()]);

        return $body.'.'.self::sign($body);
    }

    public static function matches(User $user, SocialApprovalLink $link, SocialPost $post, string $decision, ?string $reason, string $token): bool
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

        return $claims === self::claims($user, $link, $post, $decision, $reason);
    }

    /**
     * @return array{u: int, l: int, p: string, d: string, r: string, c: string}
     */
    private static function claims(User $user, SocialApprovalLink $link, SocialPost $post, string $decision, ?string $reason): array
    {
        return [
            'u' => $user->id,
            'l' => $link->id,
            'p' => $post->ulid,
            'd' => $decision,
            'r' => hash('sha256', trim((string) $reason)),
            // What the approver saw: caption, media, slot.
            'c' => hash('sha256', (string) json_encode([
                $post->caption,
                $post->media,
                $post->scheduled_at?->toIso8601ZuluString(),
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
        return hash_hmac('sha256', 'approval-confirmation|'.$body, (string) config('app.key'));
    }
}
