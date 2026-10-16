<?php

namespace App\Modules\Asesores\Providers;

use App\Modules\Asesores\Http\Policies\ClientPolicy;
use App\Modules\Asesores\Http\Policies\CommitmentPolicy;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Support\Modules\ModuleServiceProvider;
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
}
