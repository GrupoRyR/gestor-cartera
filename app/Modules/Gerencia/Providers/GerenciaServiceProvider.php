<?php

namespace App\Modules\Gerencia\Providers;

use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;

/**
 * Gerencia: management dashboard: consolidated indicators for the group and
 * by company (docs/prompt-gestor-cartera.md, Part B, "Módulo 6 — Tablero
 * gerencial").
 *
 * Web routes: /gerencia/*, named gerencia.*.
 * API routes: /api/gerencia/*, named gerencia.api.*.
 * Owner: the "Gerencia" session (phase 2).
 */
final class GerenciaServiceProvider extends ModuleServiceProvider
{
    /**
     * Order 50 on purpose: Core sends each user to the first menu entry they
     * may open, and the portfolio manager must keep landing on the Cartera
     * dashboard (order 10), which also stays the first entry of the menu.
     */
    protected function navigation(NavRegistry $navigation): void
    {
        $navigation->add(
            new NavItem(
                key: 'gerencia.tablero',
                label: 'Tablero gerencial',
                route: 'gerencia.tablero',
                icon: 'chart',
                permission: 'gerencia.view',
                group: 'Gerencia',
                order: 50,
                mobile: true,
            ),
        );
    }
}
