<?php

namespace App\Modules\Bancos\Providers;

use App\Support\Modules\ModuleServiceProvider;

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
    /**
     * Treasury reads the daily collections from GET /api/recaudo (Part B,
     * "Módulo 5", point 9), so the module API sits directly under /api.
     */
    protected function apiPrefix(): string
    {
        return '';
    }
}
