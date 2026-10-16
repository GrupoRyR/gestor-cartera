<?php

namespace App\Modules\Ingesta\Providers;

use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Ingesta\Infrastructure\EloquentIngestaSink;
use App\Support\Modules\ModuleServiceProvider;

/**
 * Ingesta: signed endpoint for the ETL robot batches, load history, manual
 * Excel upload and the missing-load alert (docs/prompt-gestor-cartera.md,
 * Part B, "Módulo 1 — Ingesta").
 *
 * Web routes: /ingesta/*, named ingesta.*.
 * API routes: /api/ingesta/*, named ingesta.api.*.
 * Owner: the "Ingesta" session (phase 1).
 */
final class IngestaServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(IngestaSink::class, EloquentIngestaSink::class);
    }
}
