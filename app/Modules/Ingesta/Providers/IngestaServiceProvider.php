<?php

namespace App\Modules\Ingesta\Providers;

use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Ingesta\Console\CheckDailyLoadCommand;
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
        // runaway loop or a flood of forged requests.
        RateLimiter::for('ingesta-robot', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->ip()));

        $this->commands([CheckDailyLoadCommand::class]);
    }

    /**
     * At the deadline (Bogotá), mail the alert when the robot cartera of
     * the day is missing. The check takes seconds, so a lock left by a
     * killed process expires after 10 minutes instead of a day.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command(CheckDailyLoadCommand::class)
            ->dailyAt((string) config('ingesta.alert_deadline'))
            ->timezone(DailyCarteraCheck::TIMEZONE)
            ->withoutOverlapping(10);
    }

    /**
     * Cargas de datos sits in Administración, before Core's entries (900
     * and up).
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
            order: 800,
            activePattern: 'ingesta.*',
        ));
    }
}
