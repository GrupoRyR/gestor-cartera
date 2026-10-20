<?php

namespace App\Modules\Comisiones\Providers;

use App\Modules\Comisiones\Application\Ports\SettlementInputs;
use App\Modules\Comisiones\Application\Ports\Settlements;
use App\Modules\Comisiones\Infrastructure\Console\AssignModuleRoleCommand;
use App\Modules\Comisiones\Infrastructure\Ingest\ImportCommissionBatch;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlementInputs;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * Comisiones: sales commission settlement, SGX only (rules in
 * docs/especificaciones/liquidacion-comisiones.md)
 * (docs/prompt-gestor-cartera.md, Part B, "Módulo 7 — Liquidación de
 * comisiones comerciales").
 *
 * Web routes: /comisiones/*, named comisiones.*.
 * API routes: /api/comisiones/*, named comisiones.api.*.
 * Owner: the "Comisiones: interfaz" session (phase 2) owns this provider; the
 * "Comisiones: cálculo" session (phase 1) owns Domain/ and Application/.
 */
final class ComisionesServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(SettlementInputs::class, EloquentSettlementInputs::class);
        $this->app->singleton(Settlements::class, EloquentSettlements::class);
    }

    protected function bootModule(): void
    {
        // Stages the ventas, recaudos, cruces and master batches of SGX.
        Event::listen(BatchAccepted::class, ImportCommissionBatch::class);

        $this->commands([AssignModuleRoleCommand::class]);
    }
}
