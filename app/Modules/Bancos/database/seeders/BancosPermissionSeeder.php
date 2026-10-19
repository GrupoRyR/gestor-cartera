<?php

namespace App\Modules\Bancos\Database\Seeders;

use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Modules\PermissionSeeder;

/**
 * Permissions of the Bancos module. DatabaseSeeder runs it on every seed.
 */
final class BancosPermissionSeeder extends PermissionSeeder
{
    /**
     * bancos.view opens the movements, the collections report and the load
     * history; bancos.import uploads a statement; bancos.apply registers the
     * cash receipt number of a movement (the analyst applies the payment in
     * SAP). Treasury delivers the statements, so it sees and loads them but
     * does not apply payments.
     *
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        return [
            'bancos.view' => [Role::PortfolioManager, Role::PortfolioAnalyst, Role::Treasury],
            'bancos.import' => [Role::PortfolioManager, Role::PortfolioAnalyst, Role::Treasury],
            'bancos.apply' => [Role::PortfolioManager, Role::PortfolioAnalyst],
        ];
    }
}
