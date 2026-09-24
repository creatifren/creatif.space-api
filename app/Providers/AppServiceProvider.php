<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
        //
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
    }
}
