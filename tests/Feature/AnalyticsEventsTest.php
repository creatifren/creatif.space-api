<?php

/*
 * Server-side product analytics: every trigger in the tracking plan sends
 * its event exactly once, with the agreed name and property keys, on the
 * user's ULID — and webhooks that repeat do not repeat the event.
 */

use App\Jobs\SendAnalytics;
use App\Models\Affiliate;
use App\Models\CreditPurchase;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\SocialPost;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics;
use App\Services\AnalyticsFake;
use App\Support\Credits;
use App\Support\ProductEvents;
use App\Support\SocialPostRelay;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use PostHog\Client;

beforeEach(function () {
    foreach (Plan::all() as $plan) {
        $plan->update(['quotas' => [...$plan->quotas, 'social_posts_monthly' => 0, 'credits_monthly' => 0]]);
    }
    config([
        'services.postforme.key' => 'test-key',
        'services.postforme.base_url' => 'https://api.postforme.test/v1',
        'services.postforme.webhook_secret' => 'whsec_test',
        'services.outstand.key' => 'sk_test',
        'services.outstand.base_url' => 'https://api.outstand.test/v1',
        'services.outstand.webhook_secret' => 'whsec_out',
        'app.frontend_url' => 'https://app.test',
    ]);
    Cache::flush();
    Notification::fake();
    $this->analytics = Analytics::fake();
});

function ae(): AnalyticsFake
{
    return test()->analytics;
}

function aePaidUser(): User
{
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    Credits::grant($user, 30_000, 'Test');

    return $user;
}

function aeHook(array $payload)
{
    return test()->postJson('/api/v1/webhooks/postforme', $payload, ['Post-For-Me-Webhook-Secret' => 'whsec_test']);
}

function aeOutstandHook(array $payload)
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return test()->call('POST', '/api/v1/webhooks/outstand', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_OUTSTAND_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'whsec_out'),
    ], $body);
}

function aeCreem(array $event)
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);

    return test()->call('POST', '/api/v1/webhooks/creem', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_CREEM_SIGNATURE' => hash_hmac('sha256', $body, config('services.creem.webhook_secret')),
    ], $body);
}

function aeHeldPost(User $user, string $approval = 'client'): SocialPost
{
    $post = SocialPost::factory()->for($user)->create(['provider_post_id' => null, 'approval' => $approval]);
    $post->targets()->create(['social_account_id' => SocialAccount::factory()->for($user)->create()->id, 'status' => 'pending']);

    return $post;
}

describe('the service', function () {
    it('queues nothing when the key is empty', function () {
        Queue::fake();
        app()->forgetInstance(Analytics::class);
        app()['env'] = 'production';
        config(['services.posthog.key' => '']);

        app(Analytics::class)->capture('01JULID', 'user_signed_up', ['method' => 'email']);
        app(Analytics::class)->set('01JULID', ['plan' => 'free']);

        Queue::assertNothingPushed();
    });

    it('is off under tests even with a key', function () {
        Queue::fake();
        app()->forgetInstance(Analytics::class);
        config(['services.posthog.key' => 'phc_test']);

        app(Analytics::class)->capture('01JULID', 'user_signed_up');

        Queue::assertNothingPushed();
    });

    it('queues one job per call once a key is set outside tests', function () {
        Queue::fake();
        app()->forgetInstance(Analytics::class);
        app()['env'] = 'production';
        config(['services.posthog.key' => 'phc_test']);

        app(Analytics::class)->capture('01JULID', 'post_created', ['post_id' => 'p1']);
        app(Analytics::class)->set('01JULID', ['plan' => 'pro']);

        Queue::assertPushed(SendAnalytics::class, 2);
        Queue::assertPushed(SendAnalytics::class, fn (SendAnalytics $job) => $job->message['type'] === 'capture'
            && $job->message['distinct_id'] === '01JULID'
            && $job->message['event'] === 'post_created');
    });

    it('swallows and logs a PostHog failure inside the job', function () {
        Log::spy();
        config(['services.posthog.key' => 'phc_test']);
        $job = new class(['type' => 'capture', 'distinct_id' => 'u', 'event' => 'x', 'properties' => []]) extends SendAnalytics
        {
            protected function client(string $key): Client
            {
                throw new RuntimeException('posthog down');
            }
        };

        $job->handle();

        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => $msg === 'analytics.send_failed')->once();
    });

    it('marks internal accounts by address or domain', function () {
        config([
            'services.posthog.internal_email_domains' => ['creatif.space'],
            'services.posthog.internal_emails' => ['qa@gmail.com'],
        ]);

        expect(Analytics::isInternal(User::factory()->make(['email' => 'ren@creatif.space'])))->toBeTrue()
            ->and(Analytics::isInternal(User::factory()->make(['email' => 'a@mail.creatif.space'])))->toBeTrue()
            ->and(Analytics::isInternal(User::factory()->make(['email' => 'QA@gmail.com'])))->toBeTrue()
            ->and(Analytics::isInternal(User::factory()->make(['email' => 'ren@notcreatif.space'])))->toBeFalse()
            ->and(Analytics::isInternal(User::factory()->make(['email' => 'client@gmail.com'])))->toBeFalse();
    });

    it('reads the internal lists from comma-separated env values', function () {
        $env = ['ANALYTICS_INTERNAL_EMAIL_DOMAINS' => ' creatif.space, @studio.test ,', 'ANALYTICS_INTERNAL_EMAILS' => 'QA@gmail.com'];
        foreach ($env as $k => $v) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
        try {
            $services = require base_path('config/services.php');
        } finally {
            foreach (array_keys($env) as $k) {
                putenv($k);
                unset($_ENV[$k], $_SERVER[$k]);
            }
        }

        expect($services['posthog']['internal_email_domains'])->toBe(['creatif.space', 'studio.test'])
            ->and($services['posthog']['internal_emails'])->toBe(['qa@gmail.com'])
            ->and($services['posthog']['host'])->toBe('https://us.i.posthog.com');
    });
});

describe('user_signed_up', function () {
    it('fires once for an email signup, with attribution and person properties', function () {
        $this->postJson('/auth/register', [
            'email' => 'ren@studio.test', 'password' => 'long-enough-1',
            'ref' => 'RANI10', 'utm_source' => 'instagram', 'utm_campaign' => 'launch',
        ])->assertStatus(202);

        $user = User::query()->sole();
        $m = ae()->assertCapturedWithKeys('user_signed_up', ['is_staff', 'signup_source', 'method', 'plan', 'platform', 'ref', 'utm_source', 'utm_campaign']);
        expect($m['distinct_id'])->toBe($user->ulid)
            ->and($m['properties'])->toMatchArray(['method' => 'email', 'plan' => 'free', 'ref' => 'RANI10', 'utm_source' => 'instagram'])
            ->and(ae()->setFor($user->ulid)[0])->toMatchArray(['plan' => 'free', 'signup_method' => 'email', 'is_internal' => false])
            ->and(ae()->setFor($user->ulid)[0])->toHaveKey('signed_up_at');

        // The same address again is a reset link, not a signup.
        $this->postJson('/auth/register', ['email' => 'ren@studio.test', 'password' => 'long-enough-1'])->assertStatus(202);
        ae()->assertCapturedOnce('user_signed_up');
    });

    it('never sends the email address', function () {
        $this->postJson('/auth/register', ['email' => 'secret@example.com', 'password' => 'long-enough-1'])->assertStatus(202);

        expect(json_encode(ae()->sent))->not->toContain('secret@example.com');
        ae()->assertCapturedWithKeys('user_signed_up', ['is_staff', 'signup_source', 'method', 'plan', 'platform']);
    });

    it('fires once for a Google signup, not for the login after', function () {
        $this->get('/auth/google/redirect?ref=RANI10&utm_source=tiktok&utm_medium=bio')
            ->assertSessionHas('signup_utm', ['utm_source' => 'tiktok', 'utm_medium' => 'bio']);

        $google = (new GoogleUser)->map(['id' => 'g-1', 'name' => 'Rani', 'email' => 'rani@example.com', 'avatar' => null]);
        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('user')->andReturn($google);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $this->get('/auth/google/callback?code=abc')->assertRedirect();
        auth()->logout();
        $this->get('/auth/google/callback?code=abc')->assertRedirect();

        $user = User::query()->sole();
        $m = ae()->assertCapturedWithKeys('user_signed_up', ['is_staff', 'signup_source', 'method', 'plan', 'platform', 'ref', 'utm_source', 'utm_medium']);
        expect($m['distinct_id'])->toBe($user->ulid)
            ->and($m['properties']['method'])->toBe('google')
            ->and(ae()->setFor($user->ulid)[0]['is_internal'])->toBeFalse();
    });
});

describe('user_signed_up: is_staff, signup_source, plan, exactly once (CS signup funnel, 12 Nov)', function () {
    it('names the source: utm_source, else referral, else the entry, else direct', function (array $attribution, string $expected) {
        expect(ProductEvents::signupSource($attribution))->toBe($expected);
    })->with([
        'utm wins' => [['utm_source' => 'instagram', 'ref' => 'RANI10', 'entry' => 'tpl-base'], 'instagram'],
        'referral' => [['ref' => 'RANI10', 'entry' => 'tpl-base'], 'referral'],
        'entry' => [['entry' => 'tpl-restaurants'], 'tpl-restaurants'],
        'nothing' => [[], 'direct'],
        'empty values' => [['utm_source' => '', 'ref' => ''], 'direct'],
    ]);

    it('sends is_staff, signup_source and the plan on a Google signup from a campaign', function () {
        $this->get('/auth/google/redirect?ref=RANI10&utm_source=newsletter&utm_campaign=nov');

        aeGoogleCallback();
        $this->get('/auth/google/callback?code=abc')->assertRedirect();

        $m = ae()->assertCapturedWithKeys('user_signed_up', ['is_staff', 'signup_source', 'method', 'plan', 'platform', 'ref', 'utm_source', 'utm_campaign']);
        expect($m['properties'])->toMatchArray([
            'method' => 'google',
            'plan' => 'free',
            'is_staff' => false,
            'signup_source' => 'newsletter',
            'ref' => 'RANI10',
        ]);
    });

    it('says referral or direct when there is no campaign', function () {
        $this->postJson('/auth/register', ['email' => 'a@example.com', 'password' => 'long-enough-1', 'ref' => 'RANI10'])->assertStatus(202);
        $this->postJson('/auth/register', ['email' => 'b@example.com', 'password' => 'long-enough-1'])->assertStatus(202);

        $sources = array_map(fn (array $m) => $m['properties']['signup_source'], ae()->captured('user_signed_up'));
        expect($sources)->toBe(['referral', 'direct']);
    });

    it('is sent once per user, however often signedUp runs for them', function () {
        $user = User::factory()->create(['email' => 'once@example.com']);

        ProductEvents::signedUp($user, 'google');
        ProductEvents::signedUp($user, 'google');
        ProductEvents::signedUp($user, 'email');

        ae()->assertCapturedOnce('user_signed_up');
        expect(ae()->captured('user_signed_up')[0]['distinct_id'])->toBe($user->ulid);
    });

    it('is sent once for an email signup that later signs in with Google', function () {
        $this->postJson('/auth/register', ['email' => 'rani@example.com', 'password' => 'long-enough-1'])->assertStatus(202);

        aeGoogleCallback();
        $this->get('/auth/google/callback?code=abc')->assertRedirect();
        auth()->logout();
        $this->get('/auth/google/callback?code=abc')->assertRedirect();

        expect(User::query()->count())->toBe(1);
        ae()->assertCapturedOnce('user_signed_up');
        expect(ae()->captured('user_signed_up')[0]['properties']['method'])->toBe('email');
    });

    it('two users each get their own', function () {
        ProductEvents::signedUp(User::factory()->create(['email' => 'x@example.com']), 'email');
        ProductEvents::signedUp(User::factory()->create(['email' => 'y@example.com']), 'email');

        expect(ae()->captured('user_signed_up'))->toHaveCount(2);
    });

    it('sends nothing for internal accounts: staff or an internal address', function () {
        ProductEvents::signedUp(User::factory()->create(['email' => 'ops@example.com', 'is_staff' => true]), 'google');
        ProductEvents::signedUp(User::factory()->create(['email' => 'hello@creatif.space']), 'google');

        ae()->assertNotCaptured('user_signed_up');
    });

    it('still sends when the cache is down (the call sites already run once per new row)', function () {
        Cache::shouldReceive('add')->andThrow(new RuntimeException('cache down'));

        ProductEvents::signedUp(User::factory()->create(['email' => 'nocache@example.com']), 'email');

        ae()->assertCapturedOnce('user_signed_up');
    });
});

/** Socialite answers the Google callback with a new user. */
function aeGoogleCallback(): void
{
    $google = (new GoogleUser)->map(['id' => 'g-entry', 'name' => 'Rani', 'email' => 'rani@example.com', 'avatar' => null]);
    $driver = Mockery::mock(GoogleProvider::class);
    $driver->shouldReceive('user')->andReturn($google);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
}

describe('user_signed_up entry and utm_term/utm_content', function () {
    it('sends entry, initial_entry ($set_once) and the full UTM set for an email signup', function () {
        $this->postJson('/auth/register', [
            'email' => 'andi@example.com', 'password' => 'long-enough-1',
            'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'launch',
            'utm_term' => 'client approval', 'utm_content' => 'ad-b',
            'entry' => 'pricing-hero',
        ])->assertStatus(202);

        $m = ae()->assertCapturedWithKeys('user_signed_up', [
            'is_staff', 'signup_source', 'method', 'plan', 'platform', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'entry', '$set_once',
        ]);
        expect($m['properties'])->toMatchArray([
            'utm_term' => 'client approval',
            'utm_content' => 'ad-b',
            'entry' => 'pricing-hero',
            '$set_once' => ['initial_entry' => 'pricing-hero'],
        ]);
    });

    it('carries entry and utm_term/utm_content through the Google redirect', function () {
        $this->get('/auth/google/redirect?utm_term=approval&utm_content=hero-cta&entry=mcp-page')
            ->assertSessionHas('signup_utm', ['utm_term' => 'approval', 'utm_content' => 'hero-cta'])
            ->assertSessionHas('signup_entry', 'mcp-page');

        aeGoogleCallback();
        $this->get('/auth/google/callback?code=abc')->assertRedirect();

        $m = ae()->assertCapturedWithKeys('user_signed_up', ['is_staff', 'signup_source', 'method', 'plan', 'platform', 'utm_term', 'utm_content', 'entry', '$set_once']);
        expect($m['properties'])->toMatchArray([
            'method' => 'google',
            'entry' => 'mcp-page',
            '$set_once' => ['initial_entry' => 'mcp-page'],
        ]);
    });

    it('drops an invalid entry without blocking the signup', function (mixed $entry) {
        $this->postJson('/auth/register', [
            'email' => 'andi@example.com', 'password' => 'long-enough-1', 'entry' => $entry,
        ])->assertStatus(202);

        ae()->assertCapturedWithKeys('user_signed_up', ['is_staff', 'signup_source', 'method', 'plan', 'platform']);
    })->with([
        'uppercase' => 'Pricing',
        'space' => 'pricing hero',
        'too long' => str_repeat('a', 41),
        'path' => '../admin',
        'empty' => '',
        'array' => [['a']],
    ]);

    it('drops an invalid entry on the Google redirect', function () {
        $this->get('/auth/google/redirect?entry='.urlencode('Pricing Hero'))->assertSessionMissing('signup_entry');

        aeGoogleCallback();
        $this->get('/auth/google/callback?code=abc')->assertRedirect();

        ae()->assertCapturedWithKeys('user_signed_up', ['is_staff', 'signup_source', 'method', 'plan', 'platform']);
    });

    it('never treats entry as a referral code', function () {
        Affiliate::factory()->approved()->create(['code' => 'rani10']);

        $this->postJson('/auth/register', [
            'email' => 'andi@example.com', 'password' => 'long-enough-1', 'entry' => 'rani10',
        ])->assertStatus(202);
        $this->get('/auth/google/redirect?entry=rani10')->assertSessionMissing('referral_code');

        expect(Referral::query()->count())->toBe(0)
            ->and(ae()->captured('user_signed_up')[0]['properties'])->not->toHaveKey('ref');
    });
});

describe('channels', function () {
    it('fires channel_connected once per new account on sync, and $sets channels_count', function () {
        $user = User::factory()->create();
        Http::fake(['api.postforme.test/*' => Http::response(['data' => [
            ['id' => 'acc_1', 'platform' => 'instagram', 'username' => '@a', 'status' => 'connected'],
            ['id' => 'acc_2', 'platform' => 'tiktok', 'username' => '@b', 'status' => 'connected'],
        ]])]);

        $this->actingAs($user)->postJson('/api/v1/social-accounts/sync')->assertOk();
        $this->actingAs($user)->postJson('/api/v1/social-accounts/sync')->assertOk();

        ae()->assertCapturedTimes('channel_connected', 2);
        $first = ae()->captured('channel_connected')[0];
        expect(array_keys($first['properties']))->toEqualCanonicalizing(['network', 'is_first_channel', 'channels_count', 'plan', 'platform'])
            ->and($first['distinct_id'])->toBe($user->ulid)
            ->and($first['properties'])->toBe(['network' => 'instagram', 'is_first_channel' => true, 'channels_count' => 1, 'plan' => 'free', 'platform' => 'web'])
            ->and(ae()->captured('channel_connected')[1]['properties'])
            ->toBe(['network' => 'tiktok', 'is_first_channel' => false, 'channels_count' => 2, 'plan' => 'free', 'platform' => 'web'])
            ->and(collect(ae()->setFor($user->ulid))->last())->toBe(['channels_count' => 2]);
    });

    it('does not count a connect twice when the webhook stored it before the sync', function () {
        $user = User::factory()->create();
        aeHook(['event_type' => 'social.account.created', 'data' => [
            'id' => 'acc_hook', 'platform' => 'threads', 'status' => 'connected', 'external_id' => $user->ulid,
        ]])->assertOk();
        aeHook(['event_type' => 'social.account.created', 'data' => [
            'id' => 'acc_hook', 'platform' => 'threads', 'status' => 'connected', 'external_id' => $user->ulid,
        ]])->assertOk();
        Http::fake(['api.postforme.test/*' => Http::response(['data' => [
            ['id' => 'acc_hook', 'platform' => 'threads', 'status' => 'connected'],
        ]])]);
        $this->actingAs($user)->postJson('/api/v1/social-accounts/sync')->assertOk();

        ae()->assertCapturedOnce('channel_connected', fn ($p) => $p['network'] === 'threads');
    });

    it('$sets channels_count on disconnect', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['message' => 'ok'])]);
        $user = User::factory()->create();
        $keep = SocialAccount::factory()->for($user)->create();
        $gone = SocialAccount::factory()->for($user)->create();

        $this->actingAs($user)->deleteJson("/api/v1/social-accounts/{$gone->ulid}")->assertNoContent();

        expect(ae()->setFor($user->ulid))->toBe([['channels_count' => 1]]);
        ae()->assertNotCaptured('channel_connected');
    });
});

describe('post_created', function () {
    it('fires once with the post shape and nothing from the caption', function (array $extra, string $status, bool $sends) {
        Http::fake(['api.postforme.test/*' => Http::response(['id' => 'pfm_1', 'status' => 'scheduled'])]);
        $user = aePaidUser();
        $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
        $x = SocialAccount::factory()->for($user)->create(['platform' => 'x']);

        $this->actingAs($user)->postJson('/api/v1/social-posts', [
            'caption' => 'Secret launch caption', 'account_ids' => [$ig->ulid, $x->ulid],
            'media' => [['url' => 'https://cdn.test/a.jpg']],
        ] + $extra)->assertCreated();

        $m = ae()->assertCapturedWithKeys('post_created', ['post_id', 'status', 'networks', 'destinations_count', 'has_media', 'approval_required']);
        expect($m['distinct_id'])->toBe($user->ulid)
            ->and($m['properties'])->toMatchArray([
                'post_id' => SocialPost::query()->sole()->ulid,
                'status' => $status,
                'networks' => ['instagram', 'x'],
                'destinations_count' => 2,
                'has_media' => true,
                'approval_required' => $status === 'approval',
            ])
            ->and(json_encode(ae()->sent))->not->toContain('Secret launch caption');
        $sends ? Http::assertSentCount(1) : Http::assertNothingSent();
    })->with([
        'now' => [[], 'now', true],
        'scheduled' => [['scheduled_at' => now()->addDay()->toIso8601String()], 'scheduled', true],
        'draft' => [['draft' => true], 'draft', false],
        'approval' => [['approval' => 'awaiting'], 'approval', false],
    ]);

    it('sends nothing when the provider refuses the post', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['error' => 'down'], 500)]);
        $user = aePaidUser();
        $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);

        $this->actingAs($user)->postJson('/api/v1/social-posts', ['caption' => 'Hi', 'media' => [['url' => 'https://cdn.example.test/photo.jpg']], 'account_ids' => [$ig->ulid]])->assertServerError();

        ae()->assertNotCaptured('post_created');
    });
});

describe('approval links', function () {
    it('fires approval_link_created with is_first_link only the first time', function () {
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create(['plan_id' => Plan::query()->where('key', 'max')->value('id')]);
        $a = aeHeldPost($user, 'awaiting');
        $b = aeHeldPost($user, 'awaiting');

        $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test',
            'post_ids' => [$a->ulid, $b->ulid], 'expires_in_hours' => 168, 'client_name' => 'Client Co',
        ])->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/social-approval-links', ['approver_email' => 'client@example.test', 'post_ids' => [$a->ulid]])->assertCreated();

        ae()->assertCapturedTimes('approval_link_created', 2);
        [$first, $second] = ae()->captured('approval_link_created');
        expect(array_keys($first['properties']))->toEqualCanonicalizing(['link_id', 'posts_count', 'expires_in_days', 'is_first_link', 'plan', 'platform'])
            ->and($first['properties'])->toMatchArray(['posts_count' => 2, 'expires_in_days' => 7.0, 'is_first_link' => true])
            ->and($second['properties'])->toMatchArray(['posts_count' => 1, 'expires_in_days' => null, 'is_first_link' => false])
            ->and(json_encode(ae()->sent))->not->toContain('Client Co');
    });

    it('fires approval_link_opened on the owner, once per visitor per day', function () {
        $owner = User::factory()->create();
        $post = aeHeldPost($owner);
        $link = SocialApprovalLink::create(['user_id' => $owner->id]);
        $link->posts()->attach($post->id);

        $this->getJson("/api/v1/a/{$link->token}")->assertOk();
        $this->getJson("/api/v1/a/{$link->token}")->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])->getJson("/api/v1/a/{$link->token}")->assertOk();

        ae()->assertCapturedTimes('approval_link_opened', 2);
        $m = ae()->captured('approval_link_opened')[0];
        expect($m['distinct_id'])->toBe($owner->ulid)
            ->and($m['properties'])->toBe(['link_id' => $link->ulid, 'posts_count' => 1]);

        $this->travel(1)->days();
        $this->getJson("/api/v1/a/{$link->token}")->assertOk();
        ae()->assertCapturedTimes('approval_link_opened', 3);
    });

    it('fires approval_decision_made for approve, changes and retract, on the owner', function () {
        Http::fake(['api.postforme.test/*' => Http::response(['id' => 'pfm_ok', 'status' => 'scheduled'])]);
        $owner = aePaidUser();
        $a = aeHeldPost($owner);
        $b = aeHeldPost($owner);
        $link = SocialApprovalLink::create(['user_id' => $owner->id]);
        $link->posts()->attach([$a->id, $b->id]);
        $this->travel(3)->hours();

        asApprover($this)->postJson("/api/v1/a/{$link->token}/posts/{$a->ulid}/decision", ['name' => 'Andi', 'decision' => 'approve'])->assertOk();
        // The same yes again is answered, not refused — and fires nothing.
        asApprover($this)->postJson("/api/v1/a/{$link->token}/posts/{$a->ulid}/decision", ['name' => 'Andi', 'decision' => 'approve'])->assertOk();
        asApprover($this)->postJson("/api/v1/a/{$link->token}/posts/{$b->ulid}/decision", ['name' => 'Andi', 'decision' => 'revise', 'reason' => 'Darker'])->assertOk();
        asApprover($this)->postJson("/api/v1/a/{$link->token}/posts/{$a->ulid}/retract", ['name' => 'Andi'])->assertOk();

        $all = ae()->captured('approval_decision_made');
        expect(collect($all)->pluck('properties.decision')->all())->toBe(['approved', 'changes_requested', 'retracted'])
            ->and(collect($all)->pluck('distinct_id')->unique()->all())->toBe([$owner->ulid])
            ->and(array_keys($all[0]['properties']))->toEqualCanonicalizing(['link_id', 'post_id', 'decision', 'hours_since_link', 'plan', 'platform'])
            ->and($all[0]['properties'])->toMatchArray(['link_id' => $link->ulid, 'post_id' => $a->ulid, 'hours_since_link' => 3.0])
            ->and(json_encode($all))->not->toContain('Andi')->not->toContain('Darker');
    });

    it('fires one approval_decision_made per post an Approve-all sent', function () {
        Http::fake(['api.postforme.test/*' => fn () => Http::response(['id' => 'pfm_'.str()->random(8), 'status' => 'scheduled'])]);
        $owner = aePaidUser();
        $a = aeHeldPost($owner);
        $b = aeHeldPost($owner);
        $link = SocialApprovalLink::create(['user_id' => $owner->id]);
        $link->posts()->attach([$a->id, $b->id]);

        asApprover($this)->postJson("/api/v1/a/{$link->token}/approve-all", ['name' => 'Andi', 'post_ids' => [$a->ulid, $b->ulid]])->assertOk();
        // Approve-all again: answered (idempotent), no second event.
        asApprover($this)->postJson("/api/v1/a/{$link->token}/approve-all", ['name' => 'Andi', 'post_ids' => [$a->ulid, $b->ulid]])->assertOk();

        ae()->assertCapturedTimes('approval_decision_made', 2, fn ($p) => $p['decision'] === 'approved');
    });
});

describe('publishing results', function () {
    it('fires post_published once per destination, deduped across redeliveries', function () {
        config(['services.social.provider' => 'postforme']);
        $user = aePaidUser();
        $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
        $tt = SocialAccount::factory()->for($user)->create(['platform' => 'tiktok']);
        $post = SocialPost::factory()->for($user)->create(['provider_post_id' => 'post_ok', 'scheduled_at' => now()->addHour()]);
        $post->targets()->create(['social_account_id' => $ig->id]);
        $post->targets()->create(['social_account_id' => $tt->id]);
        Credits::chargeSend($post);

        $result = fn (SocialAccount $a) => ['event_type' => 'social.post.result.created', 'data' => [
            'id' => 'res_'.$a->id, 'post_id' => 'post_ok', 'social_account_id' => $a->provider_account_id, 'success' => true,
        ]];
        aeHook($result($ig))->assertOk();
        aeHook($result($ig))->assertOk();
        aeHook($result($tt))->assertOk();

        ae()->assertCapturedTimes('post_published', 2);
        [$first, $second] = ae()->captured('post_published');
        expect(array_keys($first['properties']))->toEqualCanonicalizing(['post_id', 'network', 'credits_charged', 'via', 'is_first_publish', 'source', 'plan', 'platform'])
            ->and($first['distinct_id'])->toBe($user->ulid)
            ->and($first['properties'])->toMatchArray(['post_id' => $post->ulid, 'network' => 'instagram', 'via' => 'scheduled', 'is_first_publish' => true, 'source' => 'webhook'])
            ->and($first['properties']['credits_charged'])->toBeGreaterThan(0)
            ->and($second['properties'])->toMatchArray(['network' => 'tiktok', 'is_first_publish' => false]);
    });

    it('tells after_approval and immediate apart', function () {
        config(['services.social.provider' => 'postforme']);
        $user = aePaidUser();
        $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
        $approved = SocialPost::factory()->for($user)->create(['provider_post_id' => 'p_appr', 'approval' => 'approved', 'scheduled_at' => null]);
        $now = SocialPost::factory()->for($user)->create(['provider_post_id' => 'p_now', 'scheduled_at' => null]);
        $approved->targets()->create(['social_account_id' => $ig->id]);
        $now->targets()->create(['social_account_id' => $ig->id]);

        foreach (['p_appr', 'p_now'] as $id) {
            aeHook(['event_type' => 'social.post.result.created', 'data' => [
                'post_id' => $id, 'social_account_id' => $ig->provider_account_id, 'success' => true,
            ]])->assertOk();
        }

        expect(collect(ae()->captured('post_published'))->pluck('properties.via')->all())->toBe(['after_approval', 'immediate']);
    });

    it('fires post_published from an Outstand webhook, once', function () {
        config(['services.social.provider' => 'outstand']);
        $user = aePaidUser();
        $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram', 'provider' => 'outstand']);
        $post = SocialPost::factory()->for($user)->create(['provider' => 'outstand', 'provider_post_id' => '9dyJS']);
        $post->targets()->create(['social_account_id' => $ig->id]);
        $payload = ['event' => 'post.published', 'timestamp' => now()->toIso8601String(), 'data' => [
            'postId' => '9dyJS', 'socialAccounts' => [['accountId' => $ig->provider_account_id, 'network' => 'instagram', 'platformPostUrl' => 'https://instagram.com/p/x']],
        ]];

        aeOutstandHook($payload)->assertOk();
        aeOutstandHook($payload)->assertOk();

        ae()->assertCapturedOnce('post_published', fn ($p) => $p['network'] === 'instagram');
    });

    it('fires post_publish_failed once with a code, never the platform text', function () {
        config(['services.social.provider' => 'outstand']);
        $user = aePaidUser();
        $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram', 'provider' => 'outstand']);
        $post = SocialPost::factory()->for($user)->create(['provider' => 'outstand', 'provider_post_id' => 'bad1']);
        $post->targets()->create(['social_account_id' => $ig->id]);
        $payload = ['event' => 'post.error', 'data' => ['postId' => 'bad1', 'socialAccounts' => [[
            'accountId' => $ig->provider_account_id, 'error' => 'Access token has expired for @studio',
        ]]]];

        aeOutstandHook($payload)->assertOk();
        aeOutstandHook($payload)->assertOk();

        $m = ae()->assertCapturedWithKeys('post_publish_failed', ['post_id', 'network', 'error_code', 'reason_code', 'attempts', 'provider', 'source']);
        expect($m['properties'])->toBe([
            'post_id' => $post->ulid, 'network' => 'instagram', 'error_code' => 'auth', 'reason_code' => 'auth',
            'attempts' => 1, 'provider' => 'outstand', 'source' => 'webhook',
        ])
            ->and(json_encode(ae()->sent))->not->toContain('@studio');
        ae()->assertNotCaptured('post_published');
    });

    // Prod 10 Nov 02:05:12Z: 9 post_published + 3 post_publish_failed in one
    // burst when the Post list's reconcile settled old Post for Me posts.
    describe('stale results', function () {
        beforeEach(function () {
            config(['services.social.provider' => 'outstand']);
        });

        function aeOverdue(User $user, string $provider, string $postId): array
        {
            $account = SocialAccount::factory()->for($user)->create(['platform' => 'threads', 'provider' => $provider]);
            $post = SocialPost::factory()->for($user)->create([
                'provider' => $provider, 'provider_post_id' => $postId, 'status' => 'scheduled', 'scheduled_at' => now()->subHour(),
            ]);
            $post->targets()->create(['social_account_id' => $account->id]);

            return [$post, $account];
        }

        function aeOutstandResult(string $postId, SocialAccount $account): void
        {
            Http::fake(['api.outstand.test/v1/posts/*' => Http::response(['post' => ['id' => $postId, 'socialAccounts' => [
                ['id' => $account->provider_account_id, 'status' => 'published', 'platformPostUrl' => 'https://threads.net/x'],
            ]]])]);
        }

        it('emits once on the first transition, from reconcile', function () {
            $user = aePaidUser();
            [$post, $account] = aeOverdue($user, 'outstand', 'o1');
            aeOutstandResult('o1', $account);

            $this->actingAs($user)->getJson('/api/v1/social-posts')->assertOk();

            expect($post->targets()->first()->status->value)->toBe('published');
            ae()->assertCapturedOnce('post_published', fn ($p) => $p['source'] === 'reconcile' && $p['network'] === 'threads');
        });

        it('emits nothing when reconcile finds the target already published', function () {
            $user = aePaidUser();
            [$post, $account] = aeOverdue($user, 'outstand', 'o2');
            $post->targets()->update(['status' => 'published']);
            aeOutstandResult('o2', $account);

            $this->actingAs($user)->getJson('/api/v1/social-posts')->assertOk();

            ae()->assertNotCaptured('post_published');
        });

        it('emits nothing for a replay, even when the row was reset to pending', function () {
            $user = aePaidUser();
            [$post, $account] = aeOverdue($user, 'outstand', 'o3');
            $result = ['post_id' => 'o3', 'social_account_id' => $account->provider_account_id, 'success' => true];

            SocialPostRelay::applyResult($post, $account, $result, 'webhook');
            $post->targets()->update(['status' => 'pending']);
            SocialPostRelay::applyResult($post->fresh(), $account, $result, 'reconcile');

            ae()->assertCapturedOnce('post_published', fn ($p) => $p['source'] === 'webhook');
        });

        it('settles a legacy relay\'s post without an event', function () {
            $user = aePaidUser();
            [$post, $account] = aeOverdue($user, 'postforme', 'pfm_old');
            Http::fake(['api.postforme.test/*' => Http::response(['data' => [
                ['id' => 'r1', 'post_id' => 'pfm_old', 'social_account_id' => $account->provider_account_id, 'success' => true],
            ]])]);

            $this->actingAs($user)->getJson('/api/v1/social-posts')->assertOk();

            expect($post->targets()->first()->status->value)->toBe('published');
            ae()->assertNotCaptured('post_published');
            ae()->assertNotCaptured('post_publish_failed');
        });

        it('emits nothing for a result stamped more than a day before the send', function () {
            $user = aePaidUser();
            [$post, $account] = aeOverdue($user, 'outstand', 'o4');

            SocialPostRelay::applyResult($post, $account, [
                'post_id' => 'o4', 'social_account_id' => $account->provider_account_id, 'success' => false,
                'error' => 'old', 'created_at' => now()->subDays(3)->toIso8601String(),
            ], 'webhook');

            expect($post->targets()->first()->status->value)->toBe('failed');
            ae()->assertNotCaptured('post_publish_failed');
        });
    });

    it('maps platform errors to short codes', function (?string $text, string $code) {
        expect(ProductEvents::errorCode($text))->toBe($code);
    })->with([
        [null, 'unknown'],
        ['Rate limit exceeded', 'rate_limited'],
        ['Video aspect ratio not supported', 'media'],
        ['Caption is too long', 'content'],
        ['Duplicate content', 'duplicate'],
        ['Something odd', 'other'],
    ]);
});

describe('billing', function () {
    beforeEach(function () {
        Http::fake(['test-api.creem.io/*' => Http::response([
            'id' => 'ch_test', 'checkout_url' => 'https://checkout.creem.io/test',
        ])]);
    });

    it('fires checkout_started on a subscription checkout and on a plan change', function () {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'pro', 'period' => 'monthly'])->assertCreated();

        $m = ae()->assertCapturedWithKeys('checkout_started', ['kind', 'plan', 'period', 'amount_usd', 'trial']);
        expect($m['properties'])->toBe(['kind' => 'subscription', 'plan' => 'pro', 'period' => 'monthly', 'amount_usd' => 9.99, 'trial' => true]);

        $sub = Subscription::factory()->create(['creem_subscription_id' => 'sub_test']);
        $this->actingAs($sub->user)->postJson('/api/v1/me/subscription/checkout', ['plan' => 'max', 'period' => 'yearly'])->assertStatus(202);
        ae()->assertCapturedOnce('checkout_started', fn ($p, $id) => $id === $sub->user->ulid && $p['plan'] === 'max' && $p['amount_usd'] === 149.9);
    });

    it('fires checkout_started on a Credits top-up', function () {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/credits/top-up', ['dollars' => 20])->assertCreated();

        $m = ae()->assertCapturedWithKeys('checkout_started', ['kind', 'plan', 'period', 'amount_usd', 'trial']);
        expect($m['properties'])->toBe(['kind' => 'credits', 'plan' => null, 'period' => null, 'amount_usd' => 20.0, 'trial' => false]);
    });

    it('fires subscription_started and $set plan once on the first paid event', function () {
        $sub = Subscription::factory()->create(['status' => 'expired', 'current_period_start' => null, 'current_period_end' => null]);
        Invoice::factory()->for($sub)->create();
        $paid = fn (string $tx, string $start, string $end) => [
            'id' => 'evt_'.$tx, 'eventType' => 'subscription.paid', 'object' => [
                'id' => 'sub_test', 'product' => 'prod_pro_monthly', 'customer' => 'cust_test',
                'metadata' => ['subscription' => $sub->ulid],
                'last_transaction_id' => $tx,
                'last_transaction' => ['id' => $tx, 'status' => 'paid', 'amount' => 999, 'currency' => 'USD'],
                'current_period_start_date' => $start, 'current_period_end_date' => $end,
            ],
        ];

        aeCreem($paid('tran_1', '2026-10-31T00:00:00Z', '2026-11-30T00:00:00Z'))->assertOk();
        $replay = $paid('tran_1', '2026-10-31T00:00:00Z', '2026-11-30T00:00:00Z');
        $replay['id'] = 'evt_other_delivery';
        aeCreem($replay)->assertOk();
        aeCreem($paid('tran_2', '2026-11-30T00:00:00Z', '2026-12-30T00:00:00Z'))->assertOk(); // renewal

        $m = ae()->assertCapturedWithKeys('subscription_started', ['plan', 'period', 'amount_usd', 'provider']);
        expect($m['distinct_id'])->toBe($sub->user->ulid)
            ->and($m['properties'])->toBe(['plan' => 'pro', 'period' => 'monthly', 'amount_usd' => 9.99, 'provider' => 'creem'])
            ->and(ae()->setFor($sub->user->ulid))->toBe([['plan' => 'pro']]);
    });

    it('fires subscription_canceled once across the endpoint and the webhook, and $sets plan on expiry', function () {
        $sub = Subscription::factory()->create(['creem_subscription_id' => 'sub_c', 'current_period_start' => now()->subDays(40), 'current_period_end' => now()->subDay()]);
        Http::fake(['test-api.creem.io/*' => Http::response(['id' => 'sub_c', 'status' => 'scheduled_cancel'])]);

        $this->actingAs($sub->user)->postJson('/api/v1/me/subscription/cancel')->assertOk();
        aeCreem(['id' => 'evt_cancel', 'eventType' => 'subscription.canceled', 'object' => ['id' => 'sub_c']])->assertOk();

        $m = ae()->assertCapturedWithKeys('subscription_canceled', ['plan']);
        expect($m['properties'])->toBe(['plan' => 'pro'])
            ->and(ae()->setFor($sub->user->ulid))->toBe([['plan' => 'free']]);
    });

    it('fires credits_purchased once, however often Creem repeats it', function () {
        $user = User::factory()->create();
        $purchase = CreditPurchase::create([
            'user_id' => $user->id, 'units' => 10, 'credits' => 100000, 'amount' => 1000,
            'currency' => 'USD', 'status' => 'pending', 'creem_checkout_id' => 'ch_topup',
        ]);
        $event = ['id' => 'evt_topup', 'eventType' => 'checkout.completed', 'object' => [
            'id' => 'ch_topup', 'request_id' => $purchase->ulid, 'product' => 'prod_credits',
            'order' => ['id' => 'ord_topup', 'status' => 'paid', 'amount' => 1000, 'currency' => 'USD'],
        ]];

        aeCreem($event)->assertOk();
        aeCreem($event)->assertOk();
        $event['id'] = 'evt_topup_other';
        aeCreem($event)->assertOk();

        $m = ae()->assertCapturedWithKeys('credits_purchased', ['amount_usd', 'credits']);
        expect($m['distinct_id'])->toBe($user->ulid)
            ->and($m['properties'])->toBe(['amount_usd' => 10.0, 'credits' => 100000]);
    });
});

describe('engage_reply_sent', function () {
    beforeEach(function () {
        config([
            'services.social.provider' => 'outstand',
            'services.outstand.private_reply_shape' => 'auto',
            'services.inbox.enabled' => true,
            'services.inbox.credits_per_reply' => 0,
        ]);
        $this->account = SocialAccount::factory()->for(User::factory()->create())->create([
            'platform' => 'instagram', 'provider' => 'outstand', 'username' => 'studio',
            'messages_scope_at' => now(), 'inbox_synced_at' => now(),
        ]);
        $this->comment = SocialComment::query()->create([
            'user_id' => $this->account->user_id, 'social_account_id' => $this->account->id, 'provider' => 'outstand',
            'provider_post_id' => 'post_enc_1', 'platform_post_id' => '1789', 'comment_id' => 'c_1',
            'author_id' => 'ig_fan', 'author_username' => 'fan', 'text' => 'Love this!', 'network' => 'instagram',
            'commented_at' => now()->subHour(),
        ]);
    });

    it('fires for a DM reply', function () {
        $thread = SocialConversation::query()->create([
            'user_id' => $this->account->user_id, 'social_account_id' => $this->account->id, 'provider' => 'outstand',
            'provider_conversation_id' => 'conv_1', 'network' => 'instagram', 'contact_id' => 'ig_contact',
            'contact_username' => 'ana', 'last_message_at' => now()->subHour(), 'last_inbound_at' => now()->subHour(),
            'unread_count' => 1, 'status' => 'open', 'messages_synced_at' => now(),
        ]);
        Http::fake(['api.outstand.test/v1/conversations/conv_1/messages' => Http::response(['data' => ['id' => 'out_9', 'status' => 'pending']], 202)]);

        $this->actingAs($this->account->user)->postJson("/api/v1/inbox/conversations/{$thread->ulid}/messages", ['content' => 'Private words'])->assertStatus(202);

        $m = ae()->assertCapturedWithKeys('engage_reply_sent', ['kind', 'network']);
        expect($m['properties'])->toBe(['kind' => 'dm', 'network' => 'instagram'])
            ->and($m['distinct_id'])->toBe($this->account->user->ulid)
            ->and(json_encode(ae()->sent))->not->toContain('Private words');
    });

    it('fires for a public comment reply', function () {
        Http::fake(['api.outstand.test/v1/posts/post_enc_1/replies' => Http::response(['data' => ['id' => 'reply_77']], 201)]);

        $this->actingAs($this->account->user)->postJson("/api/v1/inbox/comments/{$this->comment->ulid}/reply", ['content' => 'Thanks'])->assertOk();

        ae()->assertCapturedOnce('engage_reply_sent', fn ($p) => $p === ['kind' => 'comment', 'network' => 'instagram']);
    });

    it('fires for a private reply, and not for the refused second one', function () {
        Http::fake(['api.outstand.test/v1/conversations' => Http::response(['data' => ['id' => 'conv_new', 'messageId' => 'pm_1']], 201)]);

        $this->actingAs($this->account->user)->postJson("/api/v1/inbox/comments/{$this->comment->ulid}/private-reply", ['content' => 'DM'])->assertOk();
        $this->postJson("/api/v1/inbox/comments/{$this->comment->ulid}/private-reply", ['content' => 'again'])->assertStatus(409);

        ae()->assertCapturedOnce('engage_reply_sent', fn ($p) => $p === ['kind' => 'private_reply', 'network' => 'instagram']);
    });

    it('does not fire when the provider refuses the reply', function () {
        Http::fake(['api.outstand.test/v1/posts/post_enc_1/replies' => Http::response(['error' => 'down'], 500)]);

        $this->actingAs($this->account->user)->postJson("/api/v1/inbox/comments/{$this->comment->ulid}/reply", ['content' => 'Thanks']);

        ae()->assertNotCaptured('engage_reply_sent');
    });
});

describe('plan, platform, aliases and internal accounts (CS_Product_3, 11 Nov)', function () {
    it('takes platform from X-Creatif-Platform, web by default, and ignores anything else', function (?string $header, string $expected) {
        $headers = $header === null ? [] : [ProductEvents::PLATFORM_HEADER => $header];

        $this->postJson('/auth/register', ['email' => 'p@studio.test', 'password' => 'long-enough-1'], $headers)->assertStatus(202);

        expect(ae()->captured('user_signed_up')[0]['properties'])->toMatchArray(['plan' => 'free', 'platform' => $expected]);
    })->with([
        'android app' => ['android_app', 'android_app'],
        'android TWA' => ['android_twa', 'android_twa'],
        'web, explicit' => ['web', 'web'],
        'no header' => [null, 'web'],
        'unknown value' => ['ios_app', 'web'],
        'case and spaces' => [' Android_App ', 'android_app'],
    ]);

    it('sends the plan the user is on, and the platform on approval links, decisions and channels', function () {
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create(['plan_id' => Plan::query()->where('key', 'max')->value('id')]);
        $post = aeHeldPost($user, 'awaiting');

        $this->actingAs($user)->postJson('/api/v1/social-approval-links',
            ['approver_email' => 'client@example.test', 'post_ids' => [$post->ulid]],
            [ProductEvents::PLATFORM_HEADER => 'android_twa'],
        )->assertCreated();

        expect(ae()->captured('approval_link_created')[0]['properties'])->toMatchArray(['plan' => 'max', 'platform' => 'android_twa']);
    });

    it('sends approval_link_sent and approval_decided next to the old names, until the alias date', function () {
        $user = User::factory()->create();
        $post = aeHeldPost($user, 'awaiting');
        $link = SocialApprovalLink::create(['user_id' => $user->id]);
        config(['services.posthog.alias_until' => now()->addDay()->toDateString()]);

        ProductEvents::approvalLinkCreated($link, 1, null);
        ProductEvents::approvalDecision($link, $post, 'approved');

        $sent = ae()->captured('approval_link_sent');
        expect($sent)->toHaveCount(1)
            ->and($sent[0]['properties'])->toBe(ae()->captured('approval_link_created')[0]['properties'])
            ->and(ae()->captured('approval_decided'))->toHaveCount(1)
            ->and(ae()->captured('approval_decided')[0]['properties'])->toBe(ae()->captured('approval_decision_made')[0]['properties']);

        config(['services.posthog.alias_until' => now()->subDay()->toDateString()]);
        ProductEvents::approvalLinkCreated($link, 1, null);
        ae()->assertCapturedTimes('approval_link_created', 2);
        ae()->assertCapturedTimes('approval_link_sent', 1);
    });

    it('keeps the aliases until 11 Dec 2026 by default', function () {
        expect(config('services.posthog.alias_until'))->toBe('2026-12-11');
    });

    it('carries the creating platform onto post_published, which fires from a webhook', function () {
        config(['services.social.provider' => 'postforme']);
        $user = aePaidUser();
        $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
        $post = SocialPost::factory()->for($user)->create(['provider_post_id' => 'post_app', 'scheduled_at' => now()->addHour(), 'client_platform' => 'android_app']);
        $post->targets()->create(['social_account_id' => $ig->id]);
        Credits::chargeSend($post);

        aeHook(['event_type' => 'social.post.result.created', 'data' => [
            'id' => 'res_app', 'post_id' => 'post_app', 'social_account_id' => $ig->provider_account_id, 'success' => true,
        ]])->assertOk();

        expect(ae()->captured('post_published')[0]['properties'])->toMatchArray(['platform' => 'android_app', 'plan' => 'pro']);
    });

    it('records where a post was made', function () {
        $user = aePaidUser();
        $account = SocialAccount::factory()->for($user)->create(['platform' => 'linkedin']);

        $id = $this->actingAs($user)->postJson('/api/v1/social-posts',
            ['caption' => 'Hi', 'account_ids' => [$account->ulid], 'draft' => true],
            [ProductEvents::PLATFORM_HEADER => 'android_app'],
        )->assertCreated()->json('data.id');

        expect(SocialPost::query()->where('ulid', $id)->value('client_platform'))->toBe('android_app');
    });

    it('sends nothing at all for internal accounts: staff, the internal lists, and hello@creatif.space', function (array $attrs, array $config) {
        config($config);
        $user = User::factory()->create($attrs);
        $post = aeHeldPost($user, 'awaiting');
        $link = SocialApprovalLink::create(['user_id' => $user->id]);
        $account = SocialAccount::factory()->for($user)->create();

        ProductEvents::signedUp($user, 'email');
        ProductEvents::approvalLinkCreated($link, 1, null);
        ProductEvents::approvalDecision($link, $post, 'approved');
        ProductEvents::channelConnected($user, $account);
        ProductEvents::postCreated($post, 'draft');
        ProductEvents::checkoutStarted($user, 'credits', null, null, 1000);
        ProductEvents::planChanged($user);

        expect(ae()->sent)->toBe([]);
    })->with([
        'hello@creatif.space (always)' => [['email' => 'hello@creatif.space'], []],
        'HELLO@creatif.space, any case' => [['email' => 'HELLO@Creatif.Space'], []],
        'ANALYTICS_INTERNAL_EMAILS' => [['email' => 'qa@gmail.com'], ['services.posthog.internal_emails' => ['qa@gmail.com']]],
        'internal domain' => [['email' => 'ren@creatif.space'], ['services.posthog.internal_email_domains' => ['creatif.space']]],
        'staff' => [['email' => 'staff@gmail.com', 'is_staff' => true], []],
    ]);

    it('skips the waitlist events for an internal address too', function () {
        ProductEvents::waitlistJoined('hello@creatif.space', 'mcp', false, false);
        ProductEvents::waitlistPriorityReached('hello@creatif.space', 3);
        ProductEvents::waitlistJoined('someone@studio.test', 'mcp', false, false);

        ae()->assertCapturedTimes('waitlist_joined', 1);
        ae()->assertNotCaptured('waitlist_priority_reached');
    });
});

describe('activation: first_* milestones, once per user (CS activation funnel)', function () {
    it('sends first_channel_connected once, with plan, signup_source and minutes_since_signup', function () {
        $user = User::factory()->create(['signup_attribution' => ['signup_source' => 'newsletter'], 'created_at' => now()->subMinutes(42)]);
        $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
        ProductEvents::channelConnected($user, $ig);
        $tt = SocialAccount::factory()->for($user)->create(['platform' => 'tiktok']);
        ProductEvents::channelConnected($user, $tt);
        ProductEvents::channelConnected($user, $ig); // a reconnect

        $m = ae()->assertCapturedWithKeys('first_channel_connected', ['plan', 'signup_source', 'minutes_since_signup', 'network']);
        expect($m['distinct_id'])->toBe($user->ulid)
            ->and($m['properties'])->toMatchArray([
                'plan' => 'free', 'signup_source' => 'newsletter', 'minutes_since_signup' => 42, 'network' => 'instagram',
            ]);
        ae()->assertCapturedTimes('channel_connected', 3);
    });

    it('sends first_draft_created for the first post only, through the API', function () {
        $user = aePaidUser();
        $account = SocialAccount::factory()->for($user)->create(['platform' => 'linkedin']);

        foreach (['One', 'Two'] as $caption) {
            $this->actingAs($user)->postJson('/api/v1/social-posts', ['caption' => $caption, 'account_ids' => [$account->ulid], 'draft' => true])->assertCreated();
        }

        $m = ae()->assertCapturedWithKeys('first_draft_created', ['plan', 'signup_source', 'minutes_since_signup', 'post_id', 'status']);
        expect($m['properties'])->toMatchArray(['plan' => 'pro', 'status' => 'draft', 'signup_source' => 'unknown'])
            ->and($m['properties']['minutes_since_signup'])->toBeInt()
            ->and(json_encode($m))->not->toContain('One');
        ae()->assertCapturedTimes('post_created', 2);
    });

    it('sends first_approval_sent for the first link only', function () {
        $user = aePaidUser();
        $one = SocialApprovalLink::create(['user_id' => $user->id]);
        ProductEvents::approvalLinkCreated($one, 2, 72);
        $two = SocialApprovalLink::create(['user_id' => $user->id]);
        ProductEvents::approvalLinkCreated($two, 1, null);

        $m = ae()->assertCapturedWithKeys('first_approval_sent', ['plan', 'signup_source', 'minutes_since_signup', 'link_id', 'posts_count']);
        expect($m['properties'])->toMatchArray(['link_id' => $one->ulid, 'posts_count' => 2]);
    });

    it('sends first_post_published once across destinations and redelivered webhooks', function () {
        config(['services.social.provider' => 'postforme']);
        $user = aePaidUser();
        $ig = SocialAccount::factory()->for($user)->create(['platform' => 'instagram']);
        $tt = SocialAccount::factory()->for($user)->create(['platform' => 'tiktok']);
        $post = SocialPost::factory()->for($user)->create(['provider_post_id' => 'post_first', 'scheduled_at' => now()->addHour()]);
        $post->targets()->create(['social_account_id' => $ig->id]);
        $post->targets()->create(['social_account_id' => $tt->id]);
        Credits::chargeSend($post);

        $result = fn (SocialAccount $a) => ['event_type' => 'social.post.result.created', 'data' => [
            'id' => 'res_first_'.$a->id, 'post_id' => 'post_first', 'social_account_id' => $a->provider_account_id, 'success' => true,
        ]];
        aeHook($result($ig))->assertOk();
        aeHook($result($ig))->assertOk();
        aeHook($result($tt))->assertOk();

        $m = ae()->assertCapturedWithKeys('first_post_published', ['plan', 'signup_source', 'minutes_since_signup', 'post_id', 'network']);
        expect($m['distinct_id'])->toBe($user->ulid)
            ->and($m['properties'])->toMatchArray(['post_id' => $post->ulid, 'network' => 'instagram', 'plan' => 'pro']);
    });

    it('never sends a milestone twice, even when the caller says first again', function () {
        $user = User::factory()->create();

        ProductEvents::activated($user, 'first_post_published', ['post_id' => 'a']);
        ProductEvents::activated($user, 'first_post_published', ['post_id' => 'b']);
        ProductEvents::activated($user, 'first_approval_sent');

        ae()->assertCapturedOnce('first_post_published');
        ae()->assertCapturedOnce('first_approval_sent');
        expect(ae()->captured('first_post_published')[0]['properties']['post_id'])->toBe('a');
    });

    it('ignores names that are not milestones', function () {
        ProductEvents::activated(User::factory()->create(), 'user_signed_up');

        ae()->assertNotCaptured('user_signed_up');
    });

    it('sends no milestone for internal accounts', function (array $attrs) {
        $user = User::factory()->create($attrs);
        $account = SocialAccount::factory()->for($user)->create();
        $link = SocialApprovalLink::create(['user_id' => $user->id]);

        ProductEvents::channelConnected($user, $account);
        ProductEvents::approvalLinkCreated($link, 1, null);
        foreach (ProductEvents::ACTIVATION as $event) {
            ProductEvents::activated($user, $event);
        }

        foreach (ProductEvents::ACTIVATION as $event) {
            ae()->assertNotCaptured($event);
        }
    })->with([
        'staff' => [['email' => 'ops@example.com', 'is_staff' => true]],
        'hello@creatif.space' => [['email' => 'hello@creatif.space']],
    ]);

    it('still sends when the cache is down (the is_first_* check already held)', function () {
        $user = User::factory()->create();
        Cache::shouldReceive('add')->andThrow(new RuntimeException('cache down'));

        ProductEvents::activated($user, 'first_channel_connected');

        ae()->assertCapturedOnce('first_channel_connected');
    });
});

describe('first-touch attribution on the user (users.signup_attribution)', function () {
    // toEqual, not toBe: MySQL's JSON column returns object keys in its own
    // order (length, then name), sqlite in the written one. The keys and
    // values are the contract, not their order.
    it('keeps utm_*, referrer host, landing path and signup_source from an email signup', function () {
        $this->postJson('/auth/register', [
            'email' => 'touch@example.com', 'password' => 'long-enough-1',
            'utm_source' => 'instagram', 'utm_medium' => 'bio', 'utm_campaign' => 'nov', 'utm_term' => 'not-kept',
            'referrer_host' => 'L.Instagram.com', 'landing_path' => '/pricing',
        ])->assertStatus(202);

        expect(User::query()->sole()->signup_attribution)->toEqual([
            'utm_source' => 'instagram', 'utm_medium' => 'bio', 'utm_campaign' => 'nov',
            'referrer_host' => 'l.instagram.com', 'landing_path' => '/pricing', 'signup_source' => 'instagram',
        ]);
        // The event's keys are unchanged: referrer and path stay on the row.
        ae()->assertCapturedWithKeys('user_signed_up', ['is_staff', 'signup_source', 'method', 'plan', 'platform', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term']);
    });

    it('carries referrer host and landing path through the Google redirect', function () {
        $this->get('/auth/google/redirect?ref=RANI10&referrer_host=www.google.com&landing_path=/signup')
            ->assertSessionHas('signup_touch', ['referrer_host' => 'www.google.com', 'landing_path' => '/signup']);

        aeGoogleCallback();
        $this->get('/auth/google/callback?code=abc')->assertRedirect();

        expect(User::query()->sole()->signup_attribution)->toEqual([
            'referrer_host' => 'www.google.com', 'landing_path' => '/signup', 'signup_source' => 'referral',
        ]);
    });

    it('drops a referrer or path that is not a bare host or path, without blocking the signup', function (string $host, string $path) {
        $this->postJson('/auth/register', [
            'email' => 'bad@example.com', 'password' => 'long-enough-1', 'referrer_host' => $host, 'landing_path' => $path,
        ])->assertStatus(202);

        expect(User::query()->sole()->signup_attribution)->toEqual(['signup_source' => 'direct']);
    })->with([
        'url, query' => ['https://evil.test/x', '/p?email=a@b.c'],
        'credentials, fragment' => ['user:pw@evil.test', '/p#token'],
        'port, scheme-relative' => ['evil.test:8080', '//evil.test'],
        'too long' => [str_repeat('a', 300).'.test', '/'.str_repeat('a', 300)],
    ]);

    it('is written once: a later login or call changes nothing', function () {
        $this->postJson('/auth/register', ['email' => 'rani@example.com', 'password' => 'long-enough-1', 'utm_source' => 'first'])->assertStatus(202);
        $this->get('/auth/google/redirect?utm_source=second&referrer_host=second.test');
        aeGoogleCallback();
        $this->get('/auth/google/callback?code=abc')->assertRedirect();

        $user = User::query()->sole();
        ProductEvents::signedUp($user, 'email', ['utm_source' => 'third']);

        expect($user->fresh()->signup_attribution)->toEqual(['utm_source' => 'first', 'signup_source' => 'first']);
    });

    it('is never serialized: hidden on the model and absent from /me', function () {
        $user = User::factory()->create(['signup_attribution' => ['utm_source' => 'secret-campaign', 'signup_source' => 'secret-campaign']]);

        expect($user->toArray())->not->toHaveKey('signup_attribution');
        // fresh(): strict mode wants every column the /me resource reads.
        $this->actingAs($user->fresh())->getJson('/api/v1/me')->assertOk()->assertDontSee('secret-campaign');
    });

    it('feeds signup_source into the milestones', function () {
        $this->postJson('/auth/register', ['email' => 'm@example.com', 'password' => 'long-enough-1', 'ref' => 'RANI10'])->assertStatus(202);
        $user = User::query()->sole();

        ProductEvents::channelConnected($user, SocialAccount::factory()->for($user)->create());

        expect(ae()->captured('first_channel_connected')[0]['properties']['signup_source'])->toBe('referral');
    });
});
