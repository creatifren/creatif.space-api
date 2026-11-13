<?php

use App\Jobs\FailUnconfirmedPublishes;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SocialPostFailed;
use App\Services\Analytics;
use App\Support\Credits;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * Live, 13 Nov: a Bluesky "publish now" post the relay took (VCioF) sat
 * pending with no error and no platform id; our post read "scheduled" with
 * no time, for ever, and nobody was told. A sent post still pending 5
 * minutes after it was due is now settled: a late result first, else
 * cancelled at the relay and failed with a reason (not_confirmed), refunded,
 * owner told once.
 */

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.social.provider' => 'outstand',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
        'services.outstand.webhook_secret' => 'whsec_out',
    ]);
    Cache::flush();
    Notification::fake();
    Analytics::fake();
});

function ucUser(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, 30_000, 'Test');

    return $user;
}

function ucAccount(User $user, string $platform = 'bluesky', string $providerId = 'IcKjX'): SocialAccount
{
    return SocialAccount::factory()->for($user)->create(['platform' => $platform, 'provider' => 'outstand', 'provider_account_id' => $providerId]);
}

/** A "now" post Outstand took $minutesAgo, one pending target per account, paid for. */
function ucSentPost(User $user, array $accounts, int $minutesAgo): SocialPost
{
    $post = SocialPost::factory()->for($user)->create([
        'provider' => 'outstand', 'provider_post_id' => 'VCioF', 'status' => 'scheduled', 'scheduled_at' => null,
        'caption' => 'Creatif Space connection test', 'media' => null,
    ]);
    foreach ($accounts as $a) {
        $post->targets()->create(['social_account_id' => $a->id]);
    }
    Credits::chargeSend($post);
    SocialPost::query()->whereKey($post->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);

    return $post->fresh();
}

/** Outstand's word per account id, and whether the cancel works. */
function ucFakeOutstand(array $statuses, int $deleteStatus = 200): void
{
    Http::fake(function (HttpRequest $r) use ($statuses, $deleteStatus) {
        if ($r->method() === 'DELETE') {
            return Http::response(['success' => $deleteStatus < 300], $deleteStatus);
        }

        return Http::response(['success' => true, 'post' => ['id' => 'VCioF', 'socialAccounts' => array_map(
            fn (string $id, string $status) => ['id' => $id, 'status' => $status, 'error' => null,
                'platformPostId' => $status === 'published' ? 'at://x' : null,
                'platformPostUrl' => $status === 'published' ? 'https://bsky.app/x' : null,
                'publishedAt' => $status === 'published' ? now()->toIso8601String() : null],
            array_keys($statuses), $statuses,
        )]]);
    });
}

function ucRun(): void
{
    app()->call([new FailUnconfirmedPublishes, 'handle']);
}

it('fails a "now" post the relay never confirmed after 5 minutes: cancelled at the relay, refunded, a reason, owner told once', function () {
    $user = ucUser();
    $before = Credits::total($user);
    $post = ucSentPost($user, [ucAccount($user)], 6);
    expect(Credits::total($user))->toBeLessThan($before);
    ucFakeOutstand(['IcKjX' => 'pending']);

    ucRun();
    ucRun();

    $target = $post->targets()->sole();
    expect($target->status->value)->toBe('failed')
        ->and($target->fail_code)->toBe('not_confirmed')
        ->and($target->fail_reason)->toStartWith('Bluesky did not confirm the post within 5 minutes')
        ->and($post->fresh()->status->value)->toBe('failed')
        ->and(Credits::total($user))->toBe($before);
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/posts/VCioF'));
    Notification::assertSentToTimes($user, SocialPostFailed::class, 1);
});

it('waits 5 minutes, and takes a late result instead of failing it', function () {
    $user = ucUser();
    $early = ucSentPost($user, [ucAccount($user)], 3);
    ucFakeOutstand(['IcKjX' => 'pending']);
    ucRun();
    expect($early->targets()->sole()->status->value)->toBe('pending');
    Http::assertNothingSent();

    $late = ucSentPost($user, [ucAccount($user, 'bluesky', 'Late1')], 6);
    $late->update(['provider_post_id' => 'VCioF']);
    SocialPost::query()->whereKey($late->id)->update(['updated_at' => now()->subMinutes(6)]);
    $early->delete();
    ucFakeOutstand(['Late1' => 'published']);
    ucRun();

    expect($late->targets()->sole()->status->value)->toBe('published')
        ->and($late->fresh()->status->value)->toBe('published');
    Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'DELETE');
    Notification::assertNotSentTo($user, SocialPostFailed::class);
});

it('marks nothing when the relay will not cancel: tried again next run', function () {
    $user = ucUser();
    $post = ucSentPost($user, [ucAccount($user)], 6);
    ucFakeOutstand(['IcKjX' => 'pending'], 500);

    ucRun();

    expect($post->targets()->sole()->status->value)->toBe('pending')
        ->and($post->fresh()->status->value)->toBe('scheduled');
    Notification::assertNothingSent();
});

it('one destination out, one never confirmed: no cancel, the stuck one fails, the post reads published', function () {
    $user = ucUser();
    $post = ucSentPost($user, [ucAccount($user), ucAccount($user, 'x', 'Xacc1')], 6);
    ucFakeOutstand(['IcKjX' => 'pending', 'Xacc1' => 'published']);

    ucRun();

    $targets = $post->targets()->with('account')->get()->keyBy(fn ($t) => $t->account->provider_account_id);
    expect($targets['Xacc1']->status->value)->toBe('published')
        ->and($targets['IcKjX']->status->value)->toBe('failed')
        ->and($targets['IcKjX']->fail_code)->toBe('not_confirmed')
        ->and($post->fresh()->status->value)->toBe('published');
    Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'DELETE');
});

it('leaves a destination waiting out a platform limit alone', function () {
    $user = ucUser();
    $post = ucSentPost($user, [ucAccount($user)], 6);
    $post->targets()->update(['retry_at' => now()->addMinutes(10)]);
    ucFakeOutstand(['IcKjX' => 'pending']);

    ucRun();

    expect($post->targets()->sole()->status->value)->toBe('pending');
    Http::assertNothingSent();
});

it('runs every minute', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->description, 'FailUnconfirmedPublishes'));
    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});
