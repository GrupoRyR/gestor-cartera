<?php

namespace App\Modules\Asesores\Providers;

use App\Support\Modules\ModuleServiceProvider;

/**
 * Asesores: sales advisor viewer: each advisor sees only their own portfolio
 * and payment commitments (docs/prompt-gestor-cartera.md, Part B, "Módulo 3
 * — Visualizador del asesor").
 *
 * Web routes: /asesores/*, named asesores.*.
 * API routes: /api/asesores/*, named asesores.api.*.
 * Owner: the "Asesores" session (phase 1).
 */
final class AsesoresServiceProvider extends ModuleServiceProvider
{
    //
}
