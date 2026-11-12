<?php

use App\Jobs\SendWeeklyDigests;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\User;
use App\Notifications\WeeklyDigest as WeeklyDigestMail;
use App\Support\WeeklyDigest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

/*
 * Item 9: the weekly digest — Monday 08:00 in the user's timezone, on the
 * scheduler (hourly). Last week's published count, waiting approvals,
 * failures with links. Honours weekly_digest; internal accounts skipped.
 * All with frozen time.
 */

beforeEach(function () {
    Notification::fake();
    config(['app.frontend_url' => 'https://app.creatif.test', 'services.posthog.internal_email_domains' => ['creatif.space']]);
});

/** Monday 16 Nov 2026, at this local time in this zone. */
function wdAt(string $time, string $tz): void
{
    test()->travelTo(CarbonImmutable::parse("2026-11-16 {$time}", $tz));
}

function wdUser(array $attrs = []): User
{
    return User::factory()->create(['timezone' => 'Indian/Maldives', ...$attrs]);
}

/** A post with one target in this state, its target last changed at this local time. */
function wdPost(User $user, string $status, string $targetStatus, string $when, array $extra = [], array $target = []): SocialPost
{
    $post = SocialPost::factory()->for($user)->create(['status' => $status, ...$extra]);
    $t = $post->targets()->create([
        'social_account_id' => SocialAccount::factory()->for($user)->create()->id,
        'status' => $targetStatus,
        ...$target,
    ]);
    $at = CarbonImmutable::parse($when, 'Indian/Maldives')->setTimezone(config('app.timezone'));
    $t->timestamps = false;
    $t->forceFill(['updated_at' => $at])->save();

    return $post;
}

describe('the digest', function () {
    it('counts last week (Monday to Monday, local): published, waiting now, failed with reason and link', function () {
        $user = wdUser();
        wdPost($user, 'published', 'published', '2026-11-10 12:00');
        wdPost($user, 'published', 'published', '2026-11-15 23:30');
        wdPost($user, 'published', 'published', '2026-11-08 23:59'); // the week before
        wdPost($user, 'published', 'published', '2026-11-16 00:30'); // this week
        $failed = wdPost($user, 'failed', 'failed', '2026-11-12 09:00', ['caption' => 'Winter set is here'], ['fail_code' => 'duplicate', 'fail_reason' => 'Instagram refused it as a duplicate.']);
        SocialPost::factory()->for($user)->create(['status' => 'scheduled', 'approval' => 'client']);
        SocialPost::factory()->for($user)->create(['status' => 'scheduled', 'approval' => 'awaiting']);
        SocialPost::factory()->for($user)->create(['status' => 'scheduled', 'approval' => 'approved']);
        wdPost(User::factory()->create(), 'published', 'published', '2026-11-10 12:00'); // someone else

        $d = WeeklyDigest::for($user, CarbonImmutable::parse('2026-11-16', 'Indian/Maldives'));

        expect($d['week'])->toBe(['from' => '2026-11-09', 'to' => '2026-11-15', 'timezone' => 'Indian/Maldives'])
            ->and($d['published'])->toBe(2)
            ->and($d['waiting'])->toBe(2)
            ->and($d['failed_total'])->toBe(1)
            ->and($d['failed'][0])->toBe([
                'post_id' => $failed->ulid,
                'caption' => 'Winter set is here',
                'reason' => 'Instagram refused it as a duplicate.',
                'url' => 'https://app.creatif.test/social?post='.$failed->ulid,
            ]);
    });

    it('lists at most 10 failures and counts the rest', function () {
        $user = wdUser();
        foreach (range(1, 12) as $i) {
            wdPost($user, 'failed', 'failed', '2026-11-11 10:00', target: ['fail_reason' => "Reason {$i}"]);
        }

        $d = WeeklyDigest::for($user, CarbonImmutable::parse('2026-11-16', 'Indian/Maldives'));
        expect($d['failed'])->toHaveCount(10)->and($d['failed_total'])->toBe(12);

        $mail = (new WeeklyDigestMail($d))->toMail($user);
        expect(implode("\n", $mail->introLines))->toContain('…and 2 more.')
            ->and($mail->subject)->toBe('Your week on creatif.space: 0 published, 12 failed');
    });
});

describe('SendWeeklyDigests', function () {
    it('sends at Monday 08:00 in the user’s timezone, once', function () {
        $user = wdUser();
        wdPost($user, 'published', 'published', '2026-11-12 10:00');

        wdAt('07:00', 'Indian/Maldives');
        (new SendWeeklyDigests)->handle();
        Notification::assertNothingSent();

        wdAt('08:00', 'Indian/Maldives');
        (new SendWeeklyDigests)->handle();
        Notification::assertSentToTimes($user, WeeklyDigestMail::class, 1);
        Notification::assertSentTo($user, WeeklyDigestMail::class, fn ($n) => $n->digest['published'] === 1 && $n->via($user) === ['mail']);

        // The next hourly run in the same hour, and the hours after: nothing more.
        wdAt('08:40', 'Indian/Maldives');
        (new SendWeeklyDigests)->handle();
        wdAt('09:00', 'Indian/Maldives');
        (new SendWeeklyDigests)->handle();
        Notification::assertSentToTimes($user, WeeklyDigestMail::class, 1);
        expect($user->fresh()->weekly_digest_sent_on)->toStartWith('2026-11-16');
    });

    it('goes by each user’s own zone', function () {
        $male = wdUser();
        $jakarta = wdUser(['timezone' => 'Asia/Jakarta']);
        $ny = wdUser(['timezone' => 'America/New_York']);
        foreach ([$male, $jakarta, $ny] as $u) {
            wdPost($u, 'published', 'published', '2026-11-12 10:00');
        }

        wdAt('08:00', 'Asia/Jakarta'); // 06:00 in Malé, Sunday evening in New York
        (new SendWeeklyDigests)->handle();
        Notification::assertSentTo($jakarta, WeeklyDigestMail::class);
        Notification::assertNotSentTo($male, WeeklyDigestMail::class);
        Notification::assertNotSentTo($ny, WeeklyDigestMail::class);

        wdAt('08:00', 'America/New_York');
        (new SendWeeklyDigests)->handle();
        Notification::assertSentTo($ny, WeeklyDigestMail::class);
    });

    it('falls back to the latest Content Plan’s zone, then the app’s', function () {
        $planned = wdUser(['timezone' => null]);
        SocialApprovalLink::query()->create(['user_id' => $planned->id, 'title' => 'Dec', 'client_name' => 'Andi', 'scope_timezone' => 'Europe/London']);
        $plain = wdUser(['timezone' => null]);

        expect(WeeklyDigest::timezone($planned))->toBe('Europe/London')
            ->and(WeeklyDigest::timezone($plain))->toBe(config('app.timezone'));
    });

    it('honours the weekly_digest setting', function () {
        $user = wdUser();
        wdPost($user, 'published', 'published', '2026-11-12 10:00');
        $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['weekly_digest' => false])->assertOk();

        wdAt('08:00', 'Indian/Maldives');
        (new SendWeeklyDigests)->handle();

        Notification::assertNotSentTo($user, WeeklyDigestMail::class);
    });

    it('skips internal accounts, staff, and accounts that are not active', function () {
        $internal = wdUser(['email' => 'team@creatif.space']);
        $staff = wdUser(['is_staff' => true]);
        $suspended = wdUser(['status' => 'suspended']);
        foreach ([$internal, $staff, $suspended] as $u) {
            wdPost($u, 'published', 'published', '2026-11-12 10:00');
        }

        wdAt('08:00', 'Indian/Maldives');
        (new SendWeeklyDigests)->handle();

        Notification::assertNothingSent();
    });

    it('sends nothing for a week with nothing in it', function () {
        $user = wdUser();

        wdAt('08:00', 'Indian/Maldives');
        (new SendWeeklyDigests)->handle();

        Notification::assertNothingSent();
        expect($user->fresh()->weekly_digest_sent_on)->toStartWith('2026-11-16');
    });

    it('sends again the next Monday', function () {
        $user = wdUser();
        wdPost($user, 'published', 'published', '2026-11-12 10:00');
        wdPost($user, 'published', 'published', '2026-11-18 10:00');

        wdAt('08:00', 'Indian/Maldives');
        (new SendWeeklyDigests)->handle();
        $this->travelTo(CarbonImmutable::parse('2026-11-23 08:00', 'Indian/Maldives'));
        (new SendWeeklyDigests)->handle();

        Notification::assertSentToTimes($user, WeeklyDigestMail::class, 2);
    });

    it('is scheduled hourly', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->description, SendWeeklyDigests::class));
        expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 * * * *');
    });
});
