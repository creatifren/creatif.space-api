<?php

use App\Http\Middleware\CapTokenSends;
use App\Services\Analytics;
use App\Services\ErrorTracking;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/*
 * Chain item 2: every 5xx reaches the existing error channel (PostHog error
 * tracking + the error log) — including a deliberate abort(5xx), which
 * Laravel never reported — and the throttles on auth, approval links,
 * presign and MCP routes are pinned to the numbers in the PR body.
 */

describe('5xx answers reach error tracking', function () {
    beforeEach(function () {
        ErrorTracking::flushState();
        Route::middleware('api')->get('api/_test/abort/{status}', fn (int $status) => abort($status, 'Status '.$status));
    });

    it('reports an abort(5xx) with its status, and logs it as an error', function (int $status) {
        $fake = Analytics::fake();
        $levels = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$levels) {
            $levels[] = $e->level;
        });

        $this->getJson("/api/_test/abort/{$status}")->assertStatus($status);

        $sent = $fake->captured('$exception');
        expect($sent)->toHaveCount(1)
            ->and($sent[0]['properties']['status'])->toBe($status)
            ->and($sent[0]['properties']['route'])->toBe('api/_test/abort/{status}')
            ->and($sent[0]['properties'])->toHaveKey('request_id')
            ->and($levels)->toBe(['error']);
    })->with([500, 502, 503]);

    it('keeps every 4xx out of error tracking and the log', function (int $status) {
        $fake = Analytics::fake();
        $levels = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$levels) {
            $levels[] = $e->level;
        });

        $this->getJson("/api/_test/abort/{$status}")->assertStatus($status);

        expect($fake->captured('$exception'))->toBe([])->and($levels)->toBe([]);
    })->with([400, 403, 404, 409, 422, 429]);
});

describe('throttles (numbers as in the PR body)', function () {
    $limits = [
        // Auth
        'auth.login' => '5,1',
        'auth.register' => '5,1',
        'password.email' => '5,1',
        'password.update' => '5,1',
        'verification.verify' => '10,1',
        'auth.google.native' => '10,1',
        'auth.google.redirect' => '20,1',
        'auth.google.callback' => '20,1',
        'auth.approver-link' => '20,1',
        // Approval links — public (token)
        'api.v1.approval-links.show' => '60,1',
        'api.v1.approval-links.decide' => '30,1',
        'api.v1.approval-links.retract' => '30,1',
        'api.v1.approval-links.comment' => '30,1',
        'api.v1.approval-links.approve-all' => '10,1',
        'api.v1.approval-links.email-link' => '5,1',
        // Approval links — owner
        'api.v1.social-approval-links.store' => '30,1',
        'api.v1.social-approval-links.update' => '30,1',
        'api.v1.social-approval-links.approve' => '10,1',
        'api.v1.social-posts.approve' => '30,1',
        // Presign / uploads
        'api.v1.files.presign' => '30,1',
        'api.v1.files.complete' => '60,1',
        'api.v1.files.versions.presign' => '30,1',
        'api.v1.files.versions.complete' => '60,1',
        // MCP OAuth (per-token traffic: EnforceTokenScope, mcp.rate_limit_per_minute)
        'oauth.register' => '10,1',
        'passport.token' => '60,1',
        'passport.authorizations.authorize' => '60,1',
        'oauth.revoke' => '60,1',
        'api.v1.oauth.introspect' => '600,1',
    ];

    it('throttles each route at the agreed limit', function () use ($limits) {
        $actual = [];
        foreach (array_keys($limits) as $name) {
            $route = Route::getRoutes()->getByName($name);
            expect($route)->not->toBeNull("route {$name}");
            $throttle = collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
            $actual[$name] = $throttle === null ? null : substr($throttle, strlen('throttle:'));
        }

        expect($actual)->toBe($limits);
    });

    it('limits every MCP token to mcp.rate_limit_per_minute (120) and connected-app sends to 20 a day', function () {
        expect(config('mcp.rate_limit_per_minute'))->toBe(120)
            ->and(CapTokenSends::DAILY_CAP)->toBe(20);
    });
});
