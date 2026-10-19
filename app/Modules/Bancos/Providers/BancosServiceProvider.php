<?php

namespace App\Modules\Bancos\Providers;

use App\Modules\Bancos\Console\ImportFolderCommand;
use App\Modules\Bancos\Domain\Statement\Cells;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

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
    protected function bootModule(): void
    {
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
     * Treasury reads the daily collections from GET /api/recaudo (Part B,
     * "Módulo 5", point 9), so the module API sits directly under /api.
     */
    protected function apiPrefix(): string
    {
        return '';
    }
}
