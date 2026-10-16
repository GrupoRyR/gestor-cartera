<?php

namespace App\Modules\Core\Providers;

use App\Models\User;
use App\Modules\Core\Application\Auth\IdentityProvider;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Http\Middleware\EnsureUserIsActive;
use App\Modules\Core\Infrastructure\Audit\DatabaseAuditLogger;
use App\Modules\Core\Infrastructure\Auth\SocialiteMicrosoftIdentityProvider;
use App\Modules\Core\Infrastructure\Companies\CompanyCatalog;
use App\Modules\Core\Infrastructure\Companies\SessionCurrentCompany;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;

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

    protected function registerModule(): void
    {
        // One read of the companies per request (or job), shared by every
        // caller; refresh() forgets it after an admin edits a company.
        $this->app->scoped(CompanyCatalog::class);
        $this->app->scoped(CurrentCompany::class, SessionCurrentCompany::class);

        // Reads the signed-in user and the request, so one per request (or job).
        $this->app->scoped(AuditLogger::class, DatabaseAuditLogger::class);

        // Microsoft Entra ID sign-in; tests bind a fake instead.
        $this->app->bind(IdentityProvider::class, SocialiteMicrosoftIdentityProvider::class);
    }

    protected function bootModule(): void
    {
        // $user->companies(): the companies a user is assigned to.
        User::resolveRelationUsing('companies', CompanyRecord::assignedTo(...));

        // $user->advisors(): the SAP sales employees linked to a user.
        User::resolveRelationUsing('advisors', AdvisorRecord::linkedTo(...));

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
}
