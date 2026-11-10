<?php

namespace App\Providers;

use App\Contracts\SocialProvider;
use App\Models\User;
use App\Services\ErrorTracking;
use App\Support\SocialProviders;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The relay for new accounts and posts; rows that exist ask
        // SocialProviders::for() for the one that holds them.
        $this->app->bind(SocialProvider::class, fn ($app) => $app->make(SocialProviders::class)->current());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        Model::shouldBeStrict(! $this->app->isProduction());

        DB::prohibitDestructiveCommands($this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Vite::useAggressivePrefetching();

        // The reset form lives on the Next.js side, not in Laravel.
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => config('app.frontend_url')
            .'/reset-password?token='.$token.'&email='.urlencode($user->email));

        // Errors inside a job name the job; a job that fails for good is
        // reported even when its exception never reaches the handler.
        Queue::before(fn (JobProcessing $event) => ErrorTracking::jobStarted($event->job->resolveName()));
        Queue::after(fn (JobProcessed $event) => ErrorTracking::jobFinished());
        Queue::looping(fn () => ErrorTracking::jobFinished());
        Queue::failing(function (JobFailed $event): void {
            ErrorTracking::report($event->exception, ['job' => $event->job->resolveName()]);
        });
    }
}
