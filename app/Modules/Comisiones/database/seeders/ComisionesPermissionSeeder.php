<?php

namespace App\Modules\Comisiones\Database\Seeders;

use App\Modules\Comisiones\Infrastructure\Permissions\ModuleRoles;
use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Modules\PermissionSeeder;

/**
 * Permissions of the Comisiones module. DatabaseSeeder runs it on every seed.
 * Segregation of duties (§10): whoever calculates or captures never holds
 * comisiones.approve.
 */
final class ComisionesPermissionSeeder extends PermissionSeeder
{
    /**
     * - comisiones.view: dashboard, settlements, consolidated report, controls.
     * - comisiones.calculate: calculate, recalculate and complementary settlements.
     * - comisiones.capture: budget, OBR, adjustments, manual crossings, reassignments.
     * - comisiones.approve: approve settlements and captures.
     * - comisiones.close: close a period.
     * - comisiones.parameters: add parameter versions.
     * - comisiones.reports: collection PDFs, ZIP and payroll file.
     * - comisiones.audit: the audit trail.
     *
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        return [
            'comisiones.view' => [Role::PortfolioManager, ModuleRoles::APPROVER, ModuleRoles::VIEWER],
            'comisiones.calculate' => [Role::PortfolioManager],
            'comisiones.capture' => [Role::PortfolioManager],
            'comisiones.approve' => [ModuleRoles::APPROVER],
            'comisiones.close' => [Role::PortfolioManager, ModuleRoles::APPROVER],
            'comisiones.parameters' => [Role::Admin, Role::PortfolioManager],
            'comisiones.reports' => [Role::PortfolioManager, ModuleRoles::APPROVER],
            'comisiones.audit' => [Role::Admin, Role::PortfolioManager, ModuleRoles::APPROVER],
        ];
    }
}
