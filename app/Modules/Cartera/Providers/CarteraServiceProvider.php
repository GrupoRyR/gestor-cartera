<?php

namespace App\Modules\Cartera\Providers;

use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;

/**
 * Cartera: receivables manager: dashboard, clients, follow-ups and payment
 * commitments (docs/prompt-gestor-cartera.md, Part B, "Módulo 2 — Gestor
 * de cartera").
 *
 * Web routes: /cartera/*, named cartera.*.
 * API routes: /api/cartera/*, named cartera.api.*.
 * Owner: the "Cartera" session (phase 1).
 */
final class CarteraServiceProvider extends ModuleServiceProvider
{
    /**
     * The dashboard comes first (order 10): it is the home of the portfolio
     * manager and the analyst, because Core sends each user to the first menu
     * entry they may open.
     */
    protected function navigation(NavRegistry $navigation): void
    {
        $navigation->add(
            new NavItem(
                key: 'cartera.tablero',
                label: 'Tablero',
                route: 'cartera.tablero',
                icon: 'dashboard',
                permission: 'cartera.view',
                group: 'Cartera',
                order: 10,
                mobile: true,
            ),
        );
    }
}
