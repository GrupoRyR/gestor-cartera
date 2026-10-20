<?php

namespace App\Modules\Cobro\Database\Seeders;

use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Modules\PermissionSeeder;

/**
 * Permissions of the Cobro module. DatabaseSeeder runs it on every seed.
 */
final class CobroPermissionSeeder extends PermissionSeeder
{
    /**
     * - cobro.view: the weekly collection and circularization previews and
     *   the delivery reports.
     * - cobro.manage: the exclusion list, the mail templates and the test
     *   sends.
     * - cobro.replies: the client replies inbox (read and mark messages).
     *
     * @return array<string, list<string|\BackedEnum>>
     */
    public function permissions(): array
    {
        return [
            'cobro.view' => [Role::Admin, Role::PortfolioManager, Role::PortfolioAnalyst],
            'cobro.manage' => [Role::PortfolioManager, Role::PortfolioAnalyst],
            'cobro.replies' => [Role::PortfolioManager, Role::PortfolioAnalyst],
        ];
    }
}
