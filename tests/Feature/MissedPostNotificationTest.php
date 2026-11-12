<?php

use App\Jobs\NotifyMissedPosts;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Notifications\SocialPostFailed;
use App\Notifications\SocialPostMissed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * "Post missed / failed" (publish_failed): a scheduled post whose time
 * passed 30 minutes ago and has not gone out emails its owner once; a post
 * that fails emails once; the two share one email per post; the switch
 * silences the email; no vendor name ever reaches the user.
 */

beforeEach(function () {
    Notification::fake();
    Http::fake(['*' => Http::response([], 500)]);
    config(['app.frontend_url' => 'https://app.creatif.test']);
});

function mpRun(): void
{
    app()->call([new NotifyMissedPosts, 'handle']);
}

function mpPost(User $user, int $minutesAgo, array $attrs = []): SocialPost
{
    $post = SocialPost::factory()->for($user)->create([
        'caption' => 'Winter set is here',
        'provider_post_id' => null,
        'approval' => 'client',
        'scheduled_at' => now()->subMinutes($minutesAgo),
        ...$attrs,
    ]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($user)->create()->id, 'status' => 'pending']);

    return $post;
}

it('emails once when a held post misses its time, and never twice', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);
    $post = mpPost($user, 40);

    mpRun();
    mpRun();

    Notification::assertSentToTimes($user, SocialPostMissed::class, 1);
    Notification::assertSentTo($user, SocialPostMissed::class, fn ($n) => in_array('mail', $n->via($user), true));
    expect($post->fresh()->failure_notified_at)->not->toBeNull();
});

it('waits 30 minutes, and leaves posts older than a day alone', function () {
    $user = User::factory()->create();
    mpPost($user, 10);
    mpPost($user, 25 * 60);
    mpPost($user, 40, ['status' => 'published']);
    mpPost($user, 40, ['status' => 'draft']);

    mpRun();

    Notification::assertNothingSent();
});

it('reports a sent post that is still not confirmed after 30 minutes', function () {
    $user = User::factory()->create();
    mpPost($user, 45, ['provider_post_id' => 'post_sent_1', 'approval' => null, 'provider' => 'postforme']);

    mpRun();

    Notification::assertSentTo($user, SocialPostMissed::class, fn ($n) => $n->why() === 'It wasn’t confirmed as published.');
});

it('respects the publish_failed switch: no email, still only once', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->patchJson('/api/v1/me/notification-settings', ['publish_failed' => false])->assertOk();
    $post = mpPost($user, 40);

    mpRun();

    $user = $user->fresh();
    Notification::assertSentTo($user, SocialPostMissed::class, fn ($n) => ! in_array('mail', $n->via($user), true));
    expect((new SocialPostMissed($post))->via($user))->not->toContain('mail')
        ->and((new SocialPostFailed($post))->via($user))->not->toContain('mail');
});

it('sends one email per post: a missed post that then fails sends no second one', function () {
    $user = User::factory()->create();
    $post = mpPost($user, 40, ['approval' => null, 'provider_post_id' => 'post_x']);
    mpRun();

    $post->targets()->update(['status' => 'failed', 'fail_reason' => 'Instagram refused it.']);
    $post->fresh()->recomputeFromTargets();

    Notification::assertSentToTimes($user, SocialPostMissed::class, 1);
    Notification::assertNotSentTo($user, SocialPostFailed::class);
    expect($post->fresh()->status->value)->toBe('failed');
});

it('emails a failed post once, however often the result is recomputed', function () {
    $user = User::factory()->create();
    $post = mpPost($user, 0, ['approval' => null, 'provider_post_id' => 'post_y', 'scheduled_at' => now()->addHour()]);
    $post->targets()->update(['status' => 'failed', 'fail_reason' => 'Instagram refused it.']);

    $post->fresh()->recomputeFromTargets();
    $post->fresh()->recomputeFromTargets();

    Notification::assertSentToTimes($user, SocialPostFailed::class, 1);
});

it('never names the service behind the app', function () {
    $user = User::factory()->create(['name' => 'Rani']);
    $post = mpPost($user, 40);

    $missed = (new SocialPostMissed($post))->toMail($user);
    $failed = (new SocialPostFailed(tap($post)->forceFill(['fail_reason' => 'Outstand error 500: upstream'])))->toMail($user);

    $text = implode("\n", [$missed->subject, ...$missed->introLines, ...$missed->outroLines, $failed->subject, ...$failed->introLines, ...$failed->outroLines]);
    expect($text)->toContain('Post missed its time: Winter set is here')
        ->toContain('It was still waiting for your client’s approval.')
        ->toContain('No reason was given.')
        ->not->toMatch(SocialPostFailed::VENDORS);
});

it('is scheduled every five minutes', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->description, NotifyMissedPosts::class));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('*/5 * * * *');
});
