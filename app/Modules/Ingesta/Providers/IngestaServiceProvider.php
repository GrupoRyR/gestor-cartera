<?php

namespace App\Modules\Ingesta\Providers;

use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Ingesta\Console\CheckDailyLoadCommand;
use App\Modules\Ingesta\Console\PruneManualUploadsCommand;
use App\Modules\Ingesta\Infrastructure\DailyCarteraCheck;
use App\Modules\Ingesta\Infrastructure\EloquentIngestaSink;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Ingesta: signed endpoint for the ETL robot batches, load history, manual
 * Excel upload and the missing-load alert (docs/prompt-gestor-cartera.md,
 * Part B, "Módulo 1 — Ingesta").
 *
 * Web routes: /ingesta/*, named ingesta.*.
 * API routes: /api/ingesta/*, named ingesta.api.*.
 * Owner: the "Ingesta" session (phase 1).
 */
final class IngestaServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(IngestaSink::class, EloquentIngestaSink::class);
    }

    protected function bootModule(): void
    {
        // The robot sends a handful of batches a day; this only caps a
        // runaway loop or a flood of forged requests: 60 a minute from one
        // IP and 120 from all of them, so many IPs cannot keep the server
        // hashing bodies (audit S-19). The throttle checks both before
        // counting either, so a blocked IP does not use up the global quota.
        // The throttle keys each counter as the limiter name plus by(), so
        // the two by() values ("ip:…" and "global") must never collide.
        RateLimiter::for('ingesta-robot', fn (Request $request): array => [
            Limit::perMinute(60)->by('ip:'.$request->ip()),
            Limit::perMinute(120)->by('global'),
        ]);

        $this->commands([CheckDailyLoadCommand::class, PruneManualUploadsCommand::class]);
    }

    /**
     * At the deadline (Bogotá), mail the alert when the robot cartera of
     * the day is missing; at 03:00, delete the manual uploads nobody
     * confirmed or discarded. Both take seconds, so a lock left by a killed
     * process expires after 10 minutes instead of a day.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command(CheckDailyLoadCommand::class)
            ->dailyAt((string) config('ingesta.alert_deadline'))
            ->timezone(DailyCarteraCheck::TIMEZONE)
            ->withoutOverlapping(10);

        $schedule->command(PruneManualUploadsCommand::class)
            ->dailyAt('03:00')
            ->timezone(DailyCarteraCheck::TIMEZONE)
            ->withoutOverlapping(10);
    }

    /**
     * Cargas de datos sits in Administración, after Core's entries (900
     * and 910): the home page redirects to the first visible entry, so an
     * admin keeps landing on Equipo.
     */
    protected function navigation(NavRegistry $navigation): void
    {
        $navigation->add(new NavItem(
            key: 'ingesta.cargas',
            label: 'Cargas de datos',
            route: 'ingesta.index',
            icon: 'history',
            permission: 'ingesta.view',
            group: 'Administración',
            order: 920,
            activePattern: 'ingesta.*',
        ));
    }
}
