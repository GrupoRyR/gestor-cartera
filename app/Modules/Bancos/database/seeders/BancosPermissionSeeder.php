<?php

namespace App\Modules\Bancos\Database\Seeders;

use App\Support\Modules\PermissionSeeder;

/**
 * Permissions of the Bancos module. DatabaseSeeder runs it on every seed.
 */
final class BancosPermissionSeeder extends PermissionSeeder
{
    /**
     * Permission name ("bancos.<action>") => roles that receive it, e.g.:
     *
     *     'bancos.view' => ['gerente_cartera', 'analista_cartera'],
     *
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        return [];
    }
}
