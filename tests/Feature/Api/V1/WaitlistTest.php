<?php

use App\Notifications\WaitlistConfirm;
use App\Notifications\WaitlistOnTheList;
use App\Notifications\WaitlistPriority;
use App\Services\Analytics;
use App\Support\Waitlist;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
 * The MCP waitlist: double opt-in, nothing stored until confirmed.
 */

beforeEach(function () {
    config([
        'app.public_url' => 'https://creatif.space',
        'services.resend.key' => 're_test',
        'services.resend.waitlist_segments.mcp' => 'seg_mcp',
    ]);
    Cache::flush();
    Notification::fake();
    $this->analytics = Analytics::fake();
});

function joinWaitlist($test, array $body, string $origin = 'https://creatif.space')
{
    return $test->withHeader('Origin', $origin)->postJson('/api/v1/waitlist', $body + ['list' => 'mcp']);
}

/** The confirm URL from the one email sent to $email. */
function confirmUrlFor(string $email): string
{
    $url = null;
    Notification::assertSentOnDemand(WaitlistConfirm::class, function (WaitlistConfirm $n, array $channels, AnonymousNotifiable $to) use ($email, &$url) {
        $url = $n->url;

        return $to->routes['mail'] === $email;
    });

    return (string) $url;
}

it('answers 202 check_inbox and sends one confirm email with a one-line subject', function () {
    joinWaitlist($this, ['email' => ' Rani@Example.com ', 'utm_source' => 'x'])
        ->assertStatus(202)
        ->assertExactJson(['status' => 'check_inbox']);

    $url = confirmUrlFor('rani@example.com');
    expect($url)->toStartWith(route('api.v1.waitlist.confirm').'?token=')
        ->and($url)->not->toContain('rani');

    $mail = (new WaitlistConfirm($url))->toMail(new AnonymousNotifiable);
    expect($mail->subject)->toBe('Confirm your spot on the Creatif Space MCP waitlist')
        ->and($mail->actionUrl)->toBe($url);
});

it('answers the same for a bot in the honeypot, and sends nothing', function () {
    joinWaitlist($this, ['email' => 'bot@example.com', 'website' => 'http://spam'])
        ->assertOk()
        ->assertExactJson(['status' => 'check_inbox']);

    Notification::assertNothingSent();
});

it('refuses a bad email or an unknown list', function () {
    joinWaitlist($this, ['email' => 'not-an-email'])->assertUnprocessable();
    $this->withHeader('Origin', 'https://creatif.space')
        ->postJson('/api/v1/waitlist', ['email' => 'a@example.com', 'list' => 'vip'])->assertUnprocessable();

    Notification::assertNothingSent();
});

it('sends at most 3 emails a day per address, still answering 202', function () {
    foreach (range(1, 4) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"]);
        joinWaitlist($this, ['email' => 'andi@example.com'])->assertStatus(202);
    }

    Notification::assertSentOnDemandTimes(WaitlistConfirm::class, 3);
});

it('limits each IP to 5 a minute', function () {
    foreach (range(1, 5) as $i) {
        joinWaitlist($this, ['email' => "p{$i}@example.com"])->assertStatus(202);
    }
    joinWaitlist($this, ['email' => 'p6@example.com'])->assertStatus(429);
});

it('takes the form only from creatif.space', function () {
    joinWaitlist($this, ['email' => 'a@example.com'], 'https://evil.test')->assertForbidden();
    joinWaitlist($this, ['email' => 'a@example.com'], 'https://app.creatif.space')->assertForbidden();

    Notification::assertNothingSent();
});

/**
 * Resend as a tiny in-memory store: contacts by email, with properties and
 * segments. Returns the store so a test can look inside.
 */
function fakeResend(): ArrayObject
{
    $store = new ArrayObject;
    Http::fake(['api.resend.com/*' => function (HttpRequest $r) use ($store) {
        $path = parse_url($r->url(), PHP_URL_PATH);
        if ($r->method() === 'POST' && $path === '/contacts') {
            if (isset($store[$r['email']])) {
                return Http::response(['message' => 'Contact already exists'], 409);
            }
            $store[$r['email']] = ['email' => $r['email'], 'properties' => $r['properties'] ?? [], 'segments' => []];

            return Http::response(['object' => 'contact', 'id' => 'c_'.count($store)], 201);
        }
        if (preg_match('#^/contacts/([^/]+)/segments/([^/]+)$#', $path, $m) && $r->method() === 'POST') {
            $email = rawurldecode($m[1]);
            $c = $store[$email];
            $c['segments'] = array_values(array_unique([...$c['segments'], $m[2]]));
            $store[$email] = $c;

            return Http::response(['id' => $m[2]], 201);
        }
        if (preg_match('#^/contacts/([^/]+)$#', $path, $m)) {
            $email = rawurldecode($m[1]);
            if (! isset($store[$email])) {
                return Http::response(['message' => 'Contact not found'], 404);
            }
            if ($r->method() === 'PATCH') {
                $c = $store[$email];
                $c['properties'] = array_merge($c['properties'], $r['properties'] ?? []);
                $store[$email] = $c;
            }

            return Http::response(['object' => 'contact'] + $store[$email]);
        }

        return Http::response(['message' => 'unexpected '.$r->method().' '.$path], 500);
    }]);

    return $store;
}

function confirmWaitlist($test, string $email, ?string $ref = null)
{
    return $test->get('/api/v1/waitlist/confirm?token='.urlencode(Waitlist::token($email, 'mcp', [], $ref)));
}

function confirmedUrl(string $email): string
{
    return 'https://creatif.space/mcp?waitlist=confirmed&code='.Waitlist::refCode($email);
}

it('takes first-touch UTMs nested (marketing form), top-level as a fallback, into the link and PostHog', function () {
    $store = fakeResend();
    joinWaitlist($this, [
        'email' => 'u@example.com',
        'utm' => ['source' => 'x', 'medium' => 'social', 'campaign' => 'mcp-launch', 'term' => null, 'content' => 'hero'],
        'utm_source' => 'ignored-when-nested', 'utm_term' => 'fallback-term',
    ])->assertStatus(202);
    $token = urldecode(Str::after(confirmUrlFor('u@example.com'), 'token='));

    expect(Waitlist::read($token)['utm'])->toBe([
        'utm_source' => 'x', 'utm_medium' => 'social', 'utm_campaign' => 'mcp-launch',
        'utm_term' => 'fallback-term', 'utm_content' => 'hero',
    ]);

    $this->get('/api/v1/waitlist/confirm?token='.urlencode($token))->assertRedirect(confirmedUrl('u@example.com'));
    expect($this->analytics->captured('waitlist_joined')[0]['properties'])->toMatchArray([
        'utm_source' => 'x', 'utm_medium' => 'social', 'utm_campaign' => 'mcp-launch', 'utm_term' => 'fallback-term', 'utm_content' => 'hero',
    ]);

    joinWaitlist($this, ['email' => 'v@example.com', 'utm' => ['source' => str_repeat('a', 101)]])->assertUnprocessable();
    joinWaitlist($this, ['email' => 'v@example.com', 'utm' => ['medium' => ['nested']]])->assertUnprocessable();
    joinWaitlist($this, ['email' => 'v@example.com', 'utm' => 'not-an-object'])->assertUnprocessable();
});

it('carries a valid ref from the form into the confirm link', function () {
    joinWaitlist($this, ['email' => 'b@example.com', 'ref' => 'AbCd_12-'])->assertStatus(202);
    $token = Str::after(confirmUrlFor('b@example.com'), 'token=');

    expect(Waitlist::read(urldecode($token))['ref'])->toBe('AbCd_12-');
    joinWaitlist($this, ['email' => 'c@example.com', 'ref' => 'bad'])->assertUnprocessable();
});

it('confirms: contact with its properties, segment, share email, PostHog, redirect with the code', function () {
    $store = fakeResend();

    confirmWaitlist($this, 'rani@example.com')->assertRedirect(confirmedUrl('rani@example.com'));

    $c = $store['rani@example.com'];
    expect($c['segments'])->toBe(['seg_mcp'])
        ->and($c['properties'])->toMatchArray([
            'ref_code' => Waitlist::refCode('rani@example.com'), 'referred_by' => '', 'referral_count' => 0,
            'priority' => 'false', 'referral_counted' => 'false',
        ])
        ->and($c['properties']['confirmed_at'])->not->toBeEmpty()
        ->and(Waitlist::refCode('rani@example.com'))->toMatch(Waitlist::REF_PATTERN);

    Notification::assertSentOnDemand(WaitlistOnTheList::class, fn ($n, $ch, $to) => $to->routes['mail'] === 'rani@example.com'
        && $n->shareUrl === 'https://creatif.space/mcp?ref='.Waitlist::refCode('rani@example.com'));
    $mail = (new WaitlistOnTheList('https://creatif.space/mcp?ref=x'))->toMail(new AnonymousNotifiable);
    expect(implode(' ', $mail->introLines))->toContain('Want in sooner? Share your link. When 3 people confirm, you move to the priority batch.');

    $m = $this->analytics->assertCapturedWithKeys('waitlist_joined', ['list', 'referred', 'ref_code_present']);
    expect($m['distinct_id'])->toBe(hash('sha256', 'rani@example.com'))
        ->and($m['properties'])->toBe(['list' => 'mcp', 'referred' => false, 'ref_code_present' => false])
        ->and(json_encode($this->analytics->sent))->not->toContain('rani@');

    $this->getJson('/api/v1/waitlist/status?code='.Waitlist::refCode('rani@example.com'))
        ->assertOk()->assertExactJson(['referral_count' => 0, 'priority' => false, 'needed' => 3]);
});

it('treats a second confirm as done: same code, nothing reset, nothing sent again', function () {
    $store = fakeResend();
    confirmWaitlist($this, 'rani@example.com');
    $before = $store['rani@example.com'];

    confirmWaitlist($this, 'rani@example.com')->assertRedirect(confirmedUrl('rani@example.com'));

    expect($store['rani@example.com'])->toBe($before);
    Notification::assertSentOnDemandTimes(WaitlistOnTheList::class, 1);
    $this->analytics->assertCapturedOnce('waitlist_joined');
});

it('does not reset a contact Resend already has as confirmed when the cache was cleared', function () {
    $store = fakeResend();
    confirmWaitlist($this, 'rani@example.com');
    $c = $store['rani@example.com'];
    $c['properties']['referral_count'] = 2;
    $store['rani@example.com'] = $c;
    Cache::flush();

    confirmWaitlist($this, 'rani@example.com')->assertRedirect(confirmedUrl('rani@example.com'));

    expect($store['rani@example.com']['properties']['referral_count'])->toBe(2);
    $this->getJson('/api/v1/waitlist/status?code='.Waitlist::refCode('rani@example.com'))->assertJson(['referral_count' => 2]);
});

it('counts a referral once, and the third moves the referrer up with one email', function () {
    $store = fakeResend();
    confirmWaitlist($this, 'owner@example.com');
    $code = Waitlist::refCode('owner@example.com');

    foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $friend) {
        confirmWaitlist($this, $friend, $code)->assertRedirect(confirmedUrl($friend));
        confirmWaitlist($this, $friend, $code); // a re-click never counts twice
    }

    expect($store['owner@example.com']['properties'])->toMatchArray(['referral_count' => 3, 'priority' => 'true'])
        ->and($store['a@example.com']['properties'])->toMatchArray(['referred_by' => $code, 'referral_counted' => 'true']);
    $this->getJson('/api/v1/waitlist/status?code='.$code)->assertExactJson(['referral_count' => 3, 'priority' => true, 'needed' => 3]);

    Notification::assertSentOnDemandTimes(WaitlistPriority::class, 1);
    Notification::assertSentOnDemand(WaitlistPriority::class, fn ($n, $ch, $to) => $to->routes['mail'] === 'owner@example.com');
    $mail = (new WaitlistPriority)->toMail(new AnonymousNotifiable);
    expect($mail->subject)->toBe("You're in the priority batch")
        ->and($mail->introLines)->toBe(["Three people joined with your link. You'll get Creatif Space MCP in the first invite round. Thanks for spreading the word."]);

    $this->analytics->assertCapturedOnce('waitlist_priority_reached', fn ($p, $id) => $id === hash('sha256', 'owner@example.com'));
    $this->analytics->assertCapturedTimes('waitlist_joined', 3, fn ($p) => $p['referred'] === true && $p['ref_code_present'] === true);

    // A fourth is counted, but there is no second priority email.
    confirmWaitlist($this, 'd@example.com', $code);
    expect($store['owner@example.com']['properties']['referral_count'])->toBe(4);
    Notification::assertSentOnDemandTimes(WaitlistPriority::class, 1);
});

it('does not count a self-referral, a disposable address or an unknown code', function () {
    $store = fakeResend();
    confirmWaitlist($this, 'owner@example.com');
    $code = Waitlist::refCode('owner@example.com');

    confirmWaitlist($this, 'owner@example.com', $code);           // self, after the fact
    confirmWaitlist($this, 'self@example.com', Waitlist::refCode('self@example.com')); // own code
    confirmWaitlist($this, 'x@mailinator.com', $code);
    confirmWaitlist($this, 'y@sub.yopmail.com', $code);
    confirmWaitlist($this, 'z@example.com', 'Unknown1');

    expect($store['owner@example.com']['properties']['referral_count'])->toBe(0)
        ->and($store['x@mailinator.com']['properties']['referred_by'])->toBe('')
        ->and($store['z@example.com']['properties']['referred_by'])->toBe('');
    // self@, x@, y@ and z@ each joined with a ref that did not count.
    $this->analytics->assertCapturedTimes('waitlist_joined', 4, fn ($p) => $p['ref_code_present'] === true && $p['referred'] === false);
    $this->analytics->assertCapturedTimes('waitlist_joined', 0, fn ($p) => $p['referred'] === true);
    Notification::assertSentOnDemandTimes(WaitlistPriority::class, 0);
});

it('answers status without PII and 404s an unknown or malformed code', function () {
    fakeResend();
    confirmWaitlist($this, 'rani@example.com');

    $body = $this->getJson('/api/v1/waitlist/status?code='.Waitlist::refCode('rani@example.com'))->assertOk()->json();
    expect(json_encode($body))->not->toContain('rani');
    $this->getJson('/api/v1/waitlist/status?code=Unknown1')->assertNotFound();
    $this->getJson('/api/v1/waitlist/status?code=../../x')->assertNotFound();
});

it('redirects a forged or expired link to waitlist=expired', function () {
    Http::fake();
    $token = Waitlist::token('rani@example.com', 'mcp');

    $this->get('/api/v1/waitlist/confirm?token=forged')->assertRedirect('https://creatif.space/mcp?waitlist=expired');
    $this->get('/api/v1/waitlist/confirm')->assertRedirect('https://creatif.space/mcp?waitlist=expired');
    $this->travel(Waitlist::DAYS)->days();
    $this->travel(1)->minutes();
    $this->get('/api/v1/waitlist/confirm?token='.urlencode($token))->assertRedirect('https://creatif.space/mcp?waitlist=expired');

    Http::assertNothingSent();
    $this->analytics->assertNotCaptured('waitlist_joined');
});

it('still confirms when no segment is configured, with a warning', function () {
    Log::spy();
    $store = fakeResend();
    config(['services.resend.waitlist_segments.mcp' => '']);

    confirmWaitlist($this, 'rani@example.com')->assertRedirect(confirmedUrl('rani@example.com'));

    expect($store['rani@example.com']['segments'])->toBe([]);
    Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => $m === 'waitlist.segment_missing' && $c['list'] === 'mcp')->once();
    $this->analytics->assertCapturedOnce('waitlist_joined');
});

it('sends a Resend failure to waitlist=error, without an event or email', function () {
    Http::fake(['api.resend.com/*' => Http::response(['message' => 'boom'], 500)]);

    confirmWaitlist($this, 'rani@example.com')->assertRedirect('https://creatif.space/mcp?waitlist=error');

    $this->analytics->assertNotCaptured('waitlist_joined');
    Notification::assertNotSentTo(new AnonymousNotifiable, WaitlistOnTheList::class);
});
