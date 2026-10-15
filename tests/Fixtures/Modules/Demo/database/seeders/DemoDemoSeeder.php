<?php

namespace Tests\Fixtures\Modules\Demo\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Local demo data of the fixture module ("<Module>DemoSeeder" convention).
 */
final class DemoDemoSeeder extends Seeder
{
    public function run(): void
    {
        Role::findOrCreate('demo_local', 'web');
    }
}
