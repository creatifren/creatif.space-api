<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureRateLimitHeaders;
use App\Http\Middleware\ScopeToWorkspace;
use App\Http\Middleware\ThrottlePerRoute;
use App\Http\Middleware\TrustAppServer;
use App\Services\ErrorTracking;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            Route::middleware('api')->group(base_path('routes/oauth.php'));

            // Liveness, as cheap as a request can be: no middleware group, so
            // no session read/write, cookies or view render (Laravel's own
            // `health:` route runs the whole `web` stack — a DB session row
            // per probe with SESSION_DRIVER=database).
            Route::get('/up', fn () => response()->json(['status' => 'ok']))->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // Every 429 from the API carries X-RateLimit-Limit / -Remaining and
        // Retry-After, whichever limit answered it. Outermost in the group.
        $middleware->api(prepend: [EnsureRateLimitHeaders::class]);

        // Approval-link decisions use the session (the signed-in approver)
        // but may arrive by navigator.sendBeacon, which cannot send the
        // X-XSRF-TOKEN header. SameSite=Lax + Sanctum's own-origin check
        // keep cross-site requests unauthenticated instead. See routes/api/v1.php.
        //
        // Public, throttled, unauthenticated POSTs from the marketing site and
        // public Space pages (creatif.space): that origin is in
        // SANCTUM_STATEFUL_DOMAINS for the signed-in approver, so Sanctum
        // treats these browser POSTs as SPA requests and wanted an XSRF
        // token the static pages never fetch (419, enterprise leads lost).
        // None changes the signed-in person's account (a contact lead is only
        // linked to them); throttles, honeypot, validation and
        // MarketingSiteOrigin stay on the routes. Pinned in PublicMarketingCsrfTest.
        $middleware->validateCsrfTokens(except: [
            'api/v1/a/*/posts/*/decision',
            'api/v1/a/*/posts/*/retract',
            'api/v1/a/*/approve-all',
            'api/v1/enterprise/contact',
            'api/v1/waitlist',
            'api/v1/referrals/*/click',
            'api/v1/spaces/*/events',
            'api/v1/profiles/*/spaces/*/unlock',
        ]);

        // The visitor's IP may come only from our app server (TRUSTED_PROXIES),
        // only via X-Forwarded-For. See TrustAppServer.
        $middleware->replace(TrustProxies::class, TrustAppServer::class);

        // Every request gets an id: logs, error tracking, X-Request-Id back.
        $middleware->prepend(AssignRequestId::class);

        // Workspace scope needs the bound models: always after binding.
        $middleware->appendToPriorityList(SubstituteBindings::class, ScopeToWorkspace::class);

        $middleware->alias([
            'throttle' => ThrottlePerRoute::class,
        ]);

        // No Blade login page here — guests belong on the Next.js frontend.
        // JSON requests still get a clean 401 via shouldRenderJsonWhen below.
        // An OAuth authorize request is remembered so the login can hand the
        // person back to the consent screen (the frontend sends them to
        // /oauth/resume afterwards).
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('oauth/authorize') && $request->isMethod('GET') && $request->hasSession()) {
                $request->session()->put('oauth.intended', $request->fullUrl());

                return config('app.frontend_url').'/login?next='.urlencode('/oauth/resume');
            }

            return config('app.frontend_url').'/login';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A throttled request says how long to wait, in words a person reads,
        // not Laravel's raw "Too Many Attempts.". JSON callers only; the
        // Retry-After header is kept.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! ($request->is('api/*') || $request->is('auth/*') || $request->expectsJson())) {
                return null;
            }
            $seconds = max(1, (int) ($e->getHeaders()['Retry-After'] ?? 60));

            return response()->json([
                'message' => "Too many attempts. Try again in {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429, $e->getHeaders());
        });

        // A deliberate 5xx (abort(502) "the AI did not answer", abort(503)
        // "not set up") is a failure someone must see, but Laravel never
        // reports HttpException. Report those; every 4xx stays quiet.
        $exceptions->stopIgnoring(HttpException::class);
        $exceptions->dontReportWhen(
            fn (Throwable $e) => $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500,
        );

        // Refusals the person is told about (plan, format, preflight,
        // Credits, horizon) are not errors: info / warning, not ERROR.
        foreach (ErrorTracking::REFUSALS as $class => $level) {
            $exceptions->level($class, $level);
        }

        // To PostHog error tracking as well as laravel.log (the callback
        // returns nothing, so the default logging still runs).
        $exceptions->report(function (Throwable $e): void {
            ErrorTracking::report($e);
        });
    })->create();
