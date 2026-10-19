<?php

namespace App\Modules\Ingesta\Database\Seeders;

use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Modules\PermissionSeeder;

/**
 * Permissions of the Ingesta module. DatabaseSeeder runs it on every seed.
 */
final class IngestaPermissionSeeder extends PermissionSeeder
{
    /**
     * ingesta.view opens the load history; ingesta.upload, the manual
     * upload of the cartera Excel.
     *
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        return [
            'ingesta.view' => [Role::Admin, Role::PortfolioManager, Role::PortfolioAnalyst],
            'ingesta.upload' => [Role::Admin, Role::PortfolioManager, Role::PortfolioAnalyst],
        ];
    }
}
