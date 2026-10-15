<?php

namespace App\Modules\Gerencia\Providers;

use App\Support\Modules\ModuleServiceProvider;

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
    //
}
