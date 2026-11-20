<?php

namespace App\Modules\Soportes\Database\Seeders;

use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Modules\PermissionSeeder;

/**
 * Permissions of the Soportes module. DatabaseSeeder runs it on every seed;
 * running it again changes nothing.
 */
final class SoportesPermissionSeeder extends PermissionSeeder
{
    /**
     * soportes.view opens the receipts inbox: reviewers see the receipts of
     * their companies, everyone else only the ones they uploaded or of their
     * own clients (see ReceiptPolicy).
     *
     * soportes.upload uploads a receipt: the advisor for their own clients,
     * the portfolio team for any client of their companies.
     *
     * soportes.review marks a receipt applied or rejected, after the analyst
     * applied (or could not apply) the payment in SAP. Not Treasury.
     *
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        $uploaders = [Role::SalesAdvisor, Role::PortfolioAnalyst, Role::PortfolioManager];

        return [
            'soportes.view' => $uploaders,
            'soportes.upload' => $uploaders,
            'soportes.review' => [Role::PortfolioAnalyst, Role::PortfolioManager],
        ];
    }

    /**
     * The superadmin sees the inbox but neither uploads nor reviews: whoever
     * collects uploads, and whoever applies the payment in SAP reviews
     * (separation of duties).
     *
     * @return list<string>
     */
    protected function superAdminExcludes(): array
    {
        return ['soportes.upload', 'soportes.review'];
    }
}
