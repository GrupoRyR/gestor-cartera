<?php

namespace App\Modules\Cartera\Providers;

use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

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
    protected function bootModule(): void
    {
        // The Excel downloads (audit S-10): 10 a minute per user, counted
        // together, so a script cannot pull the whole portfolio in a loop and
        // one user at the limit never blocks another.
        RateLimiter::for('cartera-exports', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(10)->by($user === null ? 'ip:'.$request->ip() : 'user:'.$user->getAuthIdentifier());
        });
    }

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
            new NavItem(
                key: 'cartera.kanban',
                label: 'Kanban',
                route: 'cartera.kanban',
                icon: 'board',
                permission: 'cartera.view',
                group: 'Cartera',
                order: 20,
                mobile: true,
            ),
            new NavItem(
                key: 'cartera.clientes',
                label: 'Clientes',
                route: 'cartera.clientes.index',
                icon: 'users',
                permission: 'cartera.view',
                group: 'Cartera',
                order: 30,
                mobile: true,
                activePattern: 'cartera.clientes.*',
            ),
            new NavItem(
                key: 'cartera.compromisos',
                label: 'Compromisos',
                route: 'cartera.compromisos.index',
                icon: 'calendar-check',
                permission: 'cartera.view',
                group: 'Cartera',
                order: 40,
                mobile: true,
                activePattern: 'cartera.compromisos.*',
            ),
        );
    }
}
