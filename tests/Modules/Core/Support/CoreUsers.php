<?php

namespace Tests\Modules\Core\Support;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Saved users with a Spatie role and their company assignments.
 */
final class CoreUsers
{
    /**
     * An active user with $role (none when null), assigned to $companies
     * through company_user.
     */
    public static function create(?Role $role, CompanyCode ...$companies): User
    {
        $user = User::factory()->create();

        if ($role !== null) {
            $user->assignRole(SpatieRole::findOrCreate($role, 'web'));
        }

        foreach ($companies as $company) {
            $user->companies()->attach($company->value);
        }

        return $user;
    }
}
