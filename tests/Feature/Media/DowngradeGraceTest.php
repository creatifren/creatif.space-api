<?php

use App\Enums\SocialPostStatus;
use App\Jobs\ApplyMediaRetention;
use App\Jobs\NotifyKeptFilesDowngrade;
use App\Models\File;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\KeptFilesGoingSoon;
use App\Support\Media\MediaLimits;
use App\Support\Media\MediaRetention;
use App\Support\Media\RetentionVerdict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * Media retention downgrade policy (/terms): when a paid account drops to
 * Free, its kept files are not deleted for 30 days; 7 days and 1 day before
 * the first deletion an email says how many files / how much will go and
 * how to keep them. Retention stays in report mode.
 */

beforeEach(function () {
    Storage::fake('s3');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-12-01 03:00:00', 'UTC'));
});

/** A Free account that was paid until $endedAt (UTC), with kept files. */
function dgUser(string $endedAt = '2026-12-01 00:00:00'): User
{
    $user = User::factory()->create();
    // datetime columns hold app-timezone wall time, as the app writes them.
    $end = CarbonImmutable::parse($endedAt, 'UTC')->setTimezone((string) config('app.timezone'));
    Subscription::factory()->for($user)->create([
        'status' => 'expired',
        'current_period_end' => $end->subDays(7),
        'grace_ends_at' => $end,
    ]);
    MediaRetention::flush();

    return $user;
}

function dgVerdict(File $file): RetentionVerdict
{
    MediaRetention::flush();

    return MediaRetention::for($file->fresh());
}

it('dates the downgrade from the last subscription\'s end of entitlement', function () {
    $user = dgUser('2026-11-28 12:00:00');
    expect(MediaLimits::downgradedAt($user)?->utc()->toIso8601ZuluString())->toBe('2026-11-28T12:00:00Z');

    // Paid now: no downgrade.
    $paid = mediaUser('pro');
    expect(MediaLimits::downgradedAt($paid))->toBeNull();

    // Never paid: none either.
    expect(MediaLimits::downgradedAt(mediaUser('free')))->toBeNull();

    // An early expiry (period end still ahead) dates from when it changed.
    $early = User::factory()->create();
    $sub = Subscription::factory()->for($early)->create(['status' => 'expired', 'current_period_end' => now()->addDays(20), 'grace_ends_at' => null]);
    expect(MediaLimits::downgradedAt($early)?->timestamp)->toBe(CarbonImmutable::instance($sub->fresh()->updated_at)->timestamp);
});

it('keeps kept files 30 days after the downgrade, and auto_delete_at says when', function () {
    $user = dgUser('2026-12-01 00:00:00');
    $kept = mediaFile($user, ['kept_at' => now()->subMonth(), 'created_at' => now()->subMonths(2)]);

    $verdict = dgVerdict($kept);
    expect($verdict->rule)->toBe(RetentionVerdict::FREE_KEPT_GRACE)
        ->and($verdict->kept)->toBeTrue()
        ->and($verdict->dueAt?->utc()->toIso8601ZuluString())->toBe('2026-12-31T00:00:00Z');

    $this->actingAs($user)->getJson("/api/v1/files/{$kept->ulid}")
        ->assertOk()
        ->assertJsonPath('data.is_kept', true)
        ->assertJsonPath('data.auto_delete_at', '2026-12-31T00:00:00Z');
});

it('leaves kept files alone while paid, on a queued post, or when not post media', function () {
    $paid = mediaUser('pro');
    expect(dgVerdict(mediaFile($paid, ['kept_at' => now()]))->rule)->toBe(RetentionVerdict::KEPT);

    $user = dgUser();
    $onPost = mediaFile($user, ['kept_at' => now()]);
    postWith($user, [$onPost], SocialPostStatus::Scheduled);
    $pdf = mediaFile($user, ['kept_at' => now(), 'mime_type' => 'application/pdf']);

    expect(dgVerdict($onPost)->rule)->toBe(RetentionVerdict::QUEUED)
        ->and(dgVerdict($onPost)->dueAt)->toBeNull()
        ->and(dgVerdict($pdf)->rule)->toBe(RetentionVerdict::NOT_POST_MEDIA)
        ->and(dgVerdict($pdf)->dueAt)->toBeNull();
});

it('reports kept files after the 30 days — and in report mode deletes nothing', function () {
    $user = dgUser('2026-12-01 00:00:00');
    $kept = mediaFile($user, ['kept_at' => now()->subMonth(), 'size_bytes' => 5 * 1024 * 1024]);
    Storage::disk('s3')->put($kept->path, 'bytes');

    expect((new ApplyMediaRetention)->handle()['files'])->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-12-31 00:00:01', 'UTC'));
    $summary = (new ApplyMediaRetention)->handle();
    expect($summary['mode'])->toBe('report')
        ->and($summary['files'])->toBe(1)
        ->and($summary['users'][$user->ulid]['rules'])->toBe([RetentionVerdict::FREE_KEPT_GRACE => 1])
        ->and(File::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(Storage::disk('s3')->exists($kept->path))->toBeTrue();
});

describe('downgrade notices', function () {
    beforeEach(function () {
        config(['media.retention.downgrade_notices' => 'send']);
    });

    it('emails 7 days and 1 day before the first deletion — once each', function () {
        $user = dgUser('2026-12-01 00:00:00'); // first deletion 31 Dec 00:00 UTC
        mediaFile($user, ['kept_at' => now(), 'size_bytes' => 1024 ** 3]);
        mediaFile($user, ['kept_at' => now(), 'size_bytes' => 512 * 1024 ** 2]);

        // 8 days before: nothing yet.
        $this->travelTo(CarbonImmutable::parse('2026-12-23 00:00:00', 'UTC'));
        expect((new NotifyKeptFilesDowngrade)->handle()['notices'])->toBe([]);
        Notification::assertNothingSent();

        // 7 days before.
        $this->travelTo(CarbonImmutable::parse('2026-12-24 01:00:00', 'UTC'));
        $run = (new NotifyKeptFilesDowngrade)->handle();
        expect($run['notices'])->toBe([['user' => $user->ulid, 'days_before' => 7, 'files' => 2, 'bytes' => 1536 * 1024 ** 2, 'due_on' => '2026-12-31']]);
        Notification::assertSentTo($user, KeptFilesGoingSoon::class, fn (KeptFilesGoingSoon $n) => $n->daysBefore === 7 && $n->files === 2);

        // Same window, next day: not again.
        $this->travelTo(CarbonImmutable::parse('2026-12-25 01:00:00', 'UTC'));
        (new NotifyKeptFilesDowngrade)->handle();
        Notification::assertSentToTimes($user, KeptFilesGoingSoon::class, 1);

        // 1 day before.
        $this->travelTo(CarbonImmutable::parse('2026-12-30 01:00:00', 'UTC'));
        (new NotifyKeptFilesDowngrade)->handle();
        (new NotifyKeptFilesDowngrade)->handle();
        Notification::assertSentToTimes($user, KeptFilesGoingSoon::class, 2);
        Notification::assertSentTo($user, KeptFilesGoingSoon::class, fn (KeptFilesGoingSoon $n) => $n->daysBefore === 1);

        expect(DB::table('media_retention_notices')->where('user_id', $user->id)->orderBy('days_before')->pluck('days_before')->all())->toBe([1, 7]);
    });

    it('says how many files, how much, when, and how to keep them', function () {
        $user = dgUser('2026-12-01 00:00:00');
        mediaFile($user, ['kept_at' => now(), 'size_bytes' => 3 * 1024 ** 3]);
        $this->travelTo(CarbonImmutable::parse('2026-12-24 01:00:00', 'UTC'));
        (new NotifyKeptFilesDowngrade)->handle();

        Notification::assertSentTo($user, KeptFilesGoingSoon::class, function (KeptFilesGoingSoon $n) use ($user) {
            $mail = $n->toMail($user);
            $text = implode("\n", array_merge($mail->introLines, $mail->outroLines));

            return $mail->subject === '1 kept file will be deleted in 7 days'
                && str_contains($text, '3 GB')
                && str_contains($text, '31 Dec 2026')
                && str_contains($text, 'upgrade to Pro, or download them')
                && str_contains((string) $mail->actionUrl, 'section=subscription')
                && str_contains($text, '/files');
        });
    });

    it('does not email paid accounts or accounts with nothing kept', function () {
        mediaFile(mediaUser('pro'), ['kept_at' => now()]);
        mediaFile(dgUser(), []); // downgraded, nothing kept
        $this->travelTo(CarbonImmutable::parse('2026-12-24 01:00:00', 'UTC'));

        expect((new NotifyKeptFilesDowngrade)->handle()['notices'])->toBe([]);
        Notification::assertNothingSent();
    });

    it('only reports (no email) in the default report mode', function () {
        config(['media.retention.downgrade_notices' => 'report']);
        $user = dgUser('2026-12-01 00:00:00');
        mediaFile($user, ['kept_at' => now()]);
        $this->travelTo(CarbonImmutable::parse('2026-12-24 01:00:00', 'UTC'));

        $run = (new NotifyKeptFilesDowngrade)->handle();
        expect($run['mode'])->toBe('report')->and($run['notices'])->toHaveCount(1);
        Notification::assertNothingSent();
        expect(DB::table('media_retention_notices')->count())->toBe(0);
    });
});

it('defaults to report mode for both deletes and notices', function () {
    expect(config('media.retention_mode'))->toBe('report')
        ->and(config('media.retention.downgrade_notices'))->toBe('report');
});
