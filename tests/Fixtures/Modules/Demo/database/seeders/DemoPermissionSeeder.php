<?php

namespace Tests\Fixtures\Modules\Demo\Database\Seeders;

use App\Support\Modules\PermissionSeeder;
use Tests\Fixtures\Modules\Demo\DemoRole;

final class DemoPermissionSeeder extends PermissionSeeder
{
    public function permissions(): array
    {
        return [
            'demo.view' => ['analista_cartera', DemoRole::Manager],
            'demo.manage' => [DemoRole::Manager],
        ];
    }
}
