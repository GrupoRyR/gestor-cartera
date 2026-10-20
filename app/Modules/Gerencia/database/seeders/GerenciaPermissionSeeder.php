<?php

namespace App\Modules\Gerencia\Database\Seeders;

use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Modules\PermissionSeeder;

/**
 * Permissions of the Gerencia module. DatabaseSeeder runs it on every seed;
 * running it again changes nothing.
 */
final class GerenciaPermissionSeeder extends PermissionSeeder
{
    /**
     * gerencia.view: the management dashboard. Only the portfolio manager
     * (Miguel); the analyst works from the Cartera dashboard instead.
     *
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        return [
            'gerencia.view' => [Role::PortfolioManager],
        ];
    }
}
