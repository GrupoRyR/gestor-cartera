<?php

namespace App\Providers;

use App\Support\Navigation\NavRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use RuntimeException;
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
            // The singleton behind the Socialite facade (a SocialiteManager).
            $socialite = $this->app->make(SocialiteFactory::class);

            // Same creator as $event->extendSocialite(), plus a fail-closed
            // tenant guard: without a tenant the driver falls back to "common"
            // and accepts accounts from any directory. It runs only when
            // Socialite::driver('microsoft') is built, so a missing tenant
            // never breaks boot or /up, only the sign-in.
            $socialite->extend('microsoft', function () use ($event, $socialite) {
                if (blank(config('services.microsoft.tenant'))) {
                    throw new RuntimeException(
                        'MICROSOFT_TENANT_ID is required: without it Microsoft sign-in would accept accounts from any directory ("common").',
                    );
                }

                return $event->buildProvider($socialite, 'microsoft', MicrosoftProvider::class, null);
            });
        });
    }
}
