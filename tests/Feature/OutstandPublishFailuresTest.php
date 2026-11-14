<?php

use App\Models\File;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics;
use App\Support\Credits;
use App\Support\PublishFailure;
use App\Support\SocialPostRelay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * Publish failures of 13 Nov 2026, 23:50–23:55 MVT (Outstand posts CPhf8, KkzsV, zv5Xk, 9hP6F):
 *  (a) TikTok refused every post with url_ownership_unverified: we handed Outstand signed R2 links, and
 *      TikTok pulls media only from a domain verified for the TikTok app. Library files now go out from
 *      Outstand's media host (POST /media/upload → PUT → POST /media/{id}/confirm).
 *  (b) Bluesky refused video uploads with 401 (images fine): Bluesky takes video only from accounts with a
 *      verified email. Classified as video_upload_refused with that next step, not "reconnect".
 *  (c) YouTube answered release_pending with a retryAt: the shared upload pool opens then. The destination
 *      now waits (Pending, retry_at = Outstand's retryAt, a retry_reason in words) and is sent again.
 *  (d) A destination that fails for good gets its Credits back exactly once.
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

function opfUser(int $credits = 30_000): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, $credits, 'Test');

    return $user;
}

function opfAccount(User $user, string $platform): SocialAccount
{
    return SocialAccount::factory()->for($user)->create(['platform' => $platform, 'provider' => 'outstand']);
}

/** A post Outstand already took, one pending target per account, paid for. */
function opfSentPost(User $user, array $accounts): SocialPost
{
    $post = SocialPost::factory()->for($user)->create([
        'provider' => 'outstand', 'provider_post_id' => 'op_1', 'status' => 'scheduled', 'scheduled_at' => null,
        'media' => [['url' => 'https://cdn.example.test/clip.mp4']],
    ]);
    foreach ($accounts as $a) {
        $post->targets()->create(['social_account_id' => $a->id]);
    }
    Credits::chargeSend($post);

    return $post;
}

function opfHook($test, SocialAccount $account, string $error, string $postId = 'op_1')
{
    $body = json_encode(['event' => 'post.error', 'data' => ['postId' => $postId, 'socialAccounts' => [[
        'accountId' => $account->provider_account_id, 'network' => $account->platform->value, 'error' => $error,
    ]]]], JSON_THROW_ON_ERROR);

    return $test->call('POST', '/api/v1/webhooks/outstand', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_OUTSTAND_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'whsec_out'),
    ], $body);
}

const OPF_TIKTOK = 'TikTok API error (url_ownership_unverified): Please review our URL ownership verification rules at https://developers.tiktok.com/doc/content-posting-api-media-transfer-guide/#pull_from_url - TikTok refused to fetch the media because its domain is not verified for the TikTok app. Verify the media domain under URL Properties in the TikTok developer portal. (log_id: 20261114025205C425EF823747D12B8547)';
const OPF_BLUESKY = 'Error publishing post to Bluesky: Error: Failed to upload media: Bluesky video upload failed: Failed to upload video: 401';
const OPF_YOUTUBE = 'Shared YouTube upload capacity is waiting for the next release. Retry or schedule this destination later. [youtube_upload_error:release_pending; retryAt=2026-11-13T19:00:00.000Z]';

describe('(a) TikTok: media from Outstand\'s host, not our signed R2 links', function () {
    it('copies a library file to Outstand media and posts its public URL (cover too); other URLs pass through', function () {
        Storage::fake('s3');
        $user = opfUser();
        $tt = opfAccount($user, 'tiktok');
        $video = File::factory()->for($user)->create(['path' => 'u/'.$user->ulid.'/01M7AZ2QDP4WHM21D0Z0675693.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 11, 'ulid' => '01M7AZ2QDP4WHM21D0Z0675693']);
        Storage::disk('s3')->put($video->path, 'video-bytes');
        $r2 = 'https://acct.r2.cloudflarestorage.com/creatif-space/'.$video->path.'?X-Amz-Expires=86400&X-Amz-Signature=abc';

        Http::fake([
            'api.outstand.test/v1/media/upload' => Http::response(['success' => true, 'data' => ['id' => 'm1', 'upload_url' => 'https://outstand-media.r2.test/put/m1?sig=1', 'expires_in' => 3600]]),
            'outstand-media.r2.test/*' => Http::response('', 200),
            'api.outstand.test/v1/media/m1/confirm' => Http::response(['success' => true, 'data' => ['id' => 'm1', 'url' => 'https://media.outstand.so/org_x/m1/clip.mp4', 'status' => 'active']]),
            'api.outstand.test/v1/posts' => Http::response(['success' => true, 'post' => ['id' => 'p1', 'publishedAt' => null]]),
        ]);

        $this->actingAs($user)->postJson('/api/v1/social-posts', [
            'caption' => 'First time using Creatif Space?',
            'account_ids' => [$tt->ulid],
            'media' => [['url' => $r2]],
        ])->assertCreated();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.outstand.test/v1/media/upload'
            && $r['filename'] === '01M7AZ2QDP4WHM21D0Z0675693.mp4' && $r['content_type'] === 'video/mp4');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && str_starts_with($r->url(), 'https://outstand-media.r2.test/put/m1')
            && $r->body() === 'video-bytes' && $r->hasHeader('Content-Type', 'video/mp4'));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.outstand.test/v1/media/m1/confirm' && $r['size'] === 11);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.outstand.test/v1/posts'
            && $r['containers'][0]['media'] === [['url' => 'https://media.outstand.so/org_x/m1/clip.mp4', 'filename' => '01M7AZ2QDP4WHM21D0Z0675693.mp4']]
            && ! str_contains(json_encode($r->data()), 'r2.cloudflarestorage.com'));
    });

    it('does not copy a URL that is not one of our library files', function () {
        Http::fake(['api.outstand.test/v1/posts' => Http::response(['success' => true, 'post' => ['id' => 'p2', 'publishedAt' => null]])]);
        $user = opfUser();
        $tt = opfAccount($user, 'tiktok');

        $this->actingAs($user)->postJson('/api/v1/social-posts', ['caption' => 'Hi', 'account_ids' => [$tt->ulid], 'media' => [['url' => 'https://cdn.example.test/clip.mp4']]])->assertCreated();

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/media/'));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.outstand.test/v1/posts'
            && $r['containers'][0]['media'][0]['url'] === 'https://cdn.example.test/clip.mp4');
    });

    it('fails the send and gives the Credits back when the copy cannot be made', function () {
        Storage::fake('s3');
        $user = opfUser();
        $tt = opfAccount($user, 'tiktok');
        $file = File::factory()->for($user)->create(['path' => 'u/'.$user->ulid.'/01M7AZ2QDP4WHM21D0Z0675694.mp4', 'mime_type' => 'video/mp4', 'ulid' => '01M7AZ2QDP4WHM21D0Z0675694']);
        Storage::disk('s3')->put($file->path, 'v');
        Http::fake(['api.outstand.test/v1/media/upload' => Http::response(['success' => false, 'error' => 'nope'], 500)]);

        $this->actingAs($user)->postJson('/api/v1/social-posts', [
            'caption' => 'Hi', 'account_ids' => [$tt->ulid],
            'media' => [['url' => 'https://acct.r2.cloudflarestorage.com/creatif-space/'.$file->path.'?X-Amz-Signature=x']],
        ]);

        expect(Credits::balance($user))->toBe(30_000);
        Http::assertNotSent(fn (HttpRequest $r) => $r->url() === 'https://api.outstand.test/v1/posts');
    });
});

describe('(b) Bluesky video upload 401', function () {
    it('is video_upload_refused with the verified-email next step, not "reconnect"', function () {
        $f = PublishFailure::classify(OPF_BLUESKY, 'bluesky');

        expect($f['reason_code'])->toBe('video_upload_refused')
            ->and($f['error_code'])->toBe('media')
            ->and($f['reason'])->toContain('verified email')
            ->and($f['reason'])->not->toContain('reconnect')
            ->and(PublishFailure::retryable('video_upload_refused'))->toBeFalse();
    });

    it('fails that destination and pays it back once, even when the result is delivered twice', function () {
        $user = opfUser();
        $bs = opfAccount($user, 'bluesky');
        $post = opfSentPost($user, [$bs]);
        $after = Credits::balance($user);

        opfHook($this, $bs, OPF_BLUESKY)->assertOk();
        opfHook($this, $bs, OPF_BLUESKY)->assertOk();

        $t = $post->targets()->first();
        expect($t->status->value)->toBe('failed')
            ->and($t->fail_code)->toBe('video_upload_refused')
            ->and(Credits::balance($user))->toBe($after + Credits::perSend('bluesky'));
    });
});

describe('(c) YouTube release_pending', function () {
    it('is capacity_pending, retryable, waiting until Outstand\'s retryAt exactly', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-13T18:55:30Z'));
        $f = PublishFailure::classify(OPF_YOUTUBE, 'youtube');

        expect($f['reason_code'])->toBe('capacity_pending')
            ->and(PublishFailure::retryable('capacity_pending'))->toBeTrue()
            ->and(SocialPostRelay::retryAt('capacity_pending', 0, OPF_YOUTUBE)->utc()->toIso8601String())->toBe('2026-11-13T19:00:00+00:00')
            // daily_limit_reached / provider_quota_exhausted stay quota_exceeded.
            ->and(PublishFailure::classify('Shared YouTube uploads have reached the daily limit. [youtube_upload_error:daily_limit_reached; retryAt=2026-10-07T07:00:00.000Z]', 'youtube')['reason_code'])->toBe('quota_exceeded');
        CarbonImmutable::setTestNow();
    });

    it('keeps the destination pending (no refund, no failure) and says in words when it goes', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-13T18:55:30Z'));
        $user = opfUser();
        $yt = opfAccount($user, 'youtube');
        $post = opfSentPost($user, [$yt]);
        $after = Credits::balance($user);

        opfHook($this, $yt, OPF_YOUTUBE)->assertOk();

        $t = $post->targets()->first();
        expect($t->status->value)->toBe('pending')
            ->and($t->fail_code)->toBe('capacity_pending')
            ->and($t->retry_at->utc()->toIso8601String())->toBe('2026-11-13T19:00:00+00:00')
            ->and(Credits::balance($user))->toBe($after);

        $this->actingAs($user)->getJson('/api/v1/social-posts/'.$post->ulid)->assertOk()
            ->assertJsonPath('data.targets.0.status', 'pending')
            ->assertJsonPath('data.targets.0.retry_reason', "YouTube's shared upload capacity is full right now. It is sent again automatically at 19:00 UTC.");
        CarbonImmutable::setTestNow();
    });

    it('fails and pays back once when the retries run out', function () {
        $user = opfUser();
        $yt = opfAccount($user, 'youtube');
        $post = opfSentPost($user, [$yt]);
        $after = Credits::balance($user);
        $post->targets()->update(['attempts' => SocialPostRelay::MAX_RETRIES]);

        opfHook($this, $yt, OPF_YOUTUBE)->assertOk();
        opfHook($this, $yt, OPF_YOUTUBE)->assertOk();

        $t = $post->targets()->first();
        expect($t->status->value)->toBe('failed')
            ->and($t->fail_code)->toBe('capacity_pending')
            ->and($t->fail_reason)->toContain('not charged')
            ->and(Credits::balance($user))->toBe($after + Credits::perSend('youtube'));
    });
});

describe('(d) Credits for a failed TikTok destination', function () {
    it('come back once; the other destinations stay paid', function () {
        $user = opfUser();
        $tt = opfAccount($user, 'tiktok');
        $fb = opfAccount($user, 'facebook');
        $post = opfSentPost($user, [$tt, $fb]);
        $after = Credits::balance($user);

        opfHook($this, $tt, OPF_TIKTOK)->assertOk();
        opfHook($this, $tt, OPF_TIKTOK)->assertOk();

        expect($post->targets()->where('social_account_id', $tt->id)->first()->status->value)->toBe('failed')
            ->and($post->targets()->where('social_account_id', $fb->id)->first()->status->value)->toBe('pending')
            ->and(Credits::balance($user))->toBe($after + Credits::perSend('tiktok'));
    });
});
