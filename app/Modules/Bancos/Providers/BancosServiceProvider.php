<?php

namespace App\Modules\Bancos\Providers;

use App\Modules\Bancos\Application\Access\VisibleCompanies;
use App\Modules\Bancos\Application\Movements\MovementSearch;
use App\Modules\Bancos\Console\ImportFolderCommand;
use App\Modules\Bancos\Domain\Statement\Cells;
use App\Modules\Bancos\Infrastructure\EloquentRecaudoProvider;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\RecaudoProvider;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Bancos: bank movement importers, daily and weekly collections and the
 * read-only collections API for Treasury (docs/prompt-gestor-cartera.md, Part
 * B, "Módulo 5 — Movimientos de bancos y recaudo").
 *
 * Web routes: /bancos/*, named bancos.*.
 * API routes: /api/*, named bancos.api.*.
 * Owner: the "Bancos" session (phase 1); the "Conciliación" session (phase
 * 2) owns Conciliacion/.
 */
final class BancosServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(RecaudoProvider::class, EloquentRecaudoProvider::class);
    }

    protected function bootModule(): void
    {
        // Treasury sends a few requests a day; this only caps a runaway loop or
        // someone guessing tokens.
        RateLimiter::for('bancos-api', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->ip()));

        $this->commands([ImportFolderCommand::class]);
    }

    /**
     * Every morning (Bogotá) the folder is read: Treasury drops the
     * statements of the day before (día vencido). Importing takes seconds, so
     * a lock left by a killed process expires after 10 minutes, not a day.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command(ImportFolderCommand::class)
            ->dailyAt((string) config('bancos.import_at'))
            ->timezone(Cells::TIMEZONE)
            ->withoutOverlapping(10);
    }

    /**
     * The menu of the module: the collections report, the movements by bank
     * (with how many credits still wait for their cash receipt) and the loads.
     * The report and the movements are on the bottom bar of the phone.
     */
    protected function navigation(NavRegistry $navigation): void
    {
        $navigation->add(
            new NavItem(
                key: 'bancos.recaudo',
                label: 'Recaudo',
                route: 'bancos.recaudo',
                icon: 'money',
                permission: 'bancos.view',
                group: 'Bancos',
                order: 10,
                activePattern: 'bancos.recaudo',
                mobile: true,
            ),
            new NavItem(
                key: 'bancos.movimientos',
                label: 'Movimientos',
                route: 'bancos.movimientos.index',
                icon: 'bank',
                permission: 'bancos.view',
                group: 'Bancos',
                order: 20,
                activePattern: 'bancos.movimientos.*',
                mobile: true,
                badge: fn (): ?int => app()->bound(CurrentCompany::class)
                    ? (app(MovementSearch::class)->pendingCount(app(VisibleCompanies::class)->inView()) ?: null)
                    : null,
            ),
            new NavItem(
                key: 'bancos.cargas',
                label: 'Cargas de extractos',
                route: 'bancos.cargas.index',
                icon: 'upload',
                permission: 'bancos.view',
                group: 'Bancos',
                order: 30,
                activePattern: 'bancos.cargas.*',
            ),
        );
    }

    /**
     * Treasury reads the daily collections from GET /api/recaudo (Part B,
     * "Módulo 5", point 9), so the module API sits directly under /api.
     */
    protected function apiPrefix(): string
    {
        return '';
    }
}
