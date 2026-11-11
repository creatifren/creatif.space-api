<?php

namespace Tests\Fixtures;

use App\Contracts\SocialProvider;
use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Models\FollowerSnapshot;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\Subscription;
use App\Models\User;
use App\Support\SocialProviders;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mockery;

/**
 * A Pro workspace ("Kopi Senja") reporting on October 2026, with September
 * to compare against. Each network sends its numbers the way it really does
 * — and leaves out what it really leaves out:
 *
 *   instagram  everything (reach, impressions, likes, comments, shares, saves)
 *   facebook   no saves
 *   tiktok     no reach, no saves; follower history starts 12 Oct
 *   x          likes / replies / quotes only (no reach, impressions, saves);
 *              followers never asked (RefreshFollowerCount::SKIPPED); nothing
 *              published in September (no comparison)
 *   linkedin   the feed call fails → every number "—"
 *
 * Every number is fixed, so the aggregation tests can assert exact sums.
 */
final class InsightFixture
{
    public const NOW = '2026-11-05 10:00:00';

    public const MONTH = '2026-10';

    /**
     * Raw feed items per platform: [day, hour, metrics, caption], newest
     * first is applied when served.
     *
     * @return array<string, array{oct: list<array<string, mixed>>, sep: list<array<string, mixed>>}>
     */
    public static function posts(): array
    {
        $ig = fn (int $reach, int $impr, int $likes, int $com, int $sh, int $sv) => [
            'reach' => $reach, 'impressions' => $impr, 'likes' => $likes, 'comments' => $com, 'shares' => $sh, 'saved' => $sv,
        ];
        $fb = fn (int $reach, int $impr, int $likes, int $com, int $sh) => [
            'reach' => $reach, 'impressions' => $impr, 'reactions_total' => $likes, 'comments' => $com, 'shares' => $sh,
        ];
        $tt = fn (int $views, int $likes, int $com, int $sh) => [
            'views' => $views, 'likes' => $likes, 'comments' => $com, 'shares' => $sh,
        ];
        $x = fn (int $likes, int $replies, int $quotes) => [
            'public_metrics' => ['like_count' => $likes, 'replies' => $replies, 'quotes' => $quotes],
        ];

        return [
            'instagram' => [
                'oct' => [
                    [2, 9, $ig(4200, 6100, 310, 24, 12, 40), 'Senja di kedai: kopi susu gula aren kami sekarang hadir dalam ukuran satu liter untuk dibawa pulang bersama teman.'],
                    [5, 18, $ig(3900, 5200, 280, 19, 8, 35), 'Behind the bar with Dimas — how we dial in the espresso every morning before the doors open.'],
                    [8, 12, $ig(5100, 7400, 402, 31, 22, 61), 'New single origin: Gayo Wine Process. Notes of jackfruit, cacao and a long sweet finish.'],
                    [11, 19, $ig(2600, 3900, 150, 9, 3, 12), 'Rainy day playlist is up. Come in, stay dry, order something warm.'],
                    [14, 10, $ig(6800, 9800, 640, 58, 41, 120), 'Giveaway! Tag two friends you would share a pitcher of es kopi with. Winners announced Friday.'],
                    [16, 17, $ig(3300, 4700, 210, 14, 6, 22), 'Weekend hours: we open at 7 on Saturday and Sunday from now on.'],
                    [19, 9, $ig(4100, 5600, 300, 20, 11, 30), 'Pastry drop: pandan croissant, only twelve a day.'],
                    [22, 20, $ig(3700, 5100, 260, 17, 9, 28), 'Latte art throwdown recap — thank you to everyone who came.'],
                    [24, 11, $ig(2900, 4100, 190, 12, 5, 18), 'Meet the roaster: a short visit to our partner farm in Aceh.'],
                    [27, 18, $ig(4600, 6300, 350, 26, 14, 44), 'Halloween menu preview: the Black Cat cold brew.'],
                    [29, 12, $ig(3100, 4400, 230, 15, 7, 25), 'Thank you for 13k followers. Free refill this week on every filter coffee.'],
                    [31, 19, $ig(5500, 7900, 470, 37, 19, 66), 'Black Cat cold brew is here until it runs out.'],
                ],
                'sep' => [
                    [3, 9, $ig(3600, 5000, 240, 16, 6, 20), 'September menu.'],
                    [7, 18, $ig(3100, 4300, 200, 12, 5, 18), 'Sunday slow bar.'],
                    [10, 12, $ig(4000, 5600, 290, 21, 9, 30), 'Single origin of the month.'],
                    [14, 19, $ig(2800, 3800, 170, 10, 4, 14), 'Open mic night.'],
                    [18, 10, $ig(3500, 4900, 230, 15, 6, 22), 'New cups.'],
                    [21, 17, $ig(3000, 4200, 190, 11, 5, 17), 'Team photo.'],
                    [24, 9, $ig(3800, 5300, 260, 18, 8, 26), 'Cold brew restock.'],
                    [27, 20, $ig(2700, 3700, 160, 9, 3, 13), 'Rainy season starts.'],
                    [30, 11, $ig(3300, 4600, 210, 13, 6, 19), 'See you in October.'],
                ],
            ],
            'facebook' => [
                'oct' => [
                    [3, 10, $fb(1800, 2600, 95, 12, 9), 'Kopi susu gula aren, now in one-litre bottles.'],
                    [7, 15, $fb(1500, 2100, 70, 8, 5), 'Our espresso routine, explained.'],
                    [12, 11, $fb(2200, 3100, 130, 17, 14), 'Gayo Wine Process has landed.'],
                    [15, 10, $fb(2900, 4000, 210, 30, 26), 'Giveaway: share a pitcher of es kopi.'],
                    [20, 16, $fb(1300, 1900, 60, 6, 3), 'Pandan croissant, twelve a day.'],
                    [23, 9, $fb(1600, 2300, 80, 9, 6), 'Latte art throwdown photos.'],
                    [28, 14, $fb(1700, 2500, 90, 10, 8), 'Halloween menu.'],
                    [31, 18, $fb(2100, 2900, 120, 14, 11), 'Black Cat cold brew is here.'],
                ],
                'sep' => [
                    [4, 10, $fb(1400, 2000, 70, 8, 5), 'September menu.'],
                    [9, 15, $fb(1200, 1700, 55, 6, 4), 'Sunday slow bar.'],
                    [13, 11, $fb(1700, 2400, 90, 11, 8), 'Single origin of the month.'],
                    [19, 10, $fb(1500, 2100, 75, 9, 6), 'New cups.'],
                    [25, 16, $fb(1300, 1800, 60, 7, 4), 'Cold brew restock.'],
                    [29, 9, $fb(1100, 1600, 50, 5, 3), 'See you in October.'],
                ],
            ],
            'tiktok' => [
                'oct' => [
                    [4, 19, $tt(18500, 1200, 80, 140), 'POV: your barista hears "less sugar" for the fifth time today'],
                    [9, 20, $tt(9200, 640, 35, 60), 'How we pour a tulip in 9 seconds'],
                    [14, 21, $tt(41000, 3900, 260, 520), 'Giveaway pitcher reveal'],
                    [18, 19, $tt(7600, 480, 22, 41), 'Pandan croissant ASMR'],
                    [25, 20, $tt(12300, 910, 51, 88), 'Farm trip, part one'],
                    [30, 21, $tt(15800, 1300, 70, 122), 'Black Cat cold brew, poured slowly'],
                ],
                'sep' => [
                    [6, 19, $tt(8800, 600, 30, 55), 'September menu, fast'],
                    [13, 20, $tt(11200, 850, 44, 70), 'Open mic highlights'],
                    [20, 21, $tt(6400, 410, 18, 30), 'New cups unboxing'],
                    [27, 19, $tt(9900, 720, 36, 61), 'Cold brew restock'],
                ],
            ],
            'x' => [
                'oct' => [
                    [1, 8, $x(42, 6, 3), 'October is here. So is the Gayo Wine Process.'],
                    [4, 12, $x(18, 2, 0), 'Open until 10 tonight.'],
                    [8, 9, $x(55, 9, 4), 'New single origin thread.'],
                    [10, 14, $x(12, 1, 0), 'Wifi password changed, ask at the bar.'],
                    [14, 10, $x(88, 21, 12), 'Giveaway rules, in one post.'],
                    [17, 8, $x(20, 3, 1), 'Weekend hours from now on: 7am.'],
                    [21, 15, $x(16, 2, 0), 'Pandan croissant sold out by 10.'],
                    [24, 11, $x(25, 4, 1), 'Farm trip notes.'],
                    [28, 9, $x(31, 5, 2), 'Halloween menu.'],
                    [31, 18, $x(47, 8, 5), 'Black Cat cold brew. That is it, that is the post.'],
                ],
                'sep' => [],
            ],
            'linkedin' => ['oct' => [], 'sep' => []],
        ];
    }

    /** @return list<string> */
    public static function platforms(): array
    {
        return ['instagram', 'facebook', 'tiktok', 'x', 'linkedin'];
    }

    /**
     * The workspace, its accounts, snapshots and workflow rows.
     *
     * @return array{owner: User, accounts: array<string, SocialAccount>}
     */
    public static function workspace(string $plan = 'pro', bool $withPosts = true): array
    {
        $owner = User::factory()->create(['name' => 'Kopi Senja', 'locale' => 'en', 'avatar_url' => 'https://img.example.test/logo.png']);

        if ($plan !== 'free') {
            Subscription::factory()->for($owner)->create([
                'plan_id' => Plan::query()->where('key', $plan)->value('id'),
            ]);
        }

        $accounts = [];
        foreach (self::platforms() as $platform) {
            $accounts[$platform] = SocialAccount::factory()->for($owner)->create([
                'platform' => $platform,
                'username' => 'kopisenja',
                'provider' => SocialAccount::currentProvider(),
                'provider_account_id' => 'acc_'.$platform,
            ]);
        }

        // Followers: Instagram and Facebook have history from August;
        // TikTok from 12 October; X is never asked; LinkedIn has none.
        $snap = fn (string $p, string $on, int $n) => FollowerSnapshot::query()->create([
            'social_account_id' => $accounts[$p]->id, 'on' => $on, 'followers' => $n,
        ]);
        $snap('instagram', '2026-08-31', 11800);
        $snap('instagram', '2026-09-30', 12400);
        $snap('instagram', '2026-10-15', 12800);
        $snap('instagram', '2026-10-31', 13150);
        $snap('facebook', '2026-08-31', 5020);
        $snap('facebook', '2026-09-30', 5100);
        $snap('facebook', '2026-10-31', 5185);
        $snap('tiktok', '2026-10-12', 2300);
        $snap('tiktok', '2026-10-31', 2950);

        if ($withPosts) {
            self::workflow($owner, $accounts);
        }

        return ['owner' => $owner, 'accounts' => $accounts];
    }

    /**
     * Our own rows: approvals (two through a client link) and scheduled
     * posts whose network publish time is known through the feed.
     *
     * @param  array<string, SocialAccount>  $accounts
     */
    private static function workflow(User $owner, array $accounts): void
    {
        $tz = config('app.timezone');

        // Approved in October: requested via a link 3 h and 21 h before, one
        // approved in the editor 2 h after it was written.
        $link = SocialApprovalLink::query()->create(['user_id' => $owner->id, 'token' => SocialApprovalLink::hashToken('t1'), 'client_name' => 'Kopi Senja']);
        $link->forceFill(['created_at' => CarbonImmutable::parse('2026-10-06 09:00', $tz)])->save();

        $make = function (array $attrs, ?string $createdAt = null) use ($owner) {
            $post = SocialPost::factory()->for($owner)->create($attrs);
            if ($createdAt !== null) {
                $post->forceFill(['created_at' => CarbonImmutable::parse($createdAt, config('app.timezone'))])->save();
            }

            return $post;
        };

        $a = $make(['approval' => SocialPostApproval::Approved, 'approval_at' => CarbonImmutable::parse('2026-10-06 12:00', $tz)], '2026-10-05 08:00');
        $b = $make(['approval' => SocialPostApproval::Approved, 'approval_at' => CarbonImmutable::parse('2026-10-07 06:00', $tz)], '2026-10-05 09:00');
        $c = $make(['approval' => SocialPostApproval::Approved, 'approval_at' => CarbonImmutable::parse('2026-10-20 12:00', $tz)], '2026-10-20 10:00');
        $link->posts()->attach([$a->id, $b->id]);

        // September: one approval, 10 h.
        $d = $make(['approval' => SocialPostApproval::Approved, 'approval_at' => CarbonImmutable::parse('2026-09-10 20:00', $tz)], '2026-09-10 10:00');

        foreach ([$a, $b, $c, $d] as $post) {
            SocialPostTarget::query()->create(['social_post_id' => $post->id, 'social_account_id' => $accounts['instagram']->id, 'status' => SocialPostTargetStatus::Published]);
        }

        // Scheduled in October, matched to Instagram feed items by the
        // relay's post id: on time (+2 min), on time (-5 min), late (+25 min).
        foreach ([[8, 12, 2, 2], [14, 10, -5, 4], [27, 18, 25, 9]] as [$day, $hour, $offset, $index]) {
            $published = CarbonImmutable::parse(sprintf('2026-10-%02d %02d:00', $day, $hour), $tz);
            $post = $make([
                'status' => SocialPostStatus::Published,
                'provider' => SocialAccount::currentProvider(),
                'provider_post_id' => 'op_instagram_oct_'.$index,
                'scheduled_at' => $published->subMinutes($offset),
            ]);
            SocialPostTarget::query()->create(['social_post_id' => $post->id, 'social_account_id' => $accounts['instagram']->id, 'status' => SocialPostTargetStatus::Published]);
        }
    }

    /**
     * Swap the relay for one that serves the fixture feeds (LinkedIn fails),
     * and answer thumbnail fetches with real small JPEGs.
     *
     * @param  array<string, array{oct: list<array<string, mixed>>, sep: list<array<string, mixed>>}>|null  $posts
     */
    public static function fakeRelay(?array $posts = null): void
    {
        $posts ??= self::posts();
        $tz = config('app.timezone');
        $feeds = [];

        foreach ($posts as $platform => $months) {
            $items = [];
            foreach (['oct' => '2026-10', 'sep' => '2026-09'] as $key => $month) {
                foreach ($months[$key] as $i => [$day, $hour, $metrics, $caption]) {
                    $items[] = [
                        'platform_post_id' => $platform.'_'.$key.'_'.$i,
                        'caption' => $caption,
                        'platform_url' => 'https://'.$platform.'.example.test/p/'.$key.$i,
                        'posted_at' => CarbonImmutable::parse(sprintf('%s-%02d %02d:00', $month, $day, $hour), $tz)->utc()->toIso8601String(),
                        'media' => ['https://cdn.example.test/'.$platform.'/'.$key.'-'.$i.'.jpg'],
                        'provider_post_id' => 'op_'.$platform.'_'.$key.'_'.$i,
                        'metrics' => $metrics,
                    ];
                }
            }
            usort($items, fn ($a, $b) => strcmp($b['posted_at'], $a['posted_at']));
            $feeds['acc_'.$platform] = $items;
        }

        $provider = Mockery::mock(SocialProvider::class);
        $provider->shouldReceive('feed')->andReturnUsing(function (string $accountId, ?string $cursor = null, int $limit = 25) use ($feeds) {
            if ($accountId === 'acc_linkedin') {
                throw new RequestException(new Response(new \GuzzleHttp\Psr7\Response(403, [], '{"message":"no"}')));
            }
            $offset = (int) $cursor;
            $all = $feeds[$accountId] ?? [];

            return [
                'data' => array_slice($all, $offset, $limit),
                'cursor' => $offset + $limit < count($all) ? (string) ($offset + $limit) : null,
            ];
        });

        $providers = Mockery::mock(SocialProviders::class);
        $providers->shouldReceive('for')->andReturn($provider);
        app()->instance(SocialProviders::class, $providers);

        Http::fake([
            'cdn.example.test/*' => fn ($request) => Http::response(self::jpeg(crc32($request->url())), 200, ['Content-Type' => 'image/jpeg']),
            'img.example.test/*' => Http::response(self::jpeg(7, 0x0F172A), 200, ['Content-Type' => 'image/png']),
        ]);
    }

    /** A small solid-colour JPEG with a lighter band, so thumbnails read as pictures. */
    public static function jpeg(int $seed, ?int $rgb = null): string
    {
        $palette = [0xFF9F43, 0xC4A8FF, 0xF7A8D0, 0x1F3D34, 0x5A6478, 0xA34A3C];
        $rgb ??= $palette[$seed % count($palette)];
        $im = imagecreatetruecolor(480, 480);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, ($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255));
        imagefilledrectangle($im, 0, 300, 480, 380, (int) imagecolorallocate($im, 0xFA, 0xFA, 0xF8));
        ob_start();
        imagejpeg($im, null, 80);

        return (string) ob_get_clean();
    }
}
