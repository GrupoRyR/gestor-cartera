<?php

namespace App\Modules\Gerencia\Database\Seeders;

use App\Support\Modules\PermissionSeeder;

/**
 * Permissions of the Gerencia module. DatabaseSeeder runs it on every seed.
 */
final class GerenciaPermissionSeeder extends PermissionSeeder
{
    /**
     * Permission name ("gerencia.<action>") => roles that receive it, e.g.:
     *
     *     'gerencia.view' => ['gerente_cartera', 'analista_cartera'],
     *
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        return [];
    }
}
