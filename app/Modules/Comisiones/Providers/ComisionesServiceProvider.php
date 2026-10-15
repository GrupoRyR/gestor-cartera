<?php

namespace App\Modules\Comisiones\Providers;

use App\Support\Modules\ModuleServiceProvider;

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
    //
}
