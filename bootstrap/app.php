<?php

use App\Http\Middleware\ThrottlePerRoute;
use App\Http\Middleware\TrustAppServer;
use App\Services\ErrorTracking;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')->group(base_path('routes/oauth.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // The visitor's IP may come only from our app server (TRUSTED_PROXIES),
        // only via X-Forwarded-For. See TrustAppServer.
        $middleware->replace(TrustProxies::class, TrustAppServer::class);

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

        // To PostHog error tracking as well as laravel.log (the callback
        // returns nothing, so the default logging still runs).
        $exceptions->report(function (Throwable $e): void {
            ErrorTracking::report($e);
        });
    })->create();
