<?php

namespace App\Support;

use App\Models\ClientLabel;
use App\Models\SocialPost;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The clients a post belongs to (v7): every client label on any of its
 * accounts. One query per request: prime() loads the labels of every
 * account in a list of posts, forPost() reads from that.
 */
final class ClientBadges
{
    /** @var array<int, array<int, array<string, mixed>>> account id => badges */
    private static array $byAccount = [];

    /** @param  Collection<int, SocialPost>|iterable<SocialPost>  $posts */
    public static function prime(iterable $posts): void
    {
        $ids = [];
        foreach ($posts as $post) {
            foreach ($post->targets as $t) {
                if (! array_key_exists($t->social_account_id, self::$byAccount)) {
                    $ids[] = $t->social_account_id;
                }
            }
        }
        self::load(array_values(array_unique($ids)));
    }

    /** @return array<int, array<string, mixed>> */
    public static function forPost(SocialPost $post): array
    {
        $ids = $post->targets->pluck('social_account_id')->unique()->values()->all();
        self::load(array_values(array_filter($ids, fn ($id) => ! array_key_exists($id, self::$byAccount))));

        $out = [];
        foreach ($ids as $id) {
            foreach (self::$byAccount[$id] ?? [] as $badge) {
                $out[$badge['id']] = $badge;
            }
        }
        uasort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return array_values($out);
    }

    /** @return array<int, array<string, mixed>> */
    public static function forAccount(int $accountId): array
    {
        self::load(array_key_exists($accountId, self::$byAccount) ? [] : [$accountId]);

        return self::$byAccount[$accountId] ?? [];
    }

    /** Tests and long-running workers start clean. */
    public static function flush(): void
    {
        self::$byAccount = [];
    }

    /** @param  array<int, int>  $accountIds */
    private static function load(array $accountIds): void
    {
        if ($accountIds === []) {
            return;
        }
        foreach ($accountIds as $id) {
            self::$byAccount[$id] = [];
        }
        $rows = DB::table('client_label_social_account')
            ->join('client_labels', 'client_labels.id', '=', 'client_label_social_account.client_label_id')
            ->whereIn('client_label_social_account.social_account_id', $accountIds)
            ->orderBy('client_labels.name')
            ->get(['client_label_social_account.social_account_id', 'client_labels.ulid', 'client_labels.name', 'client_labels.initials', 'client_labels.color']);
        foreach ($rows as $r) {
            self::$byAccount[$r->social_account_id][] = ['id' => $r->ulid, 'name' => $r->name, 'initials' => $r->initials, 'color' => $r->color];
        }
    }
}
