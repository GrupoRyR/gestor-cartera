<?php

namespace App\Providers;

use App\Support\Navigation\NavRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Shared navigation; modules fill it from their providers.
        $this->app->singleton(NavRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Dates are immutable everywhere, matching the Core contracts.
        Date::use(CarbonImmutable::class);

        // Outside production, fail fast on lazy loading (N+1), silently
        // discarded attributes and missing attributes.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Never allow migrate:fresh, db:wipe and friends against production.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Microsoft Entra ID driver for Laravel Socialite (configured in config/services.php).
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('microsoft', MicrosoftProvider::class);
        });
    }
}
