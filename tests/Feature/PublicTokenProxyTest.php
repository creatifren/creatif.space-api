<?php

use App\Http\Middleware\TrustAppServer;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Analytics;
use App\Services\AnalyticsFake;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
 * The app server looks up /a/{token} and /plan/{token} for its visitors.
 * Only it (TRUSTED_PROXIES) may name the visitor in X-Forwarded-For; from
 * anyone else the header is ignored, so it cannot dodge the per-visitor
 * rate limit or inflate "approval link opened".
 */

const APP_SERVER = '10.0.0.5';

beforeEach(function () {
    config(['app.trusted_proxies' => APP_SERVER]);
    $this->analytics = Analytics::fake();
});

function proxyApprovalToken(): string
{
    $owner = User::factory()->create();
    $link = SocialApprovalLink::create(['user_id' => $owner->id, 'title' => 'Desember', 'client_name' => 'Andi']);
    $link->posts()->attach(SocialPost::factory()->for($owner)->create(['approval' => 'awaiting'])->id);

    return $link->token;
}

function proxyPlanToken(): string
{
    $owner = User::factory()->create();
    $url = test()->actingAs($owner)->postJson('/api/v1/social-plan-links', [
        'title' => 'November',
        'starts_at' => '2026-11-01T00:00:00Z',
        'ends_at' => '2026-11-30T23:59:59Z',
    ])->assertCreated()->json('data.url');
    app('auth')->forgetGuards();

    return basename((string) $url);
}

/** A GET from $remote, optionally claiming $forwarded in X-Forwarded-For. */
function proxyGet(string $uri, string $remote, ?string $forwarded = null)
{
    $t = test()->flushHeaders()->withServerVariables(['REMOTE_ADDR' => $remote]);
    if ($forwarded !== null) {
        $t = $t->withHeader('X-Forwarded-For', $forwarded);
    }

    return $t->getJson($uri);
}

function openedCount(AnalyticsFake $fake): int
{
    return count($fake->captured('approval_link_opened'));
}

describe('approval link opened, once per visitor per day', function () {
    it('counts each visitor the app server names', function () {
        $token = proxyApprovalToken();

        proxyGet("/api/v1/a/{$token}", APP_SERVER, '203.0.113.1')->assertOk();
        proxyGet("/api/v1/a/{$token}", APP_SERVER, '203.0.113.1')->assertOk();
        proxyGet("/api/v1/a/{$token}", APP_SERVER, '203.0.113.2')->assertOk();

        expect(openedCount($this->analytics))->toBe(2);
    });

    it('ignores a spoofed X-Forwarded-For from an untrusted address', function () {
        $token = proxyApprovalToken();

        foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $fake) {
            proxyGet("/api/v1/a/{$token}", '198.51.100.9', $fake)->assertOk();
        }

        expect(openedCount($this->analytics))->toBe(1);
    });

    it('takes the address the app server appended, not one the browser prepended', function () {
        $token = proxyApprovalToken();

        proxyGet("/api/v1/a/{$token}", APP_SERVER, '1.1.1.1, 203.0.113.7')->assertOk();
        proxyGet("/api/v1/a/{$token}", APP_SERVER, '8.8.8.8, 203.0.113.7')->assertOk();

        expect(openedCount($this->analytics))->toBe(1);
    });
});

describe('per-visitor rate limit (60/min)', function () {
    it('gives each visitor behind the app server their own bucket', function (string $path) {
        $uri = $path === 'a' ? '/api/v1/a/'.proxyApprovalToken() : '/api/v1/plan/'.proxyPlanToken();

        for ($i = 0; $i < 60; $i++) {
            proxyGet($uri, APP_SERVER, '203.0.113.1')->assertOk();
        }
        proxyGet($uri, APP_SERVER, '203.0.113.1')->assertStatus(429);
        proxyGet($uri, APP_SERVER, '203.0.113.2')->assertOk();
    })->with(['a', 'plan']);

    it('does not let an untrusted caller rotate X-Forwarded-For past the limit', function (string $path) {
        $uri = $path === 'a' ? '/api/v1/a/'.proxyApprovalToken() : '/api/v1/plan/'.proxyPlanToken();

        for ($i = 0; $i < 60; $i++) {
            proxyGet($uri, '198.51.100.9', "203.0.113.{$i}")->assertOk();
        }
        proxyGet($uri, '198.51.100.9', '203.0.113.200')->assertStatus(429);
    })->with(['a', 'plan']);
});

describe('TRUSTED_PROXIES', function () {
    it('refuses wildcards, /0, REMOTE_ADDR and junk, keeps IPs and CIDRs', function () {
        config(['app.trusted_proxies' => '*, **, 0.0.0.0/0, ::/0, REMOTE_ADDR, nope, 10.0.0.5, 192.168.1.0/24, ::1, 10.0.0.0/33']);

        expect(TrustAppServer::proxies())->toBe(['10.0.0.5', '192.168.1.0/24', '::1']);
    });

    it('trusts no one when set to a wildcard', function () {
        config(['app.trusted_proxies' => '*']);
        $token = proxyApprovalToken();

        proxyGet("/api/v1/a/{$token}", APP_SERVER, '203.0.113.1')->assertOk();
        proxyGet("/api/v1/a/{$token}", APP_SERVER, '203.0.113.2')->assertOk();

        expect(openedCount($this->analytics))->toBe(1);
    });

    it('trusts a CIDR range', function () {
        config(['app.trusted_proxies' => '10.0.0.0/24']);
        $token = proxyApprovalToken();

        proxyGet("/api/v1/a/{$token}", '10.0.0.77', '203.0.113.1')->assertOk();
        proxyGet("/api/v1/a/{$token}", '10.0.0.77', '203.0.113.2')->assertOk();

        expect(openedCount($this->analytics))->toBe(2);
    });

    it('never takes the scheme or host from forwarded headers', function () {
        $req = Request::create('http://api.test/x', 'GET', server: [
            'REMOTE_ADDR' => APP_SERVER,
            'HTTP_X_FORWARDED_FOR' => '203.0.113.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'evil.test',
        ]);
        (new TrustAppServer)->handle($req, fn ($r) => new Response);

        expect($req->ip())->toBe('203.0.113.1')
            ->and($req->getHost())->toBe('api.test')
            ->and($req->isSecure())->toBeFalse();
    });
});
