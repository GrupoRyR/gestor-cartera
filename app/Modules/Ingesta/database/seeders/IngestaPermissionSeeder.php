<?php

namespace App\Modules\Ingesta\Database\Seeders;

use App\Support\Modules\PermissionSeeder;

/**
 * Permissions of the Ingesta module. DatabaseSeeder runs it on every seed.
 */
final class IngestaPermissionSeeder extends PermissionSeeder
{
    /**
     * Permission name ("ingesta.<action>") => roles that receive it, e.g.:
     *
     *     'ingesta.view' => ['gerente_cartera', 'analista_cartera'],
     *
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        return [];
    }
}
