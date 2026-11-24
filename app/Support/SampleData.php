<?php

namespace App\Support;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Mcp\TokenContext;
use App\Models\Activity;
use App\Models\BlogPost;
use App\Models\ClientLabel;
use App\Models\ClientReport;
use App\Models\File;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\SocialPost;
use App\Models\SocialPostComment;
use App\Models\Space;
use App\Models\User;
use App\Services\DemoSocialProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sample data for every new account (prototype v10, decisions.md, Rizal yes
 * 20 Nov 2026): a new account opens with one client "Morning Coffee" (approver
 * Alex; renamed from Kopi Senja / Dewi 24 Nov), two fake accounts ("Demo · Instagram", "Demo · TikTok"), 8 posts
 * in every state, an approval link (Week 48), a report (this month), a
 * Gallery and a Blog post waiting for approval, two DMs and two comments
 * (on the sample accounts, so they go with them), and a picture on every
 * post but the draft (public/sample/*.jpg). Every row carries
 * is_demo = true; the accounts are the demo relay's (nothing is ever sent)
 * with provider_account_id "sample_…".
 *
 * It stays until the owner removes it (one call: remove()). It never
 * publishes, spends Credits, sends email or push, and is left out of
 * quotas, Insight, report emails, MCP / API keys and public pages.
 */
final class SampleData
{
    public const CLIENT = 'Morning Coffee';

    public const APPROVER = 'Alex (owner)';

    /** .example never delivers: an address that cannot be mailed even by mistake. */
    public const APPROVER_EMAIL = 'alex@morningcoffee.example';

    /** Sample rows are hidden while a bearer token (MCP, API key) is being served. */
    public static function hiddenForThisRequest(): bool
    {
        if (! app()->bound('request')) {
            return false;
        }

        return request()->attributes->get('mcp_token') instanceof TokenContext;
    }

    /** Seed for a new account; never lets a sign-up fail. */
    public static function seedQuietly(User $user): void
    {
        try {
            self::seed($user);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function seed(User $user): void
    {
        if (self::has($user) || $user->socialPosts()->exists()) {
            return;
        }

        DB::transaction(function () use ($user) {
            $now = CarbonImmutable::now();
            $ig = self::account($user, SocialPlatform::Instagram, 'Demo · Instagram');
            $tt = self::account($user, SocialPlatform::TikTok, 'Demo · TikTok');

            $client = new ClientLabel;
            $client->forceFill([
                'user_id' => $user->id,
                'name' => self::CLIENT,
                'initials' => 'MC',
                'color' => ClientLabel::COLORS[0],
                'approver_name' => self::APPROVER,
                'approver_email' => self::APPROVER_EMAIL,
                'report_email' => false,
                'is_demo' => true,
            ])->save();
            $client->accounts()->sync([$ig->id, $tt->id]);

            $at = fn (int $days, int $hour) => $now->addDays($days)->setTime($hour, 0);
            self::post($user, [$ig], 'Draft: weekend brunch menu. Swap the photo before sending.', SocialPostStatus::Draft, null, picture: 'brunch');
            $w1 = self::post($user, [$ig], 'Something new is coming to the bar on Friday. Save the date.', SocialPostStatus::Draft, $at(3, 10), SocialPostApproval::Client, picture: 'bar');
            $w2 = self::post($user, [$ig, $tt], 'Slow mornings, good coffee. Our new single origin is here.', SocialPostStatus::Draft, $at(4, 8), SocialPostApproval::Client, picture: 'single-origin');
            $changes = self::post($user, [$ig], 'Fresh croissants from our kitchen. Perfect with a flat white.', SocialPostStatus::Draft, $at(5, 8), SocialPostApproval::Revision, picture: 'croissant');
            self::post($user, [$tt], 'Latte art in 30 seconds. Watch till the end.', SocialPostStatus::Draft, $at(6, 18), SocialPostApproval::Approved, picture: 'latte-art');
            self::post($user, [$ig], 'Open late this Saturday. See you after 9.', SocialPostStatus::Scheduled, $at(2, 19), picture: 'open-late');
            $published = self::post($user, [$ig, $tt], 'Thank you for 1,000 followers. Coffee is on us this Friday.', SocialPostStatus::Published, $at(-3, 9), picture: 'thank-you');
            self::post($user, [$ig], '3 tips for better coffee at home. Save this for later.', SocialPostStatus::Failed, $at(-1, 19), fail: 'Instagram refused the image: it is wider than 1.91:1.', picture: 'coffee-tips');

            self::inbox($user, $ig, $published, $now);

            SocialPostComment::query()->create([
                'social_post_id' => $changes->id,
                'author_name' => self::APPROVER,
                'role' => 'APPROVER',
                'body' => 'Pastry one needs the new logo.',
            ]);

            $link = new SocialApprovalLink;
            $link->forceFill([
                'user_id' => $user->id,
                'title' => 'Week '.$now->addWeek()->isoWeek(),
                'client_name' => self::CLIENT,
                'client_label_id' => $client->id,
                'approver_email' => self::APPROVER_EMAIL,
                'reminders_enabled' => false,
                'expires_at' => $now->addDays(30),
                'is_demo' => true,
            ])->save();
            $link->posts()->sync([$w1->id, $w2->id]);

            $report = new ClientReport;
            $report->forceFill([
                'user_id' => $user->id,
                'client_label_id' => $client->id,
                'month' => $now->format('Y-m'),
                'visibility' => 'link',
                'summary' => 'A sample report: reach grew week on week, and the latte art video did best.',
                'is_demo' => true,
            ])->save();

            self::space($user, 'gallery', 'Morning Coffee · Menu photos');
            $blog = self::space($user, 'blog', 'Morning Coffee · Journal');
            $post = new BlogPost;
            $post->forceFill([
                'space_id' => $blog->id,
                'user_id' => $user->id,
                'title' => 'How we pick our beans',
                'slug' => 'how-we-pick-our-beans',
                'blocks' => [['type' => 'paragraph', 'text' => 'A sample blog post: every month we cup six coffees and keep the one our regulars ask for again.']],
                'status' => 'waiting',
                'needs_approval' => true,
                'social_approval_link_id' => $link->id,
                'is_demo' => true,
            ])->save();
        });
    }

    /** Is there any sample data left on this account? */
    public static function has(User $user): bool
    {
        return ClientLabel::query()->where('user_id', $user->id)->where('is_demo', true)->exists()
            || SocialPost::query()->where('user_id', $user->id)->where('is_demo', true)->exists()
            || SocialAccount::query()->where('user_id', $user->id)->where('is_demo', true)->where('provider_account_id', 'like', SocialAccount::SAMPLE_PREFIX.'%')->exists();
    }

    /**
     * "Remove sample data": every is_demo row of the account, and the sample
     * accounts (targets, labels on them and follower history go with them by
     * cascade). Returns what went, for the confirm toast.
     *
     * @return array<string, int>
     */
    public static function remove(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $posts = SocialPost::query()->where('user_id', $user->id)->where('is_demo', true)->pluck('id');
            DB::table('social_approval_link_post')->whereIn('social_post_id', $posts)->delete();
            $links = SocialApprovalLink::query()->where('user_id', $user->id)->where('is_demo', true)->pluck('id');
            DB::table('social_approval_decisions')->whereIn('social_approval_link_id', $links)->delete();

            $gone = [
                'blog_posts' => BlogPost::query()->where('user_id', $user->id)->where('is_demo', true)->delete(),
                'posts' => SocialPost::query()->whereKey($posts)->delete(),
                'approval_links' => SocialApprovalLink::query()->whereKey($links)->delete(),
                'reports' => ClientReport::query()->where('user_id', $user->id)->where('is_demo', true)->delete(),
                'clients' => ClientLabel::query()->where('user_id', $user->id)->where('is_demo', true)->delete(),
                'spaces' => Space::withTrashed()->where('user_id', $user->id)->where('is_demo', true)->forceDelete(),
                'files' => File::query()->where('user_id', $user->id)->where('is_demo', true)->delete(),
                'activity' => Activity::query()->where('user_id', $user->id)->where('is_demo', true)->delete(),
                'accounts' => SocialAccount::query()->where('user_id', $user->id)->where('is_demo', true)
                    ->where('provider_account_id', 'like', SocialAccount::SAMPLE_PREFIX.'%')->delete(),
            ];
            ClientBadges::flush();

            return $gone;
        });
    }

    private static function account(User $user, SocialPlatform $platform, string $name): SocialAccount
    {
        $account = new SocialAccount;
        $account->forceFill([
            'user_id' => $user->id,
            'provider' => DemoSocialProvider::NAME,
            'is_demo' => true,
            'platform' => $platform,
            'provider_account_id' => SocialAccount::SAMPLE_PREFIX.Str::lower((string) Str::ulid()),
            'username' => $name,
            'status' => SocialAccountStatus::Connected,
            'connected_at' => now(),
            'followers_count' => 1200,
            'followers_synced_at' => now(),
        ])->save();

        return $account;
    }

    /** A sample picture's address (public/sample/{name}.jpg on the API). */
    public static function picture(string $name): string
    {
        return rtrim((string) config('app.url'), '/').'/sample/'.$name.'.jpg';
    }

    /**
     * Two DMs and two comments on the sample Instagram account. They live on
     * the sample account (no is_demo of their own): removing it removes them
     * by cascade. Already "synced", so nothing ever asks the demo relay.
     */
    private static function inbox(User $user, SocialAccount $ig, SocialPost $published, CarbonImmutable $now): void
    {
        $dms = [
            ['sarah.eats', 'Sarah', 'Hi! Do you take bookings for 8 people this Saturday?', 40, 1],
            ['mike_wfh', 'Mike', 'Is the wifi good for working? Thinking of coming tomorrow.', 300, 0],
        ];
        foreach ($dms as [$username, $name, $text, $minutes, $unread]) {
            $conversation = SocialConversation::query()->create([
                'user_id' => $user->id,
                'social_account_id' => $ig->id,
                'provider' => DemoSocialProvider::NAME,
                'provider_conversation_id' => SocialAccount::SAMPLE_PREFIX.Str::lower((string) Str::ulid()),
                'network' => 'instagram',
                'contact_id' => SocialAccount::SAMPLE_PREFIX.$username,
                'contact_username' => $username,
                'contact_name' => $name,
                'last_message_preview' => $text,
                'last_message_at' => $now->subMinutes($minutes),
                'last_inbound_at' => $now->subMinutes($minutes),
                'unread_count' => $unread,
                'status' => SocialConversation::OPEN,
                'messages_synced_at' => $now,
            ]);
            $conversation->messages()->create([
                'direction' => 'in',
                'content' => $text,
                'status' => 'sent',
                'source' => 'contact',
                'sent_at' => $now->subMinutes($minutes),
            ]);
        }

        $comments = [
            ['alex.mc', 'Congrats! See you Friday.', 90],
            ['coffee.lover', 'Is the free coffee for any drink?', 30],
        ];
        foreach ($comments as [$username, $text, $minutes]) {
            SocialComment::query()->create([
                'user_id' => $user->id,
                'social_account_id' => $ig->id,
                'social_post_id' => $published->id,
                'provider' => DemoSocialProvider::NAME,
                'comment_id' => SocialAccount::SAMPLE_PREFIX.Str::lower((string) Str::ulid()),
                'author_id' => SocialAccount::SAMPLE_PREFIX.$username,
                'author_username' => $username,
                'text' => $text,
                'network' => 'instagram',
                'commented_at' => $now->subMinutes($minutes),
            ]);
        }
    }

    /** @param  list<SocialAccount>  $accounts */
    private static function post(User $user, array $accounts, string $caption, SocialPostStatus $status, ?CarbonImmutable $at, ?SocialPostApproval $approval = null, ?string $fail = null, ?string $picture = null): SocialPost
    {
        $post = new SocialPost;
        $post->forceFill([
            'user_id' => $user->id,
            'provider' => DemoSocialProvider::NAME,
            'caption' => $caption,
            'media' => $picture === null ? null : [['url' => self::picture($picture)]],
            'scheduled_at' => $at,
            'status' => $status,
            'fail_reason' => $fail,
            'approval' => $approval,
            'approval_by' => $approval === SocialPostApproval::Approved ? self::CLIENT : null,
            'approval_at' => $approval === null || $approval === SocialPostApproval::Client ? null : now(),
            'is_demo' => true,
        ])->save();

        foreach ($accounts as $account) {
            $post->targets()->create([
                'social_account_id' => $account->id,
                'status' => match ($status) {
                    SocialPostStatus::Published => SocialPostTargetStatus::Published,
                    SocialPostStatus::Failed => SocialPostTargetStatus::Failed,
                    default => SocialPostTargetStatus::Pending,
                },
                'fail_reason' => $fail,
            ]);
        }

        return $post;
    }

    private static function space(User $user, string $type, string $title): Space
    {
        $space = new Space;
        $space->forceFill([
            'user_id' => $user->id,
            'type' => $type,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'description' => 'Sample '.$type.'. Private: only you see it.',
            'status' => 'draft',
            'visibility' => 'private',
            'view_mode' => 'grid',
            'design' => Space::emptyDesign(),
            'settings' => Space::defaultSettings(),
            'is_demo' => true,
        ])->save();

        return $space;
    }
}
