<?php

namespace App\Modules\Core\Providers;

use App\Support\Modules\ModuleServiceProvider;

/**
 * Core: shared kernel: Microsoft sign-in, roles and permissions, active
 * company, the "Equipo" screen, shared entities, client states and the audit
 * trail (docs/prompt-gestor-cartera.md, Part B, "Módulo 0N — Núcleo").
 *
 * Web routes: / (site root), named core.*.
 * API routes: /api/core/*, named core.api.*.
 * Owner: the "Núcleo" session (phase 0b). The contracts in Contracts/ are
 * frozen after phase 0a; changing one takes a separate, coordinated PR.
 */
final class CoreServiceProvider extends ModuleServiceProvider
{
    /**
     * Core serves the site root: /, /login and /equipo.
     */
    protected function webPrefix(): string
    {
        return '';
    }
}
