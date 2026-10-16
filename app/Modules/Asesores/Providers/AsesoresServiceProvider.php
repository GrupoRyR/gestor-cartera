<?php

namespace App\Modules\Asesores\Providers;

use App\Modules\Asesores\Http\Policies\ClientPolicy;
use App\Modules\Asesores\Http\Policies\CommitmentPolicy;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\CommitmentQuery;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Illuminate\Support\Facades\Gate;

/**
 * Asesores: sales advisor viewer: each advisor sees only their own portfolio
 * and payment commitments (docs/prompt-gestor-cartera.md, Part B, "Módulo 3
 * — Visualizador del asesor").
 *
 * The module owns no tables: clients, invoices, commitments and the log are
 * Core's, read and written only through the Core contracts, so the portfolio
 * scope (PortfolioScope) applies to every screen and export.
 *
 * Web routes: /asesores/*, named asesores.*.
 * API routes: /api/asesores/*, named asesores.api.*.
 * Owner: the "Asesores" session (phase 1).
 */
final class AsesoresServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        // The contract data objects are not Eloquent models, so the policies
        // are registered by hand.
        Gate::policy(ClientDetail::class, ClientPolicy::class);
        Gate::policy(Commitment::class, CommitmentPolicy::class);
    }

    /**
     * The first entry (order 10) is the home of advisors and sales managers:
     * Core sends each user to the first entry they may open. The labels are
     * short because the bottom bar of a phone fits four.
     */
    protected function navigation(NavRegistry $navigation): void
    {
        $group = __('asesores::comun.nav.grupo');

        $navigation->add(
            new NavItem(
                key: 'asesores.resumen',
                label: __('asesores::comun.nav.resumen'),
                route: 'asesores.inicio',
                icon: 'dashboard',
                permission: 'asesores.view',
                group: $group,
                order: 10,
                mobile: true,
            ),
            new NavItem(
                key: 'asesores.clientes',
                label: __('asesores::comun.nav.clientes'),
                route: 'asesores.clientes.index',
                icon: 'users',
                permission: 'asesores.view',
                group: $group,
                order: 20,
                mobile: true,
                activePattern: 'asesores.clientes.*',
            ),
            new NavItem(
                key: 'asesores.agenda',
                label: __('asesores::comun.nav.agenda'),
                route: 'asesores.agenda',
                icon: 'calendar',
                permission: 'asesores.view',
                group: $group,
                order: 30,
                mobile: true,
                // Broken commitments are the ones that ask for a call today.
                badge: fn (): ?int => app(CompromisoService::class)
                    ->paginate(new CommitmentQuery(statuses: [CommitmentStatus::Broken]), 1)
                    ->total() ?: null,
            ),
            new NavItem(
                key: 'asesores.historico',
                label: __('asesores::comun.nav.historico'),
                route: 'asesores.historico',
                icon: 'history',
                permission: 'asesores.view',
                group: $group,
                order: 40,
            ),
        );
    }
}
