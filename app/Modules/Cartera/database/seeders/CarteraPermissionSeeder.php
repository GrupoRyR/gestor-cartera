<?php

namespace App\Modules\Cartera\Database\Seeders;

use App\Support\Modules\PermissionSeeder;

/**
 * Permissions of the Cartera module. DatabaseSeeder runs it on every seed.
 */
final class CarteraPermissionSeeder extends PermissionSeeder
{
    /**
     * Permission name ("cartera.<action>") => roles that receive it, e.g.:
     *
     *     'cartera.view' => ['gerente_cartera', 'analista_cartera'],
     *
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        return [];
    }
}
