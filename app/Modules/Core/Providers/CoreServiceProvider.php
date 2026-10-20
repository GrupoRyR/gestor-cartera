<?php

namespace App\Modules\Core\Providers;

use App\Models\User;
use App\Modules\Core\Application\Auth\IdentityProvider;
use App\Modules\Core\Application\Commitments\BreakOverdueCommitments;
use App\Modules\Core\Console\BreakOverdueCommitmentsCommand;
use App\Modules\Core\Console\Migracion\MigrateUsersCommand;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\ClientStatusService;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Core\Contracts\GestionLogger;
use App\Modules\Core\Http\Middleware\EnsureUserIsActive;
use App\Modules\Core\Infrastructure\Activity\DatabaseGestionLogger;
use App\Modules\Core\Infrastructure\Audit\DatabaseAuditLogger;
use App\Modules\Core\Infrastructure\Auth\SocialiteMicrosoftIdentityProvider;
use App\Modules\Core\Infrastructure\Commitments\EloquentCompromisoService;
use App\Modules\Core\Infrastructure\Companies\CompanyCatalog;
use App\Modules\Core\Infrastructure\Companies\SessionCurrentCompany;
use App\Modules\Core\Infrastructure\Ingest\ImportAdvisors;
use App\Modules\Core\Infrastructure\Ingest\ImportReceivablesSnapshot;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use App\Modules\Core\Infrastructure\Portfolio\EloquentCarteraRepository;
use App\Modules\Core\Infrastructure\Portfolio\EloquentClientStatusService;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Support\Facades\Event;

/**
 * Core: shared kernel: Microsoft sign-in, roles and permissions, active
 * company, the "Equipo" and "Parámetros" screens, shared entities, client
 * states and the audit trail (docs/prompt-gestor-cartera.md, Part B, "Módulo 0N — Núcleo").
 *
 * Web routes: / (site root), named core.*.
 * API routes: /api/core/*, named core.api.*.
 * Owner: the "Núcleo" session (phase 0b). The contracts in Contracts/ are
 * frozen after phase 0a; changing one takes a separate, coordinated PR.
 */
final class CoreServiceProvider extends ModuleServiceProvider
{
    /**
     * Core serves the site root: /, /login, /equipo and /empresas.
     */
    protected function webPrefix(): string
    {
        return '';
    }

    protected function registerModule(): void
    {
        // One read of the companies per request (or job), shared by every
        // caller; refresh() forgets it after an admin edits a company.
        $this->app->scoped(CompanyCatalog::class);
        $this->app->scoped(CurrentCompany::class, SessionCurrentCompany::class);

        // Reads the signed-in user and the request, so one per request (or job).
        $this->app->scoped(AuditLogger::class, DatabaseAuditLogger::class);

        // Follows CurrentCompany, so one per request (or job) as well.
        $this->app->scoped(CarteraRepository::class, EloquentCarteraRepository::class);
        $this->app->scoped(GestionLogger::class, DatabaseGestionLogger::class);
        $this->app->scoped(ClientStatusService::class, EloquentClientStatusService::class);
        $this->app->scoped(CompromisoService::class, EloquentCompromisoService::class);

        // Microsoft Entra ID sign-in; tests bind a fake instead.
        $this->app->bind(IdentityProvider::class, SocialiteMicrosoftIdentityProvider::class);
    }

    protected function bootModule(): void
    {
        // $user->companies(): the companies a user is assigned to.
        User::resolveRelationUsing('companies', CompanyRecord::assignedTo(...));

        // $user->advisors(): the SAP sales employees linked to a user.
        User::resolveRelationUsing('advisors', AdvisorRecord::linkedTo(...));

        // $user->teamAdvisors(): the advisors in a sales manager's team.
        User::resolveRelationUsing('teamAdvisors', AdvisorRecord::teamOf(...));

        // The robot batches Core consumes: cartera snapshots and SAP advisors.
        // Both listeners are queued, and only for their own dataset.
        Event::listen(BatchAccepted::class, ImportReceivablesSnapshot::class);
        Event::listen(BatchAccepted::class, ImportAdvisors::class);

        $this->commands([BreakOverdueCommitmentsCommand::class, MigrateUsersCommand::class]);

        // Every web page signs out a deactivated user, with or without auth.
        // Appended through the HTTP kernel, not only the router: resolving the
        // kernel after boot (as tests do) syncs its groups into the router and
        // would drop a router-only push.
        $this->callAfterResolving(HttpKernel::class, function (HttpKernel $kernel): void {
            if ($kernel instanceof FoundationHttpKernel) {
                $kernel->appendMiddlewareToGroup('web', EnsureUserIsActive::class);
            }
        });
    }

    /**
     * Pending commitments due before today become broken shortly after
     * midnight in Bogotá. The run takes seconds, so a lock left by a killed
     * process expires after 10 minutes instead of the default day.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command(BreakOverdueCommitmentsCommand::class)
            ->dailyAt('00:15')
            ->timezone(BreakOverdueCommitments::TIMEZONE)
            ->withoutOverlapping(10);
    }

    /**
     * Equipo is the first entry admins see, so it is also their home (see
     * HomeController); Parámetros follows it. Orders 900 and up keep
     * administration after the screens of the other modules for anyone who
     * sees both.
     */
    protected function navigation(NavRegistry $navigation): void
    {
        $navigation->add(
            new NavItem(
                key: 'core.equipo',
                label: 'Equipo',
                route: 'core.equipo.index',
                icon: 'users',
                permission: 'core.team.manage',
                group: 'Administración',
                order: 900,
                activePattern: 'core.equipo.*',
            ),
            new NavItem(
                key: 'core.empresas',
                label: 'Parámetros',
                route: 'core.empresas.index',
                icon: 'sliders',
                permission: 'core.companies.manage',
                group: 'Administración',
                order: 910,
                activePattern: 'core.empresas.*',
            ),
        );
    }
}
