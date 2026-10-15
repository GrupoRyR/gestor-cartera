<?php

namespace App\Modules\Core\Database\Seeders;

use App\Support\Modules\PermissionSeeder;

/**
 * Permissions of the Core module. DatabaseSeeder runs it on every seed.
 */
final class CorePermissionSeeder extends PermissionSeeder
{
    /**
     * Permission name ("core.<action>") => roles that receive it, e.g.:
     *
     *     'core.view' => ['gerente_cartera', 'analista_cartera'],
     *
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        return [];
    }
}
