<?php

namespace App\Modules\Comisiones\Providers;

use App\Modules\Comisiones\Application\Ports\CommissionParameters;
use App\Modules\Comisiones\Application\Ports\SettlementInputs;
use App\Modules\Comisiones\Application\Ports\Settlements;
use App\Modules\Comisiones\Infrastructure\Console\AssignModuleRoleCommand;
use App\Modules\Comisiones\Infrastructure\Ingest\ImportCommissionBatch;
use App\Modules\Comisiones\Infrastructure\Parameters\EloquentCommissionParameters;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlementInputs;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

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
        $this->app->singleton(CommissionParameters::class, EloquentCommissionParameters::class);
        $this->app->singleton(Settlements::class, EloquentSettlements::class);
    }

    protected function bootModule(): void
    {
        // Stages the ventas, recaudos, cruces and master batches of SGX.
        Event::listen(BatchAccepted::class, ImportCommissionBatch::class);

        $this->commands([AssignModuleRoleCommand::class]);

        // Every file the module hands out (audit S-10): 10 a minute per user,
        // counted together, so a script cannot pull every report in a loop and
        // one user at the limit never blocks another.
        RateLimiter::for('comisiones-exports', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(10)->by($user === null ? 'ip:'.$request->ip() : 'user:'.$user->getAuthIdentifier());
        });
    }

    /**
     * The Comisiones group of the menu, after Cobro (its entries use 200 to
     * 250). Every entry of the module is registered here; NavRegistry skips
     * the ones whose route does not exist yet, so the areas built later
     * (presupuesto, ajustes, parametros, auditoria) appear once their
     * routes/web/<area>.php names these routes.
     */
    protected function navigation(NavRegistry $navigation): void
    {
        $navigation->add(
            new NavItem(
                key: 'comisiones.tablero',
                label: 'Tablero de comisiones',
                route: 'comisiones.tablero',
                icon: 'dashboard',
                permission: 'comisiones.view',
                group: 'Comisiones',
                order: 200,
            ),
            new NavItem(
                key: 'comisiones.liquidacion',
                label: 'Liquidación del mes',
                route: 'comisiones.liquidacion',
                icon: 'document',
                permission: 'comisiones.view',
                group: 'Comisiones',
                order: 210,
                activePattern: 'comisiones.periodos.*',
            ),
            new NavItem(
                key: 'comisiones.presupuesto',
                label: 'Presupuesto',
                route: 'comisiones.presupuesto.index',
                icon: 'wallet',
                permission: 'comisiones.view',
                group: 'Comisiones',
                order: 220,
                activePattern: 'comisiones.presupuesto.*',
            ),
            new NavItem(
                key: 'comisiones.ajustes',
                label: 'Ajustes y cruces',
                route: 'comisiones.ajustes.index',
                icon: 'edit',
                permission: 'comisiones.view',
                group: 'Comisiones',
                order: 230,
                activePattern: 'comisiones.ajustes.*',
            ),
            new NavItem(
                key: 'comisiones.parametros',
                label: 'Parámetros de comisiones',
                route: 'comisiones.parametros.index',
                icon: 'sliders',
                permission: 'comisiones.parameters',
                group: 'Comisiones',
                order: 240,
                activePattern: 'comisiones.parametros.*',
            ),
            new NavItem(
                key: 'comisiones.auditoria',
                label: 'Auditoría',
                route: 'comisiones.auditoria.index',
                icon: 'history',
                permission: 'comisiones.audit',
                group: 'Comisiones',
                order: 250,
                activePattern: 'comisiones.auditoria.*',
            ),
        );
    }
}
