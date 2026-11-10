<?php

use App\Http\Middleware\ThrottlePerRoute;
use App\Services\ErrorTracking;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->alias([
            'throttle' => ThrottlePerRoute::class,
        ]);

        // No Blade login page here — guests belong on the Next.js frontend.
        // JSON requests still get a clean 401 via shouldRenderJsonWhen below.
        $middleware->redirectGuestsTo(
            fn () => config('app.frontend_url').'/login',
        );
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
