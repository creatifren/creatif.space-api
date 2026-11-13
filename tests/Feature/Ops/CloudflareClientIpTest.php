<?php

use App\Http\Middleware\TrustAppServer;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/*
 * Blackbox B5 (third pass): 40 bad POST /oauth/token from one address
 * through Cloudflare were all 401. Each request reached us from whichever
 * Cloudflare edge carried it, so every per-IP limit counted edges, not the
 * caller. A request from Cloudflare's ranges is now the visitor in
 * CF-Connecting-IP; the header from anyone else is ignored.
 */

beforeEach(function () {
    ensurePassportKeys();
    config(['app.url' => 'https://api.creatif.space', 'app.trusted_proxies' => '']);
});

/** One request through a Cloudflare edge (a different edge each time) for $visitor. */
function cfToken($test, string $visitor, int $edge)
{
    return $test->withServerVariables(['REMOTE_ADDR' => '162.158.0.'.($edge % 250 + 1)])
        ->withHeader('CF-Connecting-IP', $visitor)
        ->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => 'x']);
}

function cfIp(array $server, array $headers = []): ?string
{
    $req = Request::create('http://api.test/x', 'GET', server: $server);
    foreach ($headers as $k => $v) {
        $req->headers->set($k, $v);
    }
    (new TrustAppServer)->handle($req, fn () => new Response);

    return $req->ip();
}

describe('the client IP behind Cloudflare', function () {
    it('is CF-Connecting-IP when the request comes from a Cloudflare edge (v4 and v6)', function (string $edge) {
        expect(cfIp(['REMOTE_ADDR' => $edge], ['CF-Connecting-IP' => '203.0.113.9']))->toBe('203.0.113.9');
    })->with(['162.158.4.20', '104.16.1.1', '2606:4700::6810:84e5']);

    it('ignores the header from anywhere else, and a malformed one', function () {
        expect(cfIp(['REMOTE_ADDR' => '198.51.100.7'], ['CF-Connecting-IP' => '203.0.113.9']))->toBe('198.51.100.7')
            ->and(cfIp(['REMOTE_ADDR' => '162.158.4.20'], ['CF-Connecting-IP' => 'nope']))->toBe('162.158.4.20');
    });

    it('takes CF-Connecting-IP over a forwarded hop the visitor sent through Cloudflare', function () {
        $req = Request::create('http://api.test/x', 'GET', server: [
            'REMOTE_ADDR' => '162.158.4.20',
            'HTTP_X_FORWARDED_FOR' => '10.9.9.9',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
        ]);
        (new TrustAppServer)->handle($req, fn () => new Response);

        expect($req->ip())->toBe('203.0.113.9')
            ->and($req->headers->has('X-Forwarded-For'))->toBeFalse();
    });

    it('works behind our own trusted proxy too', function () {
        config(['app.trusted_proxies' => '10.0.0.5']);

        expect(cfIp(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '162.158.4.20'], ['CF-Connecting-IP' => '203.0.113.9']))
            ->toBe('203.0.113.9');
    });

    it('can be turned off', function () {
        config(['app.cloudflare_proxy' => false]);

        expect(cfIp(['REMOTE_ADDR' => '162.158.4.20'], ['CF-Connecting-IP' => '203.0.113.9']))->toBe('162.158.4.20');
    });
});

describe('/oauth/token through Cloudflare', function () {
    it('gives one caller 429 after the limit however many edges carry it; another caller is not affected', function () {
        foreach (range(1, 20) as $i) {
            expect(cfToken($this, '203.0.113.9', $i)->getStatusCode())->toBeIn([400, 401]);
        }
        foreach (range(21, 40) as $i) {
            cfToken($this, '203.0.113.9', $i)->assertStatus(429)->assertHeader('Retry-After');
        }

        expect(cfToken($this, '198.51.100.20', 41)->getStatusCode())->toBeIn([400, 401]);
    });

    it('limits an AI platform\'s egress address exactly like any other single address', function () {
        $budget = function (string $ip, int $from): int {
            $n = 0;
            while ($n < 100 && cfToken($this, $ip, $from + $n)->getStatusCode() !== 429) {
                $n++;
            }

            return $n;
        };

        expect($budget('160.79.104.10', 0))->toBe(20)
            ->and($budget('203.0.113.50', 200))->toBe(20);
    });
});

it('throttles every sign-in and OAuth endpoint', function (string $method, string $uri) {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn (RoutingRoute $r) => $r->uri() === $uri && in_array($method, $r->methods(), true));

    expect($route)->not->toBeNull()
        ->and(collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle')))->toBeTrue();
})->with([
    ['POST', 'oauth/token'],
    ['POST', 'oauth/register'],
    ['GET', 'oauth/authorize'],
    ['POST', 'oauth/authorize'],
    ['DELETE', 'oauth/authorize'],
    ['POST', 'auth/register'],
    ['POST', 'auth/login'],
    ['POST', 'auth/forgot-password'],
    ['POST', 'auth/reset-password'],
    ['POST', 'auth/reviewer/login'],
    ['POST', 'auth/google/native'],
    ['GET', 'auth/google/redirect'],
    ['POST', 'auth/logout'],
]);
