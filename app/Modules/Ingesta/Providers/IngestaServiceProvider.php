<?php

namespace App\Modules\Ingesta\Providers;

use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Ingesta\Infrastructure\EloquentIngestaSink;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

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

    protected function bootModule(): void
    {
        // The robot sends a handful of batches a day; this only caps a
        // runaway loop or a flood of forged requests.
        RateLimiter::for('ingesta-robot', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->ip()));
    }
}
