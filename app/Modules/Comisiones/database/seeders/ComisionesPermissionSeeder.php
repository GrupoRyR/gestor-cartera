<?php

namespace App\Modules\Comisiones\Database\Seeders;

use App\Support\Modules\PermissionSeeder;

/**
 * Permissions of the Comisiones module. DatabaseSeeder runs it on every seed.
 */
final class ComisionesPermissionSeeder extends PermissionSeeder
{
    /**
     * Permission name ("comisiones.<action>") => roles that receive it, e.g.:
     *
     *     'comisiones.view' => ['gerente_cartera', 'analista_cartera'],
     *
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        return [];
    }
}
