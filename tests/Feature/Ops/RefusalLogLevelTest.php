<?php

use App\Exceptions\AccountReconnectRequired;
use App\Exceptions\MediaRefused;
use App\Exceptions\PlanFeatureRequired;
use App\Exceptions\PostNotRetryable;
use App\Exceptions\PublishPreflightFailed;
use App\Models\User;
use App\Services\Analytics;
use App\Services\ErrorTracking;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
 * QA finding: plan / format / preflight refusals (402 / 422) are answers
 * to the person, not failures — logged at info or warning, never ERROR,
 * never sent to error tracking. A real failure is still ERROR.
 */

beforeEach(function () {
    ErrorTracking::flushState();

    Route::middleware('api')->group(function () {
        Route::get('api/_test/refuse/preflight', fn () => throw new PublishPreflightFailed([
            ['account_id' => 'acc_1', 'platform' => 'tiktok', 'code' => 'needs_video', 'reason' => 'TikTok only takes video.'],
        ]));
        Route::get('api/_test/refuse/format', fn () => throw MediaRefused::unsupportedFormat());
        Route::get('api/_test/refuse/plan', fn () => throw PlanFeatureRequired::feature(request()->user(), 'insight'));
        Route::get('api/_test/refuse/retry', fn () => throw PostNotRetryable::notFailed());
        Route::get('api/_test/boom', fn () => throw new RuntimeException('real failure'));
    });
});

it('logs the plan, format and preflight refusals below ERROR and keeps them out of error tracking', function (string $kind, int $status, string $level) {
    Log::spy();
    $fake = Analytics::fake();

    $this->actingAs(User::factory()->create())->getJson("/api/_test/refuse/{$kind}")->assertStatus($status);

    Log::shouldHaveReceived($level);
    Log::shouldNotHaveReceived('error');
    expect($fake->captured('$exception'))->toBe([]);
})->with([
    'publish preflight (422)' => ['preflight', 422, 'info'],
    'unsupported_format (422)' => ['format', 422, 'warning'],
    'plan feature (402)' => ['plan', 402, 'info'],
    'nothing to retry (409)' => ['retry', 409, 'info'],
]);

it('maps every refusal class below ERROR', function () {
    foreach (ErrorTracking::REFUSALS as $class => $level) {
        expect($level)->toBeIn(['info', 'warning'], $class);

        Log::spy();
        $e = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        app(ExceptionHandler::class)->report($e);

        Log::shouldHaveReceived($level);
        Log::shouldNotHaveReceived('error');
        expect(ErrorTracking::refusal($e))->toBeTrue();
    }

    expect(ErrorTracking::REFUSALS)->toHaveKeys([AccountReconnectRequired::class, PublishPreflightFailed::class, MediaRefused::class, PlanFeatureRequired::class]);
});

it('still logs a real failure as ERROR', function () {
    Log::spy();
    Analytics::fake();

    $this->getJson('/api/_test/boom')->assertStatus(500);

    Log::shouldHaveReceived('error');
});
