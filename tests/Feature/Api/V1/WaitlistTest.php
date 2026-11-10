<?php

use App\Notifications\WaitlistConfirm;
use App\Services\Analytics;
use App\Support\Waitlist;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

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

it('confirms into the Resend segment, once in PostHog, and redirects', function () {
    Http::fake([
        'api.resend.com/contacts' => Http::response(['object' => 'contact', 'id' => 'c_1'], 201),
        'api.resend.com/contacts/*/segments/*' => Http::response(['id' => 'seg_mcp'], 201),
    ]);
    $token = Waitlist::token('rani@example.com', 'mcp', ['utm_source' => 'x']);

    $this->get('/api/v1/waitlist/confirm?token='.urlencode($token))
        ->assertRedirect('https://creatif.space/mcp?waitlist=confirmed');
    // A second click: Resend says "already", still confirmed, no second event.
    Http::fake([
        'api.resend.com/contacts' => Http::response(['name' => 'validation_error', 'message' => 'Contact already exists'], 409),
        'api.resend.com/contacts/*/segments/*' => Http::response(['message' => 'Contact already in segment'], 422),
    ]);
    $this->get('/api/v1/waitlist/confirm?token='.urlencode($token))
        ->assertRedirect('https://creatif.space/mcp?waitlist=confirmed');

    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.resend.com/contacts'
        && $r['email'] === 'rani@example.com' && $r->hasHeader('Authorization', 'Bearer re_test'));
    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.resend.com/contacts/rani%40example.com/segments/seg_mcp');

    $m = $this->analytics->assertCapturedWithKeys('waitlist_joined', ['list', 'utm_source']);
    expect($m['distinct_id'])->toBe(hash('sha256', 'rani@example.com'))
        ->and($m['properties'])->toBe(['list' => 'mcp', 'utm_source' => 'x'])
        ->and(json_encode($this->analytics->sent))->not->toContain('rani@');
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
    Http::fake();
    config(['services.resend.waitlist_segments.mcp' => '']);

    $this->get('/api/v1/waitlist/confirm?token='.urlencode(Waitlist::token('rani@example.com', 'mcp')))
        ->assertRedirect('https://creatif.space/mcp?waitlist=confirmed');

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => $m === 'waitlist.segment_missing' && $c['list'] === 'mcp')->once();
    $this->analytics->assertCapturedOnce('waitlist_joined');
});

it('sends a Resend failure to waitlist=error, without an event', function () {
    Http::fake(['api.resend.com/*' => Http::response(['message' => 'boom'], 500)]);

    $this->get('/api/v1/waitlist/confirm?token='.urlencode(Waitlist::token('rani@example.com', 'mcp')))
        ->assertRedirect('https://creatif.space/mcp?waitlist=error');

    $this->analytics->assertNotCaptured('waitlist_joined');
});
