<?php

namespace App\Modules\Cobro\Providers;

use App\Modules\Cobro\Infrastructure\Mail\GraphMailSender;
use App\Modules\Core\Contracts\MailSender;
use App\Support\Modules\ModuleServiceProvider;

/**
 * Cobro: collection email through Microsoft Graph: weekly overdue notices,
 * the monthly statement engine, exclusion list, delivery report and client
 * replies (docs/prompt-gestor-cartera.md, Part B, "Módulo 4 — Correo y
 * cobro automático").
 *
 * Web routes: /cobro/*, named cobro.*.
 * API routes: /api/cobro/*, named cobro.api.*.
 * Owner: the "Cobro" session (phase 1); the "Circularización" session (phase
 * 2) owns Circularizacion/.
 */
final class CobroServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(MailSender::class, GraphMailSender::class);
    }
}
