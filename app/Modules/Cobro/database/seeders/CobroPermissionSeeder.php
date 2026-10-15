<?php

namespace App\Modules\Cobro\Database\Seeders;

use App\Support\Modules\PermissionSeeder;

/**
 * Permissions of the Cobro module. DatabaseSeeder runs it on every seed.
 */
final class CobroPermissionSeeder extends PermissionSeeder
{
    /**
     * Permission name ("cobro.<action>") => roles that receive it, e.g.:
     *
     *     'cobro.view' => ['gerente_cartera', 'analista_cartera'],
     *
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        return [];
    }
}
