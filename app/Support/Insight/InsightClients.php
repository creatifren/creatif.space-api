<?php

namespace App\Support\Insight;

use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Workspace;
use Illuminate\Support\Collection;

/**
 * Who a report is for. There is no Clients entity in the API yet (the old
 * `clients` table went with Space approval), so today a workspace is one
 * client: id = the workspace owner's ULID, channels = the workspace's
 * usable social accounts. GET /insight/clients is the list the export sheet
 * picks from, so when real clients ship only this class changes — the
 * report endpoints already take a client_id.
 */
final class InsightClients
{
    /**
     * @return list<array{id: string, name: string, slug: string, channels: list<array{id: string, platform: string, network: string, username: string|null}>}>
     */
    public static function for(User $user): array
    {
        $owner = Workspace::owner($user);

        return [[
            'id' => $owner->ulid,
            'name' => self::name($owner),
            'slug' => self::slug($owner),
            'channels' => self::accounts($owner)->map(fn (SocialAccount $a) => [
                'id' => $a->ulid,
                'platform' => $a->platform->value,
                'network' => InsightStrings::network($a->platform->value),
                'username' => $a->username,
            ])->values()->all(),
        ]];
    }

    /** The client's owner when $clientId is one $user may report on. */
    public static function resolve(User $user, string $clientId): ?User
    {
        $owner = Workspace::owner($user);

        return $owner->ulid === $clientId ? $owner : null;
    }

    /**
     * The client's channels: usable accounts of anyone in the workspace.
     *
     * @return Collection<int, SocialAccount>
     */
    public static function accounts(User $owner): Collection
    {
        return SocialAccount::query()
            ->whereIn('user_id', Workspace::memberIds($owner))
            ->usable()
            ->orderBy('id')
            ->get();
    }

    public static function name(User $owner): string
    {
        return trim((string) $owner->name) !== '' ? (string) $owner->name : 'Creatif Space';
    }

    public static function slug(User $owner): string
    {
        $slug = str(self::name($owner))->slug()->limit(60, '')->trim('-')->value();

        return $slug !== '' ? $slug : 'client';
    }

    /** {client-slug}-{yyyy-mm}-{format}.pdf */
    public static function fileName(string $slug, InsightPeriod $period, string $format): string
    {
        return $slug.'-'.$period->fileMonth().'-'.$format.'.pdf';
    }
}
