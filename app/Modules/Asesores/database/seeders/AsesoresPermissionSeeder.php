<?php

namespace App\Modules\Asesores\Database\Seeders;

use App\Support\Modules\PermissionSeeder;

/**
 * Permissions of the Asesores module. DatabaseSeeder runs it on every seed.
 */
final class AsesoresPermissionSeeder extends PermissionSeeder
{
    /**
     * Permission name ("asesores.<action>") => roles that receive it, e.g.:
     *
     *     'asesores.view' => ['gerente_cartera', 'analista_cartera'],
     *
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        return [];
    }
}
