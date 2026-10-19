<?php

namespace App\Modules\Cobro\Providers;

use App\Modules\Cobro\Console\SyncMailboxesCommand;
use App\Modules\Cobro\Infrastructure\Mail\DatabaseMailboxReader;
use App\Modules\Cobro\Infrastructure\Mail\GraphMailSender;
use App\Modules\Core\Contracts\MailboxReader;
use App\Modules\Core\Contracts\MailSender;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Illuminate\Console\Scheduling\Schedule;

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
    public const string TIMEZONE = 'America/Bogota';

    protected function registerModule(): void
    {
        $this->app->singleton(MailSender::class, GraphMailSender::class);
        $this->app->singleton(MailboxReader::class, DatabaseMailboxReader::class);
    }

    protected function bootModule(): void
    {
        $this->commands([SyncMailboxesCommand::class]);
    }

    /**
     * Every five minutes, the new replies and bounces of the company
     * inboxes. A run takes seconds; a lock left by a killed process
     * expires after 10 minutes.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command(SyncMailboxesCommand::class)
            ->everyFiveMinutes()
            ->timezone(self::TIMEZONE)
            ->withoutOverlapping(10);
    }

    /**
     * The Cobro group of the menu, after Cartera (its entries use 10 to 40).
     */
    protected function navigation(NavRegistry $navigation): void
    {
        $navigation->add(
            new NavItem(
                key: 'cobro.exclusiones',
                label: 'Lista de exclusión',
                route: 'cobro.exclusiones.index',
                icon: 'sliders',
                permission: 'cobro.manage',
                group: 'Cobro',
                order: 140,
                activePattern: 'cobro.exclusiones.*',
            ),
            new NavItem(
                key: 'cobro.plantillas',
                label: 'Plantilla de correo',
                route: 'cobro.plantillas.edit',
                icon: 'edit',
                permission: 'cobro.manage',
                group: 'Cobro',
                order: 150,
                activePattern: 'cobro.plantillas.*',
            ),
        );
    }
}
