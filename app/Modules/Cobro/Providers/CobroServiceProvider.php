<?php

namespace App\Modules\Cobro\Providers;

use App\Modules\Cobro\Circularizacion\CircularizationRun;
use App\Modules\Cobro\Circularizacion\Console\SendCircularizationCommand;
use App\Modules\Cobro\Console\ExpireStaleSendsCommand;
use App\Modules\Cobro\Console\SendWeeklyCollectionCommand;
use App\Modules\Cobro\Console\SyncMailboxesCommand;
use App\Modules\Cobro\Infrastructure\Mail\BounceLinked;
use App\Modules\Cobro\Infrastructure\Mail\DatabaseMailboxReader;
use App\Modules\Cobro\Infrastructure\Mail\GraphMailSender;
use App\Modules\Cobro\Infrastructure\Runs\MarkItemBounced;
use App\Modules\Cobro\Infrastructure\Runs\RunDefinitions;
use App\Modules\Cobro\Infrastructure\Runs\WeeklyCollection;
use App\Modules\Core\Contracts\MailboxReader;
use App\Modules\Core\Contracts\MailSender;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

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

        $this->app->singleton(RunDefinitions::class, fn (): RunDefinitions => new RunDefinitions([
            $this->app->make(WeeklyCollection::class),
            $this->app->make(CircularizationRun::class),
        ]));
    }

    protected function bootModule(): void
    {
        $this->commands([
            SyncMailboxesCommand::class,
            SendWeeklyCollectionCommand::class,
            SendCircularizationCommand::class,
            ExpireStaleSendsCommand::class,
        ]);

        // Exchange Online accepts 30 mails a minute per mailbox; the send
        // jobs stay below it across both mailboxes.
        RateLimiter::for('cobro-mail', fn (): Limit => Limit::perMinute(max(1, (int) config('cobro.send_per_minute', 25)))->by('cobro-mail'));

        Event::listen(BounceLinked::class, MarkItemBounced::class);
    }

    /**
     * - Every five minutes, the new replies and bounces of the company
     *   inboxes (a run takes seconds; a lock left by a killed process
     *   expires after 10 minutes).
     * - Wednesdays at 17:00 (Bogotá), the weekly collection: the command
     *   only plans the run and queues one job per client, so it takes
     *   seconds; the queue worker sends them.
     * - Monthly at close, the circularization; while its format is a draft
     *   the command sends nothing.
     * - Every ten minutes, close the sends a killed worker left halfway.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command(ExpireStaleSendsCommand::class)
            ->everyTenMinutes()
            ->timezone(self::TIMEZONE)
            ->withoutOverlapping(10);

        $schedule->command(SendCircularizationCommand::class)
            ->monthlyOn((int) config('cobro.circularizacion.day', 1), (string) config('cobro.circularizacion.time', '09:00'))
            ->timezone(self::TIMEZONE)
            ->withoutOverlapping(30);

        $schedule->command(SyncMailboxesCommand::class)
            ->everyFiveMinutes()
            ->timezone(self::TIMEZONE)
            ->withoutOverlapping(10);

        $schedule->command(SendWeeklyCollectionCommand::class)
            ->weeklyOn((int) config('cobro.weekly.day', 3), (string) config('cobro.weekly.time', '17:00'))
            ->timezone(self::TIMEZONE)
            ->withoutOverlapping(30);
    }

    /**
     * The Cobro group of the menu, after Cartera (its entries use 10 to 40).
     */
    protected function navigation(NavRegistry $navigation): void
    {
        $navigation->add(
            new NavItem(
                key: 'cobro.semanal',
                label: 'Cobro semanal',
                route: 'cobro.semanal.show',
                icon: 'send',
                permission: 'cobro.view',
                group: 'Cobro',
                order: 110,
                activePattern: 'cobro.semanal.*',
            ),
            new NavItem(
                key: 'cobro.respuestas',
                label: 'Respuestas de clientes',
                route: 'cobro.respuestas.index',
                icon: 'inbox',
                permission: 'cobro.replies',
                group: 'Cobro',
                order: 120,
                activePattern: 'cobro.respuestas.*',
                mobile: true,
                badge: fn (): ?int => $this->app->make(MailboxReader::class)->unreadCount() ?: null,
            ),
            new NavItem(
                key: 'cobro.lotes',
                label: 'Informes de envío',
                route: 'cobro.lotes.index',
                icon: 'history',
                permission: 'cobro.view',
                group: 'Cobro',
                order: 130,
                activePattern: 'cobro.lotes.*',
            ),
            new NavItem(
                key: 'cobro.circularizacion',
                label: 'Circularización',
                route: 'cobro.circularizacion.show',
                icon: 'document',
                permission: 'cobro.view',
                group: 'Cobro',
                order: 135,
                activePattern: 'cobro.circularizacion.*',
            ),
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
            new NavItem(
                key: 'cobro.copias',
                label: 'Copias del correo',
                route: 'cobro.copias.edit',
                icon: 'users',
                permission: 'cobro.manage',
                group: 'Cobro',
                order: 155,
                activePattern: 'cobro.copias.*',
            ),
        );
    }
}
